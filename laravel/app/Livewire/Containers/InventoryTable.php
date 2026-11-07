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

    #[Locked]
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
        $this->pypMode = (int) auth()->user()->rol_id === 5 || $pypMode;
        $this->normalizeTableState();

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
        if (! in_array($mode, ['table', 'yard3d'], true)) {
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
        if ((int) auth()->user()->rol_id === 5) {
            $this->pypMode = true;
        }
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

        $assignedCounts = $this->pypMode
            ? $this->containersQuery($branch->id)->reorder()->select('b.id as block_id', 'p.id as patio_id')
                ->selectRaw('COUNT(DISTINCT c.id) as total')->groupBy('b.id', 'p.id')->get()
            : collect();
        if ($this->pypMode) {
            foreach ($patios as $patio) {
                $patio->assigned_count = (int) $assignedCounts->where('patio_id', $patio->id)->sum('total');
            }
        }

        $selectedPatio = $this->resolveSelectedPatio($patios);
        $blocks = $selectedPatio
            ? Bloque::active()
                ->where('patio_id', $selectedPatio->id)
                ->when(! $this->pypMode, fn ($query) => $query->withCount('contenedoresActivos'))
                ->orderBy('numero')
                ->get()
            : collect();
        if ($this->pypMode) {
            foreach ($blocks as $block) {
                $block->contenedores_activos_count = (int) $assignedCounts->where('block_id', $block->id)->sum('total');
            }
        }
        $selectedBlock = $this->resolveSelectedBlock($blocks);
        $hasActiveBlocks = $patios->isNotEmpty() && Bloque::active()->whereIn('patio_id', $patios->modelKeys())->exists();

        $baseQuery = $this->containersQuery($branch->id);
        $globalSearch = $this->pypMode && $this->viewMode === 'table' && trim($this->search) !== '';

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
        } elseif ($this->pypMode) {
            foreach ($containers->items() as $container) {
                $container->audit_actions = $this->auditActions($container);
            }
        } elseif (in_array((int) auth()->user()->rol_id, [1, 2, 3], true)) {
            foreach ($containers->items() as $container) {
                $container->repair_history_url = $this->containerHistoryUrl((int) $container->id);
            }
        }

        return view('livewire.containers.inventory-table', [
            'patios' => $patios,
            'branchName' => $branch->nombre,
            'externalCompanyName' => (int) auth()->user()->rol_id === 5
                ? DB::table('external_audit_companies')->where('id', auth()->user()->external_audit_company_id)->value('name') : null,
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
            'yardScene' => $selectedPatio ? $this->yardSceneData($branch->id, $selectedPatio->id, $selectedBlock?->id) : null,
        ]);
    }

    private function normalizeTableState(): void
    {
        $this->search = mb_substr($this->search, 0, 255);
        $this->viewMode = in_array($this->viewMode, ['table', 'yard3d'], true) ? $this->viewMode : 'table';
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

        $onlySelectedBlock = $selectedBlockId > 0 && ! $this->maintenanceMode && ! $this->pypMode;
        $blocks = Bloque::active()
            ->where('patio_id', $patioId)
            ->when($onlySelectedBlock, fn ($query) => $query->whereKey($selectedBlockId))
            ->withCount('contenedoresActivos')
            ->orderBy('numero')
            ->get();

        $containersQuery = $this->containersQuery($branchId)
            ->where('p.id', $patioId)
            ->when($onlySelectedBlock, fn ($query) => $query->where('b.id', $selectedBlockId))
            ->orderBy('b.numero')
            ->orderBy('c.posicion');

        $containers = $containersQuery
            ->get()
            ->groupBy('bloque_id');

        // External accounts receive only slot coordinates for equipment outside their scope.
        $occupancy = $this->pypMode
            ? DB::table('contenedores as c')->join('bloques as b', 'b.id', '=', 'c.bloque_id')
                ->join('patios as p', 'p.id', '=', 'b.patio_id')
                ->where('p.sucursal_id', $branchId)->where('p.id', $patioId)
                ->where('b.estado_id', 1)->where('c.estado_id', 1)
                ->select('c.bloque_id', 'c.posicion')->orderBy('c.posicion')->get()->groupBy('bloque_id')
            : collect();

        return [
            'selected_block_id' => $selectedBlockId,
            'fit_to_viewport' => $this->maintenanceMode || $this->pypMode,
            'has_visual_filters' => $hasVisualFilters,
            'blocks' => $blocks->map(fn (Bloque $block): array => [
                'id' => $block->id,
                'numero' => $block->numero,
                'capacidad' => (int) $block->capacidad_maxima,
                'ocupados' => $this->pypMode ? $containers->get($block->id, collect())->count() : (int) $block->contenedores_activos_count,
                'selected' => $selectedBlockId === (int) $block->id,
                'containers' => ($this->pypMode
                    ? $occupancy->get($block->id, collect())->map(function (object $slot) use ($containers, $block): object {
                        return $containers->get($block->id, collect())->firstWhere('posicion', $slot->posicion)
                            ?? (object) ['anonymous' => true, 'posicion' => (int) $slot->posicion];
                    })
                    : $containers->get($block->id, collect()))->map(fn (object $container): array => isset($container->anonymous) ? [
                        'anonymous' => true,
                        'posicion' => (int) $container->posicion,
                        'matches' => false,
                    ] : [
                        'id' => $container->id,
                        'nombre' => $container->nombre,
                        'posicion' => (int) $container->posicion,
                        'estatus_unidad' => $container->estatus,
                        'estatus_box' => $container->estatus_box,
                        'estatus_ipsa' => $container->estatus_ipsa,
                        'estatus_ipsa_id' => (int) $container->estatus_ipsa_id,
                        'pyp' => $container->estatus_pyp,
                        'matches' => ! $hasVisualFilters || $matchingIds->has((int) $container->id),
                        'actions' => $this->pypMode ? $this->auditActions($container) : ($this->maintenanceMode ? $this->maintenanceActions($container, $patioId, (int) $block->id) : ($canOperate ? [
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
                            'history' => $this->containerHistoryUrl((int) $container->id),
                        ] : [])),
                        'url' => $this->pypMode ? $this->auditActions($container)['info'] : route($this->maintenanceMode ? 'maintenance.container' : 'containers.show', [
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

    private function containerHistoryUrl(int $containerId): string
    {
        $back = [];
        foreach ($this->queryString as $property => $options) {
            if ($this->{$property} !== $options['except']) {
                $back[$options['as'] ?? $property] = $this->{$property};
            }
        }

        return route('corrections.container-history', ['container' => $containerId, 'return_to' => route('containers.index', $back, false)]);
    }

    private function auditActions(object $container): array
    {
        $back = [];
        foreach ($this->queryString as $property => $options) {
            if ($this->{$property} !== $options['except']) {
                $back[$options['as'] ?? $property] = $this->{$property};
            }
        }
        $params = ['container' => $container->id, 'return_to' => route('pyp-audits.index', $back, false)];
        $canAudit = (bool) $container->ipsa_available && (bool) $container->audit_work_ready;

        return [
            'info' => route('pyp-audits.information', $params),
            'history' => route('pyp-audits.history', $params),
            'maintenance' => $canAudit ? route('pyp-audits.begin', $params) : null,
            'maintenance_method' => 'POST',
            'audit_version' => (int) $container->audit_revision,
            'maintenance_label' => $container->estatus_pyp === 'En revision' ? 'Continuar auditoría' : 'Auditar',
        ];
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
            && (strtoupper(trim((string) $container->estatus_ipsa)) === 'OOS'
                || ($container->source_audit_event_id && strtoupper(trim((string) $container->estatus_ipsa)) === 'DISPONIBLE'))
            && (int) $container->ipsa_status_active === self::ACTIVE;
        $canCorrect = $container->maintenance_job_status === 'returned_for_repair'
            && (int) $container->maintenance_mechanic_id === (int) auth()->id();
        $returnUrl = route('maintenance.orders', $back, false);
        $maintenanceUrl = null;
        $label = 'Reparar';
        if ($canContinue) {
            $maintenanceUrl = route('maintenance.show', ['job' => $container->maintenance_job_id, 'back' => $back]);
            $label = 'Continuar reparación';
        } elseif ($canCorrect) {
            $maintenanceUrl = route('corrections.show', ['job' => $container->maintenance_job_id, 'return_to' => $returnUrl]);
            $label = 'Atender corrección';
        } elseif (! $container->maintenance_job_id && $container->maintenance_can_start) {
            $maintenanceUrl = route('maintenance.create', ['container_id' => $container->id] + $params);
        }

        return [
            'info' => route('maintenance.container', ['container' => $container->id] + $params),
            'maintenance' => $maintenanceUrl,
            'maintenance_label' => $label,
            'history' => $container->maintenance_history_job_id
                ? route('corrections.history', ['job' => $container->maintenance_history_job_id, 'return_to' => $returnUrl]) : null,
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
                    'mj.mechanic_id as maintenance_mechanic_id', 'mj.source_audit_event_id', 'ei.estado_id as ipsa_status_active',
                    'mechanic.username as mechanic_name', 'ic.audit_company_name'])
                ->selectSub(function ($history) use ($branchId): void {
                    $history->from('maintenance_jobs as history')->select('history.id')
                        ->whereColumn('history.container_id', 'c.id')->where('history.branch_id', $branchId)
                        ->where('history.mechanic_id', auth()->id())->orderByDesc('history.id')->limit(1);
                }, 'maintenance_history_job_id')
                ->selectRaw($this->maintenanceStatusSql().' as maintenance_status')
                ->selectSub(DB::query()->fromSub(app(MaintenanceWorkflow::class)->startableContainers($branchId)->select('c.id'), 'startable')
                    ->selectRaw('COUNT(*)')->whereColumn('startable.id', 'c.id'), 'maintenance_can_start')
                ->selectRaw(ContainerAuditAssignment::statusSql().' as audit_status')
                ->selectRaw(ContainerAuditAssignment::noticeSql().' as audit_assignment_notice')
                ->selectRaw('NULL as estatus_pyp, NULL as informacion_adicional, NULL as es_asignado');
        }

        $query->leftJoin('pyp_auditoria as pa', function ($join): void {
            $join->on('c.id', '=', 'pa.contenedor_id')->where('pa.estado_id', self::ACTIVE);
        })->addSelect(['pa.estatus_pyp', 'pa.informacion_adicional', 'pa.contenedor_id as es_asignado']);

        if ($this->pypMode) {
            PypAuditService::withRevision($query);
            $query->selectRaw("CASE WHEN UPPER(TRIM(ei.nombre)) = 'DISPONIBLE' AND ei.estado_id = 1 THEN 1 ELSE 0 END as ipsa_available");
            $query->selectSub(function ($work): void {
                MaintenanceWorkflow::scopeCurrentIngress($work->from('maintenance_jobs as j')->selectRaw('CASE WHEN COUNT(*) = 0 THEN 1 ELSE 0 END'))
                    ->whereIn('j.status', MaintenanceWorkflow::OPEN_STATES);
            }, 'audit_work_ready');
        }

        app(ContainerAuditAssignment::class)->joinCurrent($query);

        return app(ExternalAuditAccess::class)->scope($query, auth()->user(), $branchId);
    }

    private function maintenanceStatusSql(): string
    {
        return "CASE WHEN mj.status = 'in_progress' THEN 'En reparacion' WHEN mj.status = 'returned_for_repair' THEN 'Devuelto a reparacion' WHEN mj.status IN ('pending_review', 'completed') THEN 'Reparado' WHEN (UPPER(TRIM(ei.nombre)) = 'OOS' AND ei.estado_id = 1) OR (pa.estatus_pyp = 'Rechazado' AND pa.ingress_cycle_id = c.current_ingress_cycle_id) THEN 'Por atender' ELSE NULL END";
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
