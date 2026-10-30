<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OperationalCatalog
{
    public const PART_UNITS = [
        'Unidad', 'Pieza', 'Par', 'Juego', 'Kit', 'Metro', 'Metro cuadrado',
        'Metro cubico', 'Centimetro', 'Litro', 'Mililitro', 'Galon',
        'Kilogramo', 'Gramo', 'Rollo', 'Lamina', 'Bolsa', 'Caja',
    ];

    public const DEFINITIONS = [
        'navieras' => ['table' => 'shipping_lines', 'title' => 'Navieras', 'singular' => 'naviera', 'icon' => 'ship'],
        'auditoras' => ['table' => 'external_audit_companies', 'title' => 'Auditoras externas', 'singular' => 'auditora', 'icon' => 'shield-check'],
        'iso' => ['table' => 'container_iso_codes', 'title' => 'ISO Code', 'singular' => 'codigo ISO', 'icon' => 'container'],
        'repuestos' => ['table' => 'repair_parts', 'title' => 'Repuestos', 'singular' => 'repuesto', 'icon' => 'package'],
        'reparaciones' => ['table' => 'repair_services', 'title' => 'Reparaciones', 'singular' => 'reparacion', 'icon' => 'wrench'],
    ];

    public static function definition(string $catalog): array
    {
        abort_unless(isset(self::DEFINITIONS[$catalog]), 404);

        return self::DEFINITIONS[$catalog];
    }

    public function snapshot(string $catalog, int $id): array
    {
        $record = DB::table(self::definition($catalog)['table'])->where('id', $id)->first();
        abort_unless($record, 404);
        $data = (array) $record;
        if ($catalog === 'navieras') {
            $data['branches'] = DB::table('shipping_line_branch')->where('shipping_line_id', $id)->orderBy('branch_id')->pluck('branch_id')->all();
            $data['auditors'] = DB::table('shipping_line_auditor')->where('shipping_line_id', $id)->orderBy('auditor_id')->pluck('auditor_id')->all();
            $data['auditor_assignments'] = DB::table('shipping_line_branch_auditor')->where('shipping_line_id', $id)
                ->orderBy('branch_id')->orderBy('auditor_id')->get()->groupBy('branch_id')
                ->map(fn (Collection $items): array => $items->pluck('auditor_id')->all())->all();
        }

        return $data;
    }

    public function save(string $catalog, array $data, int $userId, ?int $id = null): int
    {
        return DB::transaction(function () use ($catalog, $data, $userId, $id): int {
            $table = self::definition($catalog)['table'];
            $before = null;
            if ($id) {
                abort_unless(DB::table($table)->where('id', $id)->lockForUpdate()->first(), 404);
                $before = $this->snapshot($catalog, $id);
                if ($catalog === 'repuestos' && (int) $before['status'] !== 1) {
                    throw ValidationException::withMessages(['status' => 'Reactiva el repuesto antes de editarlo.']);
                }
                if ($catalog === 'reparaciones' && (int) $before['status'] !== 1) {
                    throw ValidationException::withMessages(['status' => 'Reactiva la reparacion antes de editarla.']);
                }
            }
            $branches = $data['branches'] ?? [];
            $auditors = $data['auditors'] ?? [];
            $assignments = $data['auditor_assignments'] ?? [];
            unset($data['branches'], $data['auditors'], $data['auditor_assignments']);
            if ($catalog === 'navieras') {
                $this->validateLinks('branches', 'sucursales', 'estado_id', $branches, $before['branches'] ?? []);
                $this->validateAuditorAssignments($branches, $assignments, $before['auditor_assignments'] ?? []);
                if (array_diff(array_map('intval', $auditors), array_map('intval', $before['auditors'] ?? []))) {
                    throw ValidationException::withMessages(['auditors' => 'Selecciona las auditoras dentro de su sucursal, no como autorizacion general.']);
                }
                $assignedIds = array_merge([], ...array_values($assignments));
                $auditors = array_values(array_diff(array_map('intval', $auditors), array_map('intval', $assignedIds)));
            }
            $data['updated_at'] = now();
            if ($catalog === 'reparaciones') {
                $data['unit'] = 'Servicio';
            }
            if (in_array($catalog, ['repuestos', 'reparaciones'], true)) {
                $data['code'] = $before['code'] ?? (string) Str::ulid();
            }
            if ($id) {
                DB::table($table)->where('id', $id)->update($data);
            } else {
                $id = DB::table($table)->insertGetId($data + ['status' => 1, 'created_at' => now()]);
                if (in_array($catalog, ['repuestos', 'reparaciones'], true)) {
                    DB::table($table)->where('id', $id)->update(['code' => $this->itemCode($catalog, $data['name'], $id)]);
                }
            }
            if ($catalog === 'navieras') {
                DB::table('shipping_line_branch_auditor')->where('shipping_line_id', $id)->delete();
                foreach ([['shipping_line_branch', 'branch_id', $branches], ['shipping_line_auditor', 'auditor_id', $auditors]] as [$pivot, $column, $ids]) {
                    DB::table($pivot)->where('shipping_line_id', $id)->delete();
                    foreach ($ids as $linkedId) {
                        DB::table($pivot)->insert(['shipping_line_id' => $id, $column => $linkedId]);
                    }
                }
                foreach ($assignments as $branchId => $auditorIds) {
                    foreach ($auditorIds as $auditorId) {
                        DB::table('shipping_line_branch_auditor')->insert(['shipping_line_id' => $id, 'branch_id' => $branchId, 'auditor_id' => $auditorId]);
                    }
                }
            }
            $this->event($catalog, $id, $userId, $before ? 'Actualizacion' : 'Creacion', $before);

            return $id;
        }, 3);
    }

    public function setStatus(string $catalog, int $id, int $status, int $userId): void
    {
        DB::transaction(function () use ($catalog, $id, $status, $userId): void {
            $table = self::definition($catalog)['table'];
            abort_unless(DB::table($table)->where('id', $id)->lockForUpdate()->first(), 404);
            $before = $this->snapshot($catalog, $id);
            if ((int) $before['status'] === $status) {
                return;
            }
            DB::table($table)->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
            $this->event($catalog, $id, $userId, $status === 1 ? 'Reactivacion' : 'Desactivacion', $before);
        }, 3);
    }

    public function authorizedAuditors(int $shippingLineId, int $branchId): Collection
    {
        return DB::table('shipping_line_branch_auditor as authorization')
            ->join('shipping_lines as naviera', 'naviera.id', '=', 'authorization.shipping_line_id')
            ->join('sucursales as branch', 'branch.id', '=', 'authorization.branch_id')
            ->join('external_audit_companies as auditor', 'auditor.id', '=', 'authorization.auditor_id')
            ->where('authorization.shipping_line_id', $shippingLineId)->where('authorization.branch_id', $branchId)
            ->where('naviera.status', 1)->where('branch.estado_id', 1)->where('auditor.status', 1)
            ->select('auditor.*')->orderBy('auditor.name')->get();
    }

    private function validateAuditorAssignments(array $branches, array $assignments, array $existing): void
    {
        foreach ($assignments as $branchId => $auditorIds) {
            $field = 'auditor_assignments.'.$branchId;
            if (! ctype_digit((string) $branchId) || ! in_array((int) $branchId, array_map('intval', $branches), true)) {
                throw ValidationException::withMessages([$field => 'La sucursal debe estar seleccionada para esta naviera.']);
            }
            if (count($auditorIds) !== count(array_unique(array_map('intval', $auditorIds)))) {
                throw ValidationException::withMessages([$field => 'No repitas una auditora dentro de la misma sucursal.']);
            }
            $previous = $existing[$branchId] ?? [];
            $branch = DB::table('sucursales')->where('id', $branchId)->lockForUpdate()->first();
            if ((int) $branch->estado_id !== 1 && array_diff(array_map('intval', $auditorIds), array_map('intval', $previous))) {
                throw ValidationException::withMessages([$field => 'No puedes agregar autorizaciones en una sucursal inactiva.']);
            }
            $this->validateLinks($field, 'external_audit_companies', 'status', $auditorIds, $previous);
        }
    }

    private function itemCode(string $catalog, string $name, int $id): string
    {
        $slug = strtoupper(Str::slug($name)) ?: ($catalog === 'repuestos' ? 'REPUESTO' : 'REPARACION');
        $prefix = $catalog === 'repuestos' ? 'REP' : 'RPR';
        $attempt = 0;
        do {
            $suffix = str_pad((string) $id, 6, '0', STR_PAD_LEFT).($attempt ? '-'.$attempt : '');
            $code = $prefix.'-'.rtrim(substr($slug, 0, 25 - strlen($suffix)), '-').'-'.$suffix;
            $attempt++;
        } while (DB::table(self::definition($catalog)['table'])->where('code', $code)->exists());

        return $code;
    }

    private function validateLinks(string $field, string $table, string $statusColumn, array $ids, array $existing): void
    {
        foreach ($ids as $id) {
            $linked = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            if (! $linked || ((int) $linked->{$statusColumn} !== 1 && ! in_array((int) $id, array_map('intval', $existing), true))) {
                throw ValidationException::withMessages([$field => 'Solo puedes agregar relaciones con registros activos.']);
            }
        }
    }

    private function event(string $catalog, int $id, int $userId, string $action, ?array $before): void
    {
        $after = $this->snapshot($catalog, $id);
        DB::table('operational_catalog_events')->insert([
            'catalog' => $catalog, 'record_id' => $id, 'user_id' => $userId, 'action' => $action,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => json_encode($this->snapshot($catalog, $id), JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
        $changes = app(SystemNotifications::class)->changes($before ?? [], $after, [
            'name' => 'Nombre', 'description' => 'Descripción', 'status' => 'Estado (1 activo / 2 inactivo)',
            'unit' => 'Unidad', 'reference_cost' => 'Precio referencial', 'currency' => 'Moneda', 'code' => 'Código',
            'branches' => 'Sucursales autorizadas', 'auditor_assignments' => 'Autorizaciones de auditoras por sucursal',
        ]);
        if ($changes !== []) {
            app(SystemNotifications::class)->activity($catalog, $action.' de catálogo', $after['name'] ?? $after['code'] ?? (string) $id,
                $changes, null, User::find($userId));
        }
    }
}
