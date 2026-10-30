<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdministrationTable
{
    public static function columns(string $scope): array
    {
        $column = fn (string $key, string $label, string|array $fields, ?Builder $relation = null, int $scale = 1): array => compact('key', 'label', 'fields', 'relation', 'scale');

        return match ($scope) {
            'correction-history' => [
                $column('number', 'Corrección', 'correction_history.number') + ['numeric' => true],
                $column('date', 'Fecha de solicitud', ['correction_history.created_at', 'correction_history.display_date']),
                $column('requester', 'Solicitada por', 'correction_history.requester_name') + ['filter_field' => 'correction_history.requested_by'],
                $column('status', 'Estado', 'correction_history.status_label') + ['filter_field' => 'correction_history.status'],
            ],
            'audit-history' => [
                $column('date', 'Fecha', ['audit_history.created_at', 'audit_history.display_date']),
                $column('inspector', 'Inspector', 'audit_history.inspector_name') + ['filter_field' => 'audit_history.inspector_id'],
                $column('status', 'Resultado', 'audit_history.status') + ['filter_field' => 'audit_history.status'],
                $column('notes', 'Observaciones', 'audit_history.notes'),
            ],
            'repair-history' => [
                $column('date', 'Fecha de inicio', ['repair_history.created_at', 'repair_history.display_date']),
                $column('name', 'Reparación', 'repair_history.repair_type') + ['filter_field' => 'repair_history.repair_type'],
                $column('mechanic', 'Mecánico', 'repair_history.mechanic_name') + ['filter_field' => 'repair_history.mechanic_id'],
                $column('status', 'Estado', 'repair_history.status_label') + ['filter_field' => 'repair_history.status'],
                $column('corrections', 'Correcciones de auditoría', 'repair_history.correction_count') + ['numeric' => true, 'description' => 'Número de rondas de corrección solicitadas por la auditora para esta reparación.'],
            ],
            'branches' => [$column('name', 'Nombre', 'sucursales.nombre'), $column('location', 'Ubicacion', ['sucursales.ubicacion', 'sucursales.direccion'])],
            'users' => [
                $column('name', 'Usuario', 'users.username'), $column('email', 'Correo', 'users.email'),
                $column('role', 'Rol', 'roles.nombre', DB::table('roles')->whereColumn('roles.id', 'users.rol_id')),
                $column('branch', 'Sucursal', 'sucursales.nombre', DB::table('sucursales')->whereColumn('sucursales.id', 'users.sucursal_id')),
            ],
            'unit-types' => [
                $column('name', 'Nombre', 'tipos_unidad.nombre'), $column('description', 'Descripcion', 'tipos_unidad.descripcion'),
                $column('models', 'Modelos', 'modelos.nombre', DB::table('modelos')->whereColumn('modelos.tipo_unidad_id', 'tipos_unidad.id')->where('modelos.estado_id', 1)),
            ],
            'yard-patios' => [
                $column('name', 'Patio', 'yard_patios.nombre'),
                $column('blocks', 'Bloques', 'yard_patios.bloques_activos_count') + ['numeric' => true],
                $column('capacity', 'Capacidad', 'yard_patios.capacidad_total') + ['numeric' => true],
                $column('occupancy', 'Ocupación', 'yard_patios.ocupacion_total') + ['numeric' => true],
            ],
            'yard-blocks' => array_map(fn (array $item) => $item + ['numeric' => true], [
                $column('name', 'Bloque', 'yard_blocks.numero'),
                $column('capacity', 'Capacidad', 'yard_blocks.capacidad_maxima'),
                $column('occupancy', 'Ocupación', 'yard_blocks.contenedores_activos_count'),
            ]),
            'statuses' => [
                $column('name', 'Nombre', 'estatus_contenedores.nombre'),
                $column('category', 'Categoria', 'tipos_estatus.nombre', DB::table('tipos_estatus')->whereColumn('tipos_estatus.id', 'estatus_contenedores.tipo_estatus_id')),
            ],
            'navieras' => [
                $column('name', 'Nombre', 'shipping_lines.name'),
                $column('branches', 'Sucursales', 'sucursales.nombre', DB::table('shipping_line_branch')->join('sucursales', 'sucursales.id', '=', 'shipping_line_branch.branch_id')->whereColumn('shipping_line_branch.shipping_line_id', 'shipping_lines.id')),
                $column('auditors', 'Auditoras', 'external_audit_companies.name', DB::table('external_audit_companies')->where(function ($query): void {
                    foreach (['shipping_line_auditor', 'shipping_line_branch_auditor'] as $pivot) {
                        $query->orWhereExists(DB::table($pivot)->selectRaw('1')->whereColumn($pivot.'.shipping_line_id', 'shipping_lines.id')->whereColumn($pivot.'.auditor_id', 'external_audit_companies.id'));
                    }
                })),
            ],
            'auditoras' => [
                $column('name', 'Nombre', 'external_audit_companies.name'),
                $column('contact', 'Contacto', 'external_audit_companies.contact_name'),
                $column('email', 'Correo', 'external_audit_companies.email'),
                $column('phone', 'Telefono', 'external_audit_companies.phone') + ['sortable' => false],
            ],
            'iso' => [$column('code', 'Codigo', 'container_iso_codes.code'), $column('name', 'Nombre', 'container_iso_codes.name'), $column('type', 'Tipo', 'container_iso_codes.type_name'), $column('dimensions', 'Largo / ancho / alto (m)', ['container_iso_codes.length_mm', 'container_iso_codes.width_mm', 'container_iso_codes.height_mm'], scale: 1000) + ['sortable' => false]],
            'repuestos', 'reparaciones' => [
                $column('code', 'Codigo', 'code'), $column('name', 'Nombre', 'name'), $column('unit', 'Unidad', 'unit'), $column('cost', $scope === 'reparaciones' ? 'Tarifa de referencia' : 'Costo de referencia', ['reference_cost', 'currency']),
            ],
        };
    }

    public static function paginate(EloquentBuilder|Builder $query, Request $request, string $scope, ?string $tableAlias = null): LengthAwarePaginator
    {
        $columns = self::columns($scope);
        $filters = $request->query('filters', []);
        $filters = is_array($filters) ? $filters : [];
        $search = self::text($request->query('q'));
        if ($search !== '') {
            $query->where(function ($query) use ($columns, $search): void {
                foreach ($columns as $column) {
                    $query->orWhere(fn ($query) => self::matchColumn($query, $column, $search));
                }
            });
        }
        foreach ($columns as $column) {
            $input = $filters[$column['key']] ?? null;
            $value = isset($column['filter_field']) && is_string($input) ? mb_substr(trim($input), 0, 255) : self::text($input);
            if ($value !== '') {
                if (isset($column['filter_field'])) {
                    $query->where($column['filter_field'], $value);
                } else {
                    self::matchColumn($query, $column, $value);
                }
            }
        }
        $sortableColumns = collect($columns)->filter(fn (array $column) => $column['sortable'] ?? true);
        $history = in_array($scope, ['repair-history', 'audit-history', 'correction-history'], true);
        $defaultSort = $history ? 'date' : 'name';
        $sort = $sortableColumns->firstWhere('key', $request->query('sort')) ?? $sortableColumns->firstWhere('key', $defaultSort);
        $direction = $request->query('order', $history ? 'desc' : 'asc') === 'desc' ? 'desc' : 'asc';
        $field = (array) $sort['fields'];
        $query->reorder()->orderBy($sort['relation'] ? (clone $sort['relation'])->select($field[0])->orderBy($field[0])->limit(1) : $field[0], $direction);
        $table = $tableAlias ?? ($query instanceof EloquentBuilder ? $query->getModel()->getTable() : $query->from);
        $query->orderBy($table.'.id');
        $size = $request->integer('per_page', 10);
        $size = in_array($size, [5, 10, 25], true) ? $size : 10;
        $page = max(1, $request->integer('page', 1));
        $records = $query->paginate($size, ['*'], 'page', $page)->withQueryString();
        if ($page > $records->lastPage()) {
            $records = $query->paginate($size, ['*'], 'page', $records->lastPage())->withQueryString();
        }

        return $records;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 120) : '';
    }

    private static function matchColumn(EloquentBuilder|Builder $query, array $column, string $value): void
    {
        $match = function ($query) use ($column, $value): void {
            $query->where(function ($query) use ($column, $value): void {
                foreach ((array) $column['fields'] as $field) {
                    if ($column['scale'] > 1 && is_numeric($value)) {
                        $query->orWhere($field, '=', (float) $value * $column['scale']);
                    } else {
                        $query->orWhere($field, 'like', '%'.$value.'%');
                    }
                }
            });
        };
        if ($column['relation']) {
            $relation = clone $column['relation'];
            $match($relation);
            $query->whereExists($relation);
        } else {
            $match($query);
        }
    }
}
