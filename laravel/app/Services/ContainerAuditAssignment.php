<?php

namespace App\Services;

use App\Models\Contenedor;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContainerAuditAssignment
{
    public function formData(int $branchId, ?object $container = null): array
    {
        $cycle = $container?->current_ingress_cycle_id ? DB::table('container_ingress_cycles')->find($container->current_ingress_cycle_id) : null;
        $lines = DB::table('shipping_lines as s')->join('shipping_line_branch as b', 'b.shipping_line_id', '=', 's.id')
            ->where('b.branch_id', $branchId)->where('s.status', 1)->orderBy('s.name')->get(['s.id', 's.name']);
        $auditors = DB::table('shipping_line_branch_auditor as a')->join('external_audit_companies as e', 'e.id', '=', 'a.auditor_id')
            ->where('a.branch_id', $branchId)->where('e.status', 1)->whereIn('a.shipping_line_id', $lines->pluck('id'))
            ->orderBy('e.name')->get(['a.shipping_line_id', 'e.id', 'e.name'])->groupBy('shipping_line_id');

        return ['assignmentCycle' => $cycle, 'assignmentLines' => $lines, 'assignmentAuditors' => $auditors];
    }

    public function resolve(User $user, int $branchId, array $data, ?object $cycle = null): array
    {
        $values = Validator::make($data, [
            'shipping_line_id' => ['required', 'integer'], 'audit_company_id' => ['nullable', 'integer'],
            'audit_reference' => ['nullable', 'string', 'max:255'], 'assignment_reason' => ['nullable', 'string', 'max:255'],
        ], [
            'shipping_line_id.required' => 'Selecciona una naviera del catalogo.',
            'shipping_line_id.integer' => 'Selecciona una naviera valida.',
            'audit_company_id.integer' => 'Selecciona una auditora valida.',
            'audit_reference.max' => 'Las observaciones no pueden superar los 255 caracteres.',
            'assignment_reason.max' => 'El motivo no puede superar los 255 caracteres.',
        ])->validate();
        $lineId = (int) $values['shipping_line_id'];
        $companyId = (int) ($values['audit_company_id'] ?? 0) ?: null;
        $same = $cycle && (int) $cycle->shipping_line_id === $lineId && (int) $cycle->audit_company_id === (int) $companyId;
        if (! $same && ($companyId || $cycle)) {
            abort_unless($user->hasPermission('pyp.assign'), 403, 'No tienes permiso para confirmar o cambiar la asignacion de auditoria.');
        }
        // Existing inactive relationships may be retained, never granted to a new assignment.
        $line = DB::table('shipping_lines')->where('id', $lineId)->sharedLock()->first();
        $lineAllowed = $line && (int) $line->status === 1 && DB::table('shipping_line_branch')->where('shipping_line_id', $lineId)->where('branch_id', $branchId)->exists();
        if (! $same && ! $lineAllowed) {
            throw ValidationException::withMessages(['shipping_line_id' => 'La naviera no esta activa o no opera en esta sucursal.']);
        }
        $company = $companyId ? DB::table('external_audit_companies')->where('id', $companyId)->sharedLock()->first() : null;
        if (! $same && $companyId && ! app(OperationalCatalog::class)->authorizedAuditors($lineId, $branchId)->contains('id', $companyId)) {
            throw ValidationException::withMessages(['audit_company_id' => 'La auditora no esta autorizada para esta naviera y sucursal.']);
        }
        $reference = trim((string) ($values['audit_reference'] ?? ''));
        $reason = trim((string) ($values['assignment_reason'] ?? ''));
        if ($cycle && ! $same && $reason === '') {
            throw ValidationException::withMessages(['assignment_reason' => 'Indica el motivo del cambio de asignacion.']);
        }

        return ['shipping_line_id' => $lineId, 'shipping_line_name' => $same ? $cycle->shipping_line_name : $line->name,
            'audit_company_id' => $companyId, 'audit_company_name' => $same ? $cycle->audit_company_name : $company?->name,
            'audit_reference' => array_key_exists('audit_reference', $values) ? ($reference ?: null) : ($same ? $cycle->audit_reference : null), 'reason' => $reason];
    }

    public function start(User $user, Contenedor $container, int $branchId, array $assignment, bool $reentry, ?string $previousShippingLine = null): void
    {
        $before = $this->snapshot($container);
        if ($previousShippingLine !== null) {
            $before['legacy_shipping_line'] = $previousShippingLine;
        }
        if ($container->current_ingress_cycle_id) {
            DB::table('container_ingress_cycles')->where('id', $container->current_ingress_cycle_id)->update(['closed_at' => now(), 'updated_at' => now()]);
        }
        $cycleId = DB::table('container_ingress_cycles')->insertGetId($this->cycleFields($assignment) + [
            'container_id' => $container->id, 'branch_id' => $branchId, 'entered_at' => $container->fecha_ingreso,
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $container->forceFill(['current_ingress_cycle_id' => $cycleId])->save();
        if (! $reentry && ! $before['cycle'] && ! $container->wasRecentlyCreated) {
            $jobs = DB::table('maintenance_jobs')->where('container_id', $container->id)->whereNull('ingress_cycle_id')
                ->where('legacy_ingress_at', $container->fecha_ingreso)->lockForUpdate()->get();
            foreach ($jobs as $job) {
                DB::table('maintenance_jobs')->where('id', $job->id)->update(['ingress_cycle_id' => $cycleId, 'version' => $job->version + 1, 'updated_at' => now()]);
                DB::table('maintenance_events')->insert(['job_id' => $job->id, 'user_id' => $user->id, 'action' => 'ingress_linked',
                    'before_data' => json_encode(['job' => $job], JSON_THROW_ON_ERROR),
                    'after_data' => json_encode(['job' => DB::table('maintenance_jobs')->find($job->id)], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }
        }
        $this->resetAudit($user, $container, $assignment);
        app(SystemNotifications::class)->auditAssigned($container, $branchId);
        $this->event($user, $container, $reentry ? 'reentry' : 'ingress', $assignment['reason'] ?: ($reentry ? 'Nuevo ciclo de ingreso; no hereda aprobaciones.' : 'Asignacion inicial del ingreso.'), $before);
        if (! $container->wasRecentlyCreated && ! $reentry && ! $before['cycle']) {
            app(SystemNotifications::class)->activity('audits', 'Asignación del ingreso regularizada', $container->nombre,
                app(SystemNotifications::class)->changes([], $assignment, ['shipping_line_name' => 'Naviera', 'audit_company_name' => 'Auditora', 'audit_reference' => 'Referencia']), [$branchId], $user);
        }
    }

    public function change(User $user, Contenedor $container, int $branchId, array $data): ?string
    {
        $cycle = $container->current_ingress_cycle_id ? DB::table('container_ingress_cycles')->where('id', $container->current_ingress_cycle_id)->lockForUpdate()->first() : null;
        if (! $cycle && empty($data['shipping_line_id'])) {
            return null;
        }
        $assignment = $this->resolve($user, $branchId, $data, $cycle);
        if (! $cycle) {
            abort_unless($user->hasPermission('pyp.assign'), 403);
            if (trim((string) ($data['assignment_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['assignment_reason' => 'Indica el motivo de la regularizacion del registro anterior.']);
            }
            $this->start($user, $container, $branchId, $assignment, false);

            return $assignment['shipping_line_name'];
        }
        $changed = (int) $cycle->shipping_line_id !== $assignment['shipping_line_id'] || (int) $cycle->audit_company_id !== (int) $assignment['audit_company_id'];
        $referenceChanged = (string) $cycle->audit_reference !== (string) $assignment['audit_reference'];
        if ($changed || $referenceChanged) {
            abort_unless($user->hasPermission('pyp.assign'), 403);
            $before = $this->snapshot($container);
            DB::table('container_ingress_cycles')->where('id', $cycle->id)->update($this->cycleFields($assignment) + ['updated_at' => now()]);
            if ($changed) {
                $this->resetAudit($user, $container, $assignment);
                app(SystemNotifications::class)->auditAssigned($container, $branchId);
            }
            $this->event($user, $container, $changed ? 'reassignment' : 'instruction', $assignment['reason'] ?: 'Actualizacion de referencia de la instruccion.', $before);
            $changes = app(SystemNotifications::class)->changes((array) $cycle, $assignment, [
                'shipping_line_name' => 'Naviera', 'audit_company_name' => 'Auditora', 'audit_reference' => 'Referencia de la instrucción',
            ]);
            app(SystemNotifications::class)->activity('audits', $changed ? 'Asignación de auditoría modificada' : 'Instrucción de auditoría modificada',
                $container->nombre, $changes, [$branchId], $user);
        }

        return $assignment['shipping_line_name'];
    }

    private function cycleFields(array $assignment): array
    {
        return array_intersect_key($assignment, array_flip(['shipping_line_id', 'shipping_line_name', 'audit_company_id', 'audit_company_name', 'audit_reference']));
    }

    private function resetAudit(User $user, Contenedor $container, array $assignment): void
    {
        DB::table('pyp_auditoria')->updateOrInsert(['contenedor_id' => $container->id], [
            'external_audit_company_id' => $assignment['audit_company_id'], 'ingress_cycle_id' => $container->current_ingress_cycle_id,
            'estatus_pyp' => 'Asignado', 'informacion_adicional' => null, 'fecha_registro' => now(),
            'usuario_id' => $user->id, 'estado_id' => $assignment['audit_company_id'] ? 1 : 2,
        ]);
    }

    private function snapshot(Contenedor $container): array
    {
        return ['cycle' => $container->current_ingress_cycle_id ? DB::table('container_ingress_cycles')->find($container->current_ingress_cycle_id) : null,
            'legacy_shipping_line' => $container->naviera,
            'audit' => DB::table('pyp_auditoria')->where('contenedor_id', $container->id)->first()];
    }

    private function event(User $user, Contenedor $container, string $type, string $reason, array $before): void
    {
        DB::table('container_assignment_events')->insert([
            'container_id' => $container->id, 'ingress_cycle_id' => $container->current_ingress_cycle_id,
            'user_id' => $user->id, 'event_type' => $type, 'reason' => $reason,
            'before_data' => json_encode($before, JSON_THROW_ON_ERROR), 'after_data' => json_encode($this->snapshot($container), JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function joinCurrent(Builder $query): Builder
    {
        return $query->leftJoin('container_ingress_cycles as ic', function ($join): void {
            $join->on('ic.id', '=', 'c.current_ingress_cycle_id')->on('ic.container_id', '=', 'c.id');
        })
            ->addSelect(['c.current_ingress_cycle_id', 'ic.shipping_line_name', 'ic.audit_company_name', 'ic.audit_reference', 'ic.audit_company_id', 'ic.shipping_line_id'])
            ->selectRaw(self::statusSql().' as audit_status')
            ->selectRaw(self::noticeSql().' as audit_assignment_notice');
    }

    public static function statusSql(): string
    {
        return "CASE WHEN ic.id IS NULL OR ic.audit_company_id IS NULL OR ic.closed_at IS NOT NULL THEN NULL WHEN pa.estatus_pyp IN ('En revision', 'Aprobado', 'Rechazado') AND pa.ingress_cycle_id = ic.id AND pa.external_audit_company_id = ic.audit_company_id THEN pa.estatus_pyp ELSE 'Pendiente de revision' END";
    }

    public static function noticeSql(): string
    {
        return "CASE WHEN ic.id IS NULL THEN 'Pendiente de regularizacion' WHEN ic.audit_company_id IS NULL THEN 'Sin auditora asignada' WHEN ic.closed_at IS NOT NULL THEN 'Ingreso cerrado' ELSE NULL END";
    }
}
