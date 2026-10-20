<?php

namespace App\Livewire\Containers;

use App\Models\Bloque;
use App\Models\Patio;
use App\Models\Sucursal;
use App\Services\ContainerAuditAssignment;
use App\Services\ExternalAuditAccess;
use App\Services\MaintenanceWorkflow;
use App\Services\PypAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class InventoryTable extends Component
{
    private const ACTIVE = 1;

    private const PER_PAGE_OPTIONS = [5, 10, 25];

    public string $search = '';

    public int $perPage = 10;

    public int $page = 1;

    public string $sort = 'posicion';

    public string $order = 'desc';

    public string $patioId = '';

    public string $blockId = '';

    public string $positionFilter = '';

    public string $nameFilter = '';

    public string $unitStatusFilter = '';

    public string $boxStatusFilter = '';

    public string $ipsaStatusFilter = '';

    public string $pypStatusFilter = '';

    public string $maintenanceStatusFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $viewMode = 'table';

    public bool $pypMode = false;

    #[Locked]
    public bool $maintenanceMode = false;

    protected array $queryString = [
        'search' => ['except' => ''],
        'perPage' => ['except' => 10, 'as' => 'per_page'],
        'page' => ['except' => 1],
        'sort' => ['except' => 'posicion'],
        'order' => ['except' => 'desc'],
        'patioId' => ['except' => '', 'as' => 'patio_id'],
        'blockId' => ['except' => '', 'as' => 'bloque_id'],
        'positionFilter' => ['except' => '', 'as' => 'posicion'],
        'nameFilter' => ['except' => '', 'as' => 'nombre'],
        'unitStatusFilter' => ['except' => '', 'as' => 'estatus_unidad'],
        'boxStatusFilter' => ['except' => '', 'as' => 'estatus_box'],
        'ipsaStatusFilter' => ['except' => '', 'as' => 'estatus_ipsa'],
        'pypStatusFilter' => ['except' => '', 'as' => 'estatus_pyp'],
        'maintenanceStatusFilter' => ['except' => '', 'as' => 'mantenimiento'],
        'dateFrom' => ['except' => '', 'as' => 'fecha_desde'],
        'dateTo' => ['except' => '', 'as' => 'fecha_hasta'],
        'viewMode' => ['except' => 'table', 'as' => 'vista'],
    ];

    public function mount(bool $pypMode = false, bool $maintenanceMode = false): void
    {
        $this->maintenanceMode = $maintenanceMode;
        $this->pypMode = $pypMode;
        $this->normalizeTableState();

        if ($this->pypMode) {
            $this->viewMode = 'table';
        }
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, 255);
        $this->page = 1;
    }

    public function updatedPositionFilter(): void
    {
        $this->page = 1;
    }

    public function updatedNameFilter(): void
    {
        $this->nameFilter = mb_substr($this->nameFilter, 0, 255);
        $this->page = 1;
    }

    public function updatedUnitStatusFilter(): void
    {
        $this->page = 1;
    }

    public function updatedBoxStatusFilter(): void
    {
        $this->page = 1;
    }

    public function updatedIpsaStatusFilter(): void
    {
        $this->page = 1;
    }

    public function updatedPypStatusFilter(): void
    {
        $this->page = 1;
    }

    public function updatedMaintenanceStatusFilter(): void
    {
        $this->page = 1;
    }

    public function updatedDateFrom(): void
    {
        $this->page = 1;
    }

    public function updatedDateTo(): void
    {
        $this->page = 1;
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array($this->perPage, self::PER_PAGE_OPTIONS, true) ? $this->perPage : 10;
        $this->page = 1;
    }

    public function updatedPatioId(): void
    {
        $this->blockId = '';
        $this->page = 1;
    }

    public function updatedBlockId(): void
    {
        $this->page = 1;
    }

    public function selectPatio(int $patioId): void
    {
        $this->patioId = (string) $patioId;
        $this->blockId = '';
        $this->page = 1;
    }

    public function selectBlock(int $blockId): void
    {
        $this->blockId = (string) $blockId;
        $this->page = 1;
    }

    public function selectPerPage(int $perPage): void
    {
        $this->perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 10;
        $this->page = 1;
    }

    public function setSort(string $field, string $direction = 'asc'): void
    {
        if (! array_key_exists($field, $this->sortColumns())) {
            return;
        }

        $this->sort = $field;
        $this->order = $direction === 'desc' ? 'desc' : 'asc';
        $this->page = 1;
    }

    public function goToPage(int $value): void
    {
        $this->page = max(1, $value);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->page = 1;
        $this->sort = 'posicion';
        $this->order = 'desc';
        $this->positionFilter = '';
        $this->nameFilter = '';
        $this->unitStatusFilter = '';
        $this->boxStatusFilter = '';
        $this->ipsaStatusFilter = '';
        $this->pypStatusFilter = '';
        $this->maintenanceStatusFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
    }

    public function setViewMode(string $mode): void
    {
        if ($this->pypMode || ! in_array($mode, ['table', 'yard3d'], true)) {
            return;
        }

        $this->viewMode = $mode;
    }

    public function sortIcon(string $field): string
    {
        if ($this->sort !== $field) {
            return '↕';
        }

        return $this->order === 'asc' ? '↑' : '↓';
    }

    public function render()
    {
        abort_if(! $this->maintenanceMode && (int) auth()->user()->rol_id === 4, 403);
        if ($this->maintenanceMode) {
            app(MaintenanceWorkflow::class)->authorize(auth()->user(), (int) session('sucursal_activa'));
            $this->pypMode = false;
        }
        if (in_array((int) auth()->user()->rol_id, [1, 2, 3], true)) {
            abort_unless(auth()->user()->hasPermission($this->pypMode ? 'pyp.view' : 'containers.view'), 403);
        }
        $branch = $this->activeBranch();
        abort_if(! $branch, 404, 'No hay sucursal activa.');
        if ((int) auth()->user()->rol_id === 5) {
            app(ExternalAuditAccess::class)->authorizeBranch(auth()->user(), (int) $branch->id);
        }

        $patios = Patio::active()
            ->where('sucursal_id', $branch->id)
            ->orderBy('nombre')
            ->get();

        $selectedPatio = $this->resolveSelectedPatio($patios);
        $blocks = $selectedPatio
            ? Bloque::active()
                ->where('patio_id', $selectedPatio->id)
                ->withCount('contenedoresActivos')
                ->orderBy('numero')
                ->get()
            : collect();
        $selectedBlock = $this->resolveSelectedBlock($blocks);
        $hasActiveBlocks = $patios->isNotEmpty() && Bloque::active()->whereIn('patio_id', $patios->modelKeys())->exists();

        $baseQuery = $this->containersQuery($branch->id);
        $globalSearch = $this->pypMode && trim($this->search) !== '';

        if ($globalSearch) {
            if (in_array((int) auth()->user()->rol_id, [4, 5], true)) {
                $baseQuery->where(function ($query): void {
                    $query->where('c.estatus_ipsa_id', 1)
                        ->orWhereNotNull('pa.contenedor_id');
                });
            }
        } elseif ($selectedBlock) {
            $baseQuery->where('c.bloque_id', $selectedBlock->id);
        } else {
            $baseQuery->whereRaw('1 = 0');
        }

        $capacityCurrent = $selectedBlock ? (int) $selectedBlock->contenedores_activos_count : 0;
        $capacityMax = (int) ($selectedBlock?->capacidad_maxima ?? 0);
        $occupancy = $capacityMax > 0 ? (int) round(($capacityCurrent / $capacityMax) * 100) : 0;

        $statusOptions = $this->statusOptions(clone $baseQuery);
        $containersQuery = $this->applyFilters(clone $baseQuery);
        $this->applySorting($containersQuery);

        $containers = $containersQuery->paginate($this->perPage, ['*'], 'page', $this->page);

        if ($containers->lastPage() > 0 && $this->page > $containers->lastPage()) {
            $this->page = $containers->lastPage();
            $containers = $containersQuery->paginate($this->perPage, ['*'], 'page', $this->page);
        }

        if ($this->maintenanceMode) {
            foreach ($containers->items() as $container) {
                $container->maintenance_actions = $this->maintenanceActions($container, (int) $container->patio_id, (int) $container->bloque_id);
            }
        }

        return view('livewire.containers.inventory-table', [
            'patios' => $patios,
            'branchName' => $branch->nombre,
            'hasActiveBlocks' => $hasActiveBlocks,
            'blocks' => $blocks,
            'selectedPatio' => $selectedPatio,
            'selectedBlock' => $selectedBlock,
            'containers' => $containers->items(),
            'pagination' => [
                'page' => $containers->currentPage(),
                'per_page' => $containers->perPage(),
                'total' => $containers->total(),
                'total_pages' => max(1, $containers->lastPage()),
            ],
            'capacityCurrent' => $capacityCurrent,
            'capacityMax' => $capacityMax,
            'occupancy' => $occupancy,
            'perPage' => $this->perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'statusOptions' => $statusOptions,
            'globalSearch' => $globalSearch,
            'viewMode' => $this->viewMode,
            'yardScene' => ! $this->pypMode && $selectedPatio ? $this->yardSceneData($branch->id, $selectedPatio->id, $selectedBlock?->id) : null,
        ]);
    }

    private function normalizeTableState(): void
    {
        $this->search = mb_substr($this->search, 0, 255);
        $this->nameFilter = mb_substr($this->nameFilter, 0, 255);
        $this->perPage = in_array($this->perPage, self::PER_PAGE_OPTIONS, true) ? $this->perPage : 10;
        $this->page = max(1, $this->page);
        $this->order = $this->order === 'asc' ? 'asc' : 'desc';

        if (! array_key_exists($this->sort, $this->sortColumns())) {
            $this->sort = 'posicion';
        }
    }

    private function applyFilters($query)
    {
        $search = trim($this->search);
        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('c.nombre', 'like', "%{$search}%");

                if (! $this->pypMode) {
                    $inner->orWhere('eu.nombre', 'like', "%{$search}%")
                        ->orWhere('eb.nombre', 'like', "%{$search}%")
                        ->orWhere('ei.nombre', 'like', "%{$search}%");
                }
            });
        }

        if (trim($this->positionFilter) !== '') {
            $query->where('c.posicion', (int) $this->positionFilter);
        }

        if (trim($this->nameFilter) !== '') {
            $query->where('c.nombre', 'like', '%'.trim($this->nameFilter).'%');
        }

        $this->whereUpperEquals($query, 'eu.nombre', $this->unitStatusFilter);
        $this->whereUpperEquals($query, 'eb.nombre', $this->boxStatusFilter);
        $this->whereUpperEquals($query, 'ei.nombre', $this->ipsaStatusFilter);
        $this->whereUpperEquals($query, ContainerAuditAssignment::statusSql(), $this->pypStatusFilter);
        if ($this->maintenanceMode) {
            $this->whereUpperEquals($query, $this->maintenanceStatusSql(), $this->maintenanceStatusFilter);
        }

        if (trim($this->dateFrom) !== '') {
            $query->whereDate('c.fecha_ingreso', '>=', $this->dateFrom);
        }

        if (trim($this->dateTo) !== '') {
            $query->whereDate('c.fecha_ingreso', '<=', $this->dateTo);
        }

        return $query;
    }

    private function applySorting($query): void
    {
        $columns = $this->sortColumns();
        $query->orderBy($columns[$this->sort] ?? 'c.posicion', $this->order)
            ->orderByDesc('c.id');
    }

    private function whereUpperEquals($query, string $column, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        $query->whereRaw('UPPER(COALESCE('.$column.", '')) = ?", [Str::upper(Str::ascii($value))]);
    }

    private function statusOptions($query): array
    {
        $rows = $query->get();

        return [
            'unit' => $this->uniqueOptions($rows->pluck('estatus')),
            'box' => $this->uniqueOptions($rows->pluck('estatus_box')),
            'ipsa' => $this->uniqueOptions($rows->pluck('estatus_ipsa')),
            'pyp' => PypAuditService::STATES,
        ];
    }

    private function yardSceneData(int $branchId, int $patioId, ?int $selectedBlockId): array
    {
        $canOperate = in_array((int) auth()->user()->rol_id, [1, 2, 3], true);
        $hasVisualFilters = collect([
            $this->search,
            $this->positionFilter,
            $this->nameFilter,
            $this->unitStatusFilter,
            $this->boxStatusFilter,
            $this->ipsaStatusFilter,
            $this->pypStatusFilter,
            $this->maintenanceStatusFilter,
            $this->dateFrom,
            $this->dateTo,
        ])->contains(fn (string $value): bool => trim($value) !== '');

        $matchingIds = collect();
        if ($hasVisualFilters) {
            $matchingQuery = $this->containersQuery($branchId)
                ->where('p.id', $patioId);
            $this->applyFilters($matchingQuery);
            $matchingIds = $matchingQuery
                ->pluck('c.id')
                ->map(fn ($id): int => (int) $id)
                ->flip();
        }

        $blocks = Bloque::active()
            ->where('patio_id', $patioId)
            ->withCount('contenedoresActivos')
            ->orderBy('numero')
            ->get();

        $containersQuery = $this->containersQuery($branchId)
            ->where('p.id', $patioId)
            ->orderBy('b.numero')
            ->orderBy('c.posicion');

        $containers = $containersQuery
            ->get()
            ->groupBy('bloque_id');

        return [
            'selected_block_id' => $selectedBlockId,
            'fit_to_viewport' => $this->maintenanceMode,
            'has_visual_filters' => $hasVisualFilters,
            'blocks' => $blocks->map(fn (Bloque $block): array => [
                'id' => $block->id,
                'numero' => $block->numero,
                'capacidad' => (int) $block->capacidad_maxima,
                'ocupados' => (int) $block->contenedores_activos_count,
                'selected' => $selectedBlockId === (int) $block->id,
                'containers' => $containers->get($block->id, collect())->map(fn (object $container): array => [
                    'id' => $container->id,
                    'nombre' => $container->nombre,
                    'posicion' => (int) $container->posicion,
                    'estatus_unidad' => $container->estatus,
                    'estatus_box' => $container->estatus_box,
                    'estatus_ipsa' => $container->estatus_ipsa,
                    'estatus_ipsa_id' => (int) $container->estatus_ipsa_id,
                    'pyp' => $container->estatus_pyp,
                    'matches' => ! $hasVisualFilters || $matchingIds->has((int) $container->id),
                    'actions' => $this->maintenanceMode ? $this->maintenanceActions($container, $patioId, (int) $block->id) : ($canOperate ? [
                        'info' => route('containers.show', [
                            'container' => $container->id,
                            'patio_id' => $patioId,
                            'bloque_id' => $block->id,
                        ]),
                        'transfer' => auth()->user()->hasPermission('transfers.operate') ? route('transfers.create', ['contenedor_id' => $container->id]) : null,
                        'dispatch' => auth()->user()->hasPermission('dispatches.operate') ? route('dispatches.create', ['contenedor_id' => $container->id]) : null,
                        'ticket' => route('documents.ingress.ticket', [
                            'contenedor_id' => $container->id,
                            'patio_id' => $patioId,
                            'bloque_id' => $block->id,
                        ]),
                    ] : []),
                    'url' => route($this->maintenanceMode ? 'maintenance.container' : 'containers.show', [
                        'container' => $container->id,
                        'patio_id' => $patioId,
                        'bloque_id' => $block->id,
                    ]),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function uniqueOptions($values): array
    {
        return $values
            ->filter(fn ($value): bool => trim((string) $value) !== '')
            ->unique(fn ($value): string => Str::upper(Str::ascii((string) $value)))
            ->sortBy(fn ($value): string => (string) $value, SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function sortColumns(): array
    {
        $columns = [
            'posicion' => 'c.posicion',
            'nombre' => 'c.nombre',
            'estatus_unidad' => 'eu.nombre',
            'estatus_box' => 'eb.nombre',
            'estatus_ipsa' => 'ei.nombre',
            'fecha_ingreso' => 'c.fecha_ingreso',
            'estatus_pyp' => 'pa.estatus_pyp',
        ];

        if ($this->maintenanceMode) {
            $columns['mantenimiento'] = DB::raw($this->maintenanceStatusSql());
        }

        return $columns;
    }

    private function maintenanceActions(object $container, int $patioId, int $blockId): array
    {
        $back = [];
        foreach ($this->queryString as $property => $options) {
            if ($this->{$property} !== $options['except']) {
                $back[$options['as'] ?? $property] = $this->{$property};
            }
        }
        $params = ['patio_id' => $patioId, 'bloque_id' => $blockId, 'back' => $back];
        $canContinue = $container->maintenance_job_status === 'in_progress'
            && (int) $container->maintenance_mechanic_id === (int) auth()->id()
            && strtoupper(trim((string) $container->estatus_ipsa)) === 'OOS'
            && (int) $container->ipsa_status_active === self::ACTIVE;

        return [
            'info' => route('maintenance.container', ['container' => $container->id] + $params),
            'maintenance' => $container->maintenance_job_id
                ? route('maintenance.show', ['job' => $container->maintenance_job_id, 'back' => $back])
                : ($container->maintenance_can_start ? route('maintenance.create', ['container_id' => $container->id] + $params) : null),
            'maintenance_label' => $container->maintenance_job_id ? ($canContinue ? 'Continuar reparación' : 'Ver reparación') : 'Reparar',
        ];
    }

    private function containersQuery(int $branchId)
    {
        $query = DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('estatus_contenedores as eu', 'c.estatus_unidad_id', '=', 'eu.id')
            ->leftJoin('estatus_contenedores as eb', 'c.estatus_box_id', '=', 'eb.id')
            ->leftJoin('estatus_ipsa as ei', 'c.estatus_ipsa_id', '=', 'ei.id')
            ->where('c.estado_id', self::ACTIVE)
            ->where('p.sucursal_id', $branchId)
            ->select([
                'c.id',
                'c.nombre',
                'c.posicion',
                'c.fecha_ingreso',
                'c.estatus_ipsa_id',
                'eu.nombre as estatus',
                'eb.nombre as estatus_box',
                'ei.nombre as estatus_ipsa',
                'b.id as bloque_id',
                'b.numero as bloque_numero',
                'p.id as patio_id',
                'p.nombre as patio_nombre',
            ]);

        if ($this->maintenanceMode) {
            return $query->leftJoin('maintenance_jobs as mj', function ($join) use ($branchId): void {
                $join->on('mj.container_id', '=', 'c.id')->where('mj.id', '=', function ($latest) use ($branchId): void {
                    MaintenanceWorkflow::scopeCurrentIngress($latest->selectRaw('MAX(j.id)')->from('maintenance_jobs as j'))
                        ->where('j.branch_id', $branchId);
                });
            })
                ->leftJoin('users as mechanic', 'mechanic.id', '=', 'mj.mechanic_id')
                ->leftJoin('container_ingress_cycles as ic', function ($join): void {
                    $join->on('ic.id', '=', 'c.current_ingress_cycle_id')->on('ic.container_id', '=', 'c.id');
                })
                ->leftJoin('pyp_auditoria as pa', function ($join): void {
                    $join->on('pa.contenedor_id', '=', 'c.id')->where('pa.estado_id', self::ACTIVE);
                })
                ->addSelect(['c.current_ingress_cycle_id', 'mj.id as maintenance_job_id', 'mj.status as maintenance_job_status',
                    'mj.mechanic_id as maintenance_mechanic_id', 'ei.estado_id as ipsa_status_active',
                    'mechanic.username as mechanic_name', 'ic.audit_company_name'])
                ->selectRaw($this->maintenanceStatusSql().' as maintenance_status')
                ->selectRaw("CASE WHEN ((c.current_ingress_cycle_id IS NULL AND c.fecha_ingreso IS NOT NULL) OR (ic.id IS NOT NULL AND ic.closed_at IS NULL AND ic.branch_id = p.sucursal_id)) AND UPPER(TRIM(ei.nombre)) = 'OOS' AND ei.estado_id = 1 AND mj.id IS NULL THEN 1 ELSE 0 END as maintenance_can_start")
                ->selectRaw(ContainerAuditAssignment::statusSql().' as audit_status')
                ->selectRaw(ContainerAuditAssignment::noticeSql().' as audit_assignment_notice')
                ->selectRaw('NULL as estatus_pyp, NULL as informacion_adicional, NULL as es_asignado');
        }

        $query->leftJoin('pyp_auditoria as pa', function ($join): void {
            $join->on('c.id', '=', 'pa.contenedor_id')->where('pa.estado_id', self::ACTIVE);
        })->addSelect(['pa.estatus_pyp', 'pa.informacion_adicional', 'pa.contenedor_id as es_asignado']);

        app(ContainerAuditAssignment::class)->joinCurrent($query);

        return app(ExternalAuditAccess::class)->scope($query, auth()->user(), $branchId);
    }

    private function maintenanceStatusSql(): string
    {
        return "CASE WHEN mj.status = 'in_progress' THEN 'En reparacion' WHEN mj.status = 'returned_for_repair' THEN 'Devuelto a reparacion' WHEN mj.status IN ('pending_review', 'completed') THEN 'Reparado' WHEN UPPER(TRIM(ei.nombre)) = 'OOS' AND ei.estado_id = 1 THEN 'Por atender' ELSE NULL END";
    }

    private function resolveSelectedPatio($patios): ?Patio
    {
        if ($patios->isEmpty() || $this->patioId === '') {
            return null;
        }

        return $patios->firstWhere('id', (int) $this->patioId);
    }

    private function resolveSelectedBlock($blocks): ?Bloque
    {
        if ($blocks->isEmpty() || $this->blockId === '') {
            return null;
        }

        return $blocks->firstWhere('id', (int) $this->blockId);
    }

    private function activeBranch(): ?Sucursal
    {
        $branchId = (int) session('sucursal_activa');

        return $branchId > 0 ? Sucursal::find($branchId) : null;
    }
}
