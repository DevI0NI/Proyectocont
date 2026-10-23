<div class="containers-livewire-shell @if($maintenanceMode || $pypMode) maintenance-inventory @endif">
    @php
        $roleId = (int) auth()->user()->rol_id;
        $showPyp = true;
        $showPypActions = ! $maintenanceMode && ($pypMode || $roleId === 5);
        $showInternalActions = ! $pypMode && in_array($roleId, [1, 2, 3], true);
        $missingLayout = $patios->isEmpty() || ! $hasActiveBlocks || (! $globalSearch && $selectedPatio && $blocks->isEmpty());
        $canConfigureLayout = ! $pypMode && in_array($roleId, [1, 2], true) && auth()->user()->hasPermission('yard-layout.manage');
        $layoutTitle = $patios->isEmpty() ? 'Esta sucursal no tiene patios activos'
            : ($selectedPatio ? 'Este patio no tiene bloques activos' : 'Esta sucursal no tiene bloques activos');
        $layoutDescription = $patios->isEmpty()
            ? 'No hay patios habilitados en '.$branchName.'. Se necesita al menos un patio con bloques activos para registrar contenedores.'
            : ($selectedPatio ? 'No hay bloques habilitados en '.$selectedPatio->nombre.'.' : 'Los patios de '.$branchName.' aun no tienen bloques habilitados.');
        $columnCount = 6 + (int) $showPyp + (int) $maintenanceMode + (int) ($showInternalActions || $showPypActions || $maintenanceMode) + (int) $globalSearch;
        $ingressReturnParams = array_filter([
            'search' => $search,
            'per_page' => $perPage !== 10 ? $perPage : null,
            'page' => $page !== 1 ? $page : null,
            'sort' => $sort !== 'posicion' ? $sort : null,
            'order' => $order !== 'desc' ? $order : null,
            'patio_id' => $patioId,
            'bloque_id' => $blockId,
            'posicion' => $positionFilter,
            'nombre' => $nameFilter,
            'estatus_unidad' => $unitStatusFilter,
            'estatus_box' => $boxStatusFilter,
            'estatus_ipsa' => $ipsaStatusFilter,
            'estatus_pyp' => $pypStatusFilter,
            'fecha_desde' => $dateFrom,
            'fecha_hasta' => $dateTo,
            'vista' => $viewMode !== 'table' ? $viewMode : null,
        ], static fn ($value) => $value !== null && $value !== '');
        $badgeClass = function (?string $value, string $type = 'generic'): string {
            $value = strtoupper(\Illuminate\Support\Str::ascii(trim((string) $value)));
            $base = 'status-badge';

            if ($value === '') {
                return $base.' badge-neutral';
            }

            return match ($type) {
                'unit' => match ($value) {
                    'OK', 'DISPONIBLE', 'OPERATIVO' => $base.' badge-success',
                    'DMG', 'DAMAGE', 'DANADO', 'DANADO/A', 'PENDIENTE PTI' => $base.' badge-warning',
                    default => $base.' badge-neutral',
                },
                'box' => match ($value) {
                    'OK', 'OPERATIVO' => $base.' badge-success',
                    'DMG', 'MAL', 'DANADO', 'DANADO/A' => $base.' badge-warning',
                    default => $base.' badge-neutral',
                },
                'ipsa' => match ($value) {
                    'DISPONIBLE', 'DISP' => $base.' badge-success',
                    'OOS', 'REPARACION' => $base.' badge-danger',
                    'PENDIENTE', 'PEND' => $base.' badge-warning',
                    default => $base.' badge-neutral',
                },
                'pyp' => match ($value) {
                    'APROBADO' => $base.' badge-success',
                    'RECHAZADO' => $base.' badge-danger',
                    'EN REVISION' => $base.' badge-warning',
                    default => $base.' badge-neutral',
                },
                default => $base.' badge-neutral',
            };
        };
    @endphp

    <section class="panel-card containers-module">
        <div class="containers-module-head">
            <div class="view-header-copy">
                <h1>{{ $maintenanceMode ? 'Mantenimiento' : ($pypMode ? 'Auditoría externa' : 'Configuracion de Bloques') }}</h1>
                <p>{{ $maintenanceMode ? 'Contenedores y estados tecnicos por patio y bloque.' : ($pypMode ? $externalCompanyName.' · '.$branchName : 'Consulta ocupacion, recorre bloques por patio y navega a los movimientos del inventario interno.') }}</p>
            </div>
        </div>

        <div class="containers-toolbar">
            <div class="containers-search">
                <input type="search" maxlength="255" placeholder="{{ $viewMode === 'yard3d' ? 'Buscar contenedor en el patio...' : ($pypMode ? 'Buscar número en toda la sucursal...' : 'Buscar en tabla...') }}" aria-label="Buscar contenedor" autocomplete="off" wire:model.live.debounce.300ms="search">
            </div>

            <div class="containers-filters">
                <div class="container-choice topbar-sucursal-form">
                    <span class="topbar-sucursal-label">Patio</span>
                    <div class="topbar-dropdown container-dropdown">
                        <button type="button" class="topbar-dropdown-trigger container-dropdown-trigger" data-container-dropdown-trigger aria-haspopup="listbox" aria-expanded="false" @disabled($patios->isEmpty())>
                            <span>{{ $selectedPatio?->nombre ?? ($patios->isEmpty() ? 'Sin patios' : 'Seleccionar') }}{{ $pypMode && $selectedPatio ? ' - '.$selectedPatio->assigned_count.' asig.' : '' }}</span>
                            <svg class="dropdown-caret" viewBox="0 0 20 20" aria-hidden="true">
                                <path d="M5.75 7.5 10 11.75 14.25 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <div class="topbar-dropdown-menu branch-menu container-dropdown-menu" role="listbox">
                        @foreach ($patios as $patio)
                            <button
                                type="button"
                                class="topbar-dropdown-option @if($selectedPatio?->id === $patio->id) selected @endif"
                                wire:click="selectPatio({{ (int) $patio->id }})"
                                role="option"
                                aria-selected="{{ $selectedPatio?->id === $patio->id ? 'true' : 'false' }}"
                            >
                                {{ $patio->nombre }}{{ $pypMode ? ' - '.$patio->assigned_count.' asig.' : '' }}
                            </button>
                        @endforeach
                        </div>
                    </div>
                </div>

                @if ($selectedPatio || $blocks->isNotEmpty())
                    <div class="container-choice topbar-sucursal-form">
                        <span class="topbar-sucursal-label">Bloque</span>
                        <div class="topbar-dropdown container-dropdown">
                            <button type="button" class="topbar-dropdown-trigger container-dropdown-trigger" data-container-dropdown-trigger aria-haspopup="listbox" aria-expanded="false" @disabled($blocks->isEmpty())>
                                <span>
                                    @if ($selectedBlock)
                                        Bloque {{ $selectedBlock->numero }} - {{ $capacityCurrent }}{{ $pypMode ? ' asig.' : '/'.$capacityMax }}
                                    @else
                                        {{ $blocks->isEmpty() ? 'Sin bloques' : 'Seleccionar' }}
                                    @endif
                                </span>
                                <svg class="dropdown-caret" viewBox="0 0 20 20" aria-hidden="true">
                                    <path d="M5.75 7.5 10 11.75 14.25 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                            <div class="topbar-dropdown-menu branch-menu container-dropdown-menu" role="listbox">
                            @foreach ($blocks as $block)
                                <button
                                    type="button"
                                    class="topbar-dropdown-option @if($selectedBlock?->id === $block->id) selected @endif"
                                    wire:click="selectBlock({{ (int) $block->id }})"
                                    role="option"
                                    aria-selected="{{ $selectedBlock?->id === $block->id ? 'true' : 'false' }}"
                                >
                                    Bloque {{ $block->numero }} - {{ $selectedBlock?->id === $block->id ? $capacityCurrent : $block->contenedores_activos_count }}{{ $pypMode ? ' asig.' : '/'.$block->capacidad_maxima }}
                                </button>
                            @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                @if ($viewMode === 'table')
                    <div class="container-choice topbar-sucursal-form compact">
                        <span class="topbar-sucursal-label">Filas</span>
                        <div class="topbar-dropdown container-dropdown">
                            <button type="button" class="topbar-dropdown-trigger container-dropdown-trigger" data-container-dropdown-trigger aria-haspopup="listbox" aria-expanded="false">
                                <span>{{ $perPage }}</span>
                                <svg class="dropdown-caret" viewBox="0 0 20 20" aria-hidden="true">
                                    <path d="M5.75 7.5 10 11.75 14.25 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                            <div class="topbar-dropdown-menu branch-menu container-dropdown-menu" role="listbox">
                            @foreach ($perPageOptions as $option)
                                <button
                                    type="button"
                                    class="topbar-dropdown-option @if($perPage === $option) selected @endif"
                                    wire:click="selectPerPage({{ (int) $option }})"
                                    role="option"
                                    aria-selected="{{ $perPage === $option ? 'true' : 'false' }}"
                                >
                                    {{ $option }}
                                </button>
                            @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                <button type="button" class="container-clear-button" wire:click="clearFilters">Limpiar</button>

                @if ($showInternalActions && auth()->user()->hasPermission('containers.create'))
                    @if($hasActiveBlocks)
                    <a href="{{ route('ingresos.create', $ingressReturnParams) }}" class="container-new-button">
                        <img src="{{ asset('imagenes/nuevo.png') }}" alt="">
                        <span>Nuevo</span>
                    </a>
                    @else
                    <button type="button" class="container-new-button" disabled title="Se necesita un patio con bloques activos para registrar un ingreso.">
                        <img src="{{ asset('imagenes/nuevo.png') }}" alt="">
                        <span>Nuevo</span>
                    </button>
                    @endif
                @endif
            </div>
        </div>

        <div class="containers-view-switch" role="group" aria-label="Cambiar vista">
            <button type="button" class="@if($viewMode === 'table') active @endif" wire:click="setViewMode('table')">Tabla</button>
            <button type="button" class="@if($viewMode === 'yard3d') active @endif" wire:click="setViewMode('yard3d')">Vista 3D</button>
        </div>

        <div class="containers-table-panel">
            <div class="containers-table-heading">
                <div>
                    <h2>{{ $globalSearch ? 'Resultados en la sucursal' : ($viewMode === 'yard3d' ? 'Vista 3D del patio' : ($pypMode ? 'Contenedores asignados' : 'Inventario del Bloque')) }}</h2>
                    <p>
                        @if ($missingLayout)
                            {{ $layoutTitle }}.
                        @elseif ($globalSearch)
                            {{ number_format($pagination['total']) }} contenedores encontrados.
                        @elseif ($viewMode === 'yard3d' && $selectedPatio)
                            @if ($pypMode)
                                {{ number_format($selectedPatio->assigned_count) }} contenedores asignados en {{ $selectedPatio->nombre }}.
                            @else
                                Patio {{ $selectedPatio->nombre }} con bloques y contenedores activos.
                            @endif
                        @elseif ($selectedBlock)
                            {{ number_format($pagination['total']) }} de {{ number_format($capacityCurrent) }} contenedores visibles.
                        @elseif ($selectedPatio)
                            Selecciona un bloque para ver su inventario.
                        @else
                            Selecciona un patio para comenzar.
                        @endif
                    </p>
                </div>

                @if (($selectedBlock && $viewMode === 'table') || ($pypMode && $selectedPatio))
                    <div class="container-capacity-pill" aria-label="{{ $pypMode ? 'Contenedores asignados' : 'Ocupacion del bloque' }}">
                        @if ($pypMode)
                            <strong>{{ $viewMode === 'table' && $selectedBlock ? $capacityCurrent : $selectedPatio->assigned_count }}</strong>
                            <span>contenedores asignados</span>
                        @else
                            <strong>{{ $capacityCurrent }} / {{ $capacityMax }}</strong>
                            <span>{{ $occupancy }}% ocupado</span>
                        @endif
                    </div>
                @endif
            </div>

            @if ($missingLayout)
                <div class="containers-empty-state" role="status">
                    <div class="empty-state-icon" aria-hidden="true"><span></span><span></span></div>
                    <div>
                        <h3>{{ $layoutTitle }}</h3>
                        <p>{{ $layoutDescription }} {{ $canConfigureLayout ? 'La configuracion se administra en Patios y bloques.' : 'Solicita la configuracion al administrador de la sucursal.' }}</p>
                        @if($canConfigureLayout)
                            <a href="{{ route('yard-layout.index') }}" class="containers-layout-action">Configurar patios y bloques</a>
                        @elseif($selectedPatio && $hasActiveBlocks)
                            <p>Hay bloques activos en otros patios de esta sucursal.</p>
                        @endif
                    </div>
                </div>
            @elseif (! $selectedPatio && ! $globalSearch)
                <div class="containers-empty-state">
                    <div class="empty-state-icon" aria-hidden="true">
                        <span></span>
                        <span></span>
                    </div>
                    <div>
                        <h3>Selecciona un patio para comenzar</h3>
                        <p>El inventario del bloque aparecera aqui cuando elijas un patio y luego un bloque.</p>
                    </div>
                </div>
            @elseif ($viewMode === 'yard3d')
                <div class="containers-yard3d-panel">
                    <div class="containers-yard3d-stage">
                        <canvas
                            class="containers-yard3d"
                            data-yard-scene='@json($yardScene)'
                            aria-label="Vista 3D del patio seleccionado"
                        ></canvas>
                        <div class="containers-yard3d-help" aria-hidden="true">
                            <div class="help-desktop">
                                <strong>Controles</strong>
                                <span>Click izq. + arrastrar: rotar</span>
                                <span>Click der. + arrastrar: mover</span>
                                <span>Rueda: acercar / alejar</span>
                                <span>Click en contenedor: ver mas informacion</span>
                            </div>
                            <div class="help-touch">
                                <strong>Controles tactiles</strong>
                                <span>1 dedo + arrastrar: rotar</span>
                                <span>Pellizca con 2 dedos: zoom</span>
                                <span>2 dedos + arrastrar: mover</span>
                                <span>Toca un contenedor: ver informacion</span>
                            </div>
                        </div>
                        <div class="containers-yard3d-tooltip" hidden></div>
                        <div class="containers-yard3d-actions" role="dialog" aria-label="Opciones del contenedor" hidden>
                            <button type="button" class="containers-yard3d-actions-close" aria-label="Cerrar opciones">×</button>
                            <span>Contenedor</span>
                            <strong data-yard-action-name></strong>
                            <small data-yard-action-meta></small>
                            <div class="containers-yard3d-actions-list">
                                <a data-yard-action-info href="#">{{ $pypMode ? 'Ver auditoría' : 'Informacion' }}</a>
                                @if($maintenanceMode)
                                <a data-yard-action-maintenance href="#" hidden>Reparar</a>
                                @elseif (! $pypMode)
                                <a data-yard-action-ticket href="#">Constancia ingreso</a>
                                <a data-yard-action-transfer href="#">Trasladar</a>
                                <a data-yard-action-dispatch href="#">Despachar</a>
                                @endif
                                @if(! $pypMode)<a data-yard-action-history href="#" hidden>Reparaciones</a>@endif
                            </div>
                        </div>
                    </div>
                    <div class="containers-yard3d-legend">
                        <span><i class="available"></i>Disponible</span>
                        <span><i class="pending"></i>Pendiente</span>
                        <span><i class="oos"></i>OOS / reparacion</span>
                        <span><i class="other"></i>Otros</span>
                        @if ($pypMode)
                            <span><i class="other"></i>Ocupación sin acceso</span>
                        @endif
                    </div>
                </div>
            @elseif (! $selectedBlock && ! $globalSearch)
                <div class="containers-empty-state">
                    <div class="empty-state-icon" aria-hidden="true">
                        <span></span>
                        <span></span>
                    </div>
                    <div>
                        <h3>Selecciona un bloque para ver la tabla</h3>
                        <p>Tambien puedes cambiar a Vista 3D para ver el patio completo sin elegir un bloque.</p>
                    </div>
                </div>
            @else
                <div class="containers-table-wrap">
                    <table class="containers-table">
                        <thead>
                            <tr>
                                @if ($showInternalActions || $showPypActions || $maintenanceMode)
                                    <th>Opciones</th>
                                @endif
                                <th class="containers-header-cell">
                                    <button type="button" class="containers-column-trigger" data-containers-menu-trigger="menu-position">
                                        <span>Posicion</span>
                                        <i class="containers-sort-mark @if($sort === 'posicion') is-active {{ $order }} @endif" aria-hidden="true"></i>
                                    </button>
                                    <div class="containers-header-menu" id="menu-position" hidden>
                                        <strong>Posicion</strong>
                                        <button type="button" class="@if($sort === 'posicion' && $order === 'asc') selected @endif" wire:click="setSort('posicion','asc')">Menor primero</button>
                                        <button type="button" class="@if($sort === 'posicion' && $order === 'desc') selected @endif" wire:click="setSort('posicion','desc')">Mayor primero</button>
                                        <input class="@if($positionFilter !== '') selected @endif" type="number" min="1" placeholder="Filtrar posicion" wire:model.live.debounce.300ms="positionFilter">
                                    </div>
                                </th>
                                <th class="containers-header-cell">
                                    <button type="button" class="containers-column-trigger" data-containers-menu-trigger="menu-name">
                                        <span>Nombre</span>
                                        <i class="containers-sort-mark @if($sort === 'nombre') is-active {{ $order }} @endif" aria-hidden="true"></i>
                                    </button>
                                    <div class="containers-header-menu" id="menu-name" hidden>
                                        <strong>Nombre</strong>
                                        <button type="button" class="@if($sort === 'nombre' && $order === 'asc') selected @endif" wire:click="setSort('nombre','asc')">A - Z</button>
                                        <button type="button" class="@if($sort === 'nombre' && $order === 'desc') selected @endif" wire:click="setSort('nombre','desc')">Z - A</button>
                                        <input class="@if($nameFilter !== '') selected @endif" type="search" maxlength="255" placeholder="Filtrar contenedor" wire:model.live.debounce.300ms="nameFilter">
                                    </div>
                                </th>
                                @if ($globalSearch)
                                    <th>Ubicacion</th>
                                @endif
                                <th class="containers-header-cell">
                                    <button type="button" class="containers-column-trigger" data-containers-menu-trigger="menu-unit">
                                        <span>Estatus Unidad</span>
                                    </button>
                                    <div class="containers-header-menu" id="menu-unit" hidden>
                                        <strong>Estatus Unidad</strong>
                                        @include('livewire.containers.status-filter', ['filter' => 'unitStatusFilter', 'selected' => $unitStatusFilter, 'options' => $statusOptions['unit']])
                                    </div>
                                </th>
                                <th class="containers-header-cell">
                                    <button type="button" class="containers-column-trigger" data-containers-menu-trigger="menu-box">
                                        <span>Estatus Box</span>
                                    </button>
                                    <div class="containers-header-menu" id="menu-box" hidden>
                                        <strong>Estatus Box</strong>
                                        @include('livewire.containers.status-filter', ['filter' => 'boxStatusFilter', 'selected' => $boxStatusFilter, 'options' => $statusOptions['box']])
                                    </div>
                                </th>
                                <th class="containers-header-cell">
                                    <button type="button" class="containers-column-trigger" data-containers-menu-trigger="menu-ipsa">
                                        <span>Estatus IPSA</span>
                                    </button>
                                    <div class="containers-header-menu" id="menu-ipsa" hidden>
                                        <strong>Estatus IPSA</strong>
                                        @include('livewire.containers.status-filter', ['filter' => 'ipsaStatusFilter', 'selected' => $ipsaStatusFilter, 'options' => $statusOptions['ipsa']])
                                    </div>
                                </th>
                                <th class="containers-header-cell">
                                    <button type="button" class="containers-column-trigger" data-containers-menu-trigger="menu-date">
                                        <span>Fecha Ingreso</span>
                                        <i class="containers-sort-mark @if($sort === 'fecha_ingreso') is-active {{ $order }} @endif" aria-hidden="true"></i>
                                    </button>
                                    <div class="containers-header-menu" id="menu-date" hidden>
                                        <strong>Fecha Ingreso</strong>
                                        <button type="button" class="@if($sort === 'fecha_ingreso' && $order === 'asc') selected @endif" wire:click="setSort('fecha_ingreso','asc')">Mas antigua</button>
                                        <button type="button" class="@if($sort === 'fecha_ingreso' && $order === 'desc') selected @endif" wire:click="setSort('fecha_ingreso','desc')">Mas reciente</button>
                                        <label>Desde<input class="@if($dateFrom !== '') selected @endif" type="date" wire:model.live="dateFrom"></label>
                                        <label>Hasta<input class="@if($dateTo !== '') selected @endif" type="date" wire:model.live="dateTo"></label>
                                    </div>
                                </th>
                                @if($maintenanceMode)
                                    <th class="containers-header-cell">
                                        <button type="button" class="containers-column-trigger" data-containers-menu-trigger="menu-maintenance"><span>Mantenimiento</span><i class="containers-sort-mark @if($sort === 'mantenimiento') is-active {{ $order }} @endif" aria-hidden="true"></i></button>
                                        <div class="containers-header-menu" id="menu-maintenance" hidden>
                                            <strong>Mantenimiento</strong>
                                            <button type="button" wire:click="setSort('mantenimiento', 'asc')">Ordenar A-Z</button>
                                            <button type="button" wire:click="setSort('mantenimiento', 'desc')">Ordenar Z-A</button>
                                            @include('livewire.containers.status-filter', ['filter' => 'maintenanceStatusFilter', 'selected' => $maintenanceStatusFilter, 'options' => ['Por atender', 'En reparacion', 'Reparado', 'Devuelto a reparacion']])
                                        </div>
                                    </th>
                                @endif
                                @if ($showPyp)
                                    <th class="containers-header-cell">
                                        <button type="button" class="containers-column-trigger" data-containers-menu-trigger="menu-pyp">
                                            <span>Auditoría externa</span>
                                        </button>
                                        <div class="containers-header-menu" id="menu-pyp" hidden>
                                            <strong>Auditoría externa</strong>
                                            @include('livewire.containers.status-filter', ['filter' => 'pypStatusFilter', 'selected' => $pypStatusFilter, 'options' => $statusOptions['pyp']])
                                        </div>
                                    </th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($containers as $container)
                                @php
                                    $isRestrictedRole = $roleId === 5;
                                    $isAvailableForPyp = (int) $container->estatus_ipsa_id === 1;
                                    $isAssigned = (bool) $container->es_asignado;
                                    $isCensored = $isRestrictedRole && ! $isAvailableForPyp && ! $isAssigned;
                                @endphp
                                <tr wire:key="container-row-{{ $container->id }}">
                                    @if ($showInternalActions || $showPypActions || $maintenanceMode)
                                        <td data-label="Opciones" class="options-cell">
                                            <div class="row-options">
                                                <button class="options-trigger" type="button" aria-haspopup="true" aria-expanded="false">
                                                    Opciones
                                                    <svg class="dropdown-caret" viewBox="0 0 20 20" aria-hidden="true">
                                                        <path d="M5.75 7.5 10 11.75 14.25 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                </button>
                                                <div class="options-menu">
                                                    @if ($maintenanceMode)
                                                        <a href="{{ $container->maintenance_actions['info'] }}">Informacion</a>
                                                        @if($container->maintenance_actions['maintenance'])
                                                            <a href="{{ $container->maintenance_actions['maintenance'] }}">{{ $container->maintenance_actions['maintenance_label'] }}</a>
                                                        @endif
                                                        @if($container->maintenance_actions['history'])
                                                            <a href="{{ $container->maintenance_actions['history'] }}">Reparaciones</a>
                                                        @endif
                                                    @endif
                                                    @if ($showInternalActions)
                                                        <a href="{{ $container->repair_history_url }}">Reparaciones</a>
                                                        <a href="{{ route('containers.show', ['container' => $container->id, 'patio_id' => $selectedPatio?->id, 'bloque_id' => $selectedBlock?->id]) }}">
                                                            Informacion
                                                        </a>
                                                        <a href="{{ route('documents.ingress.ticket', ['contenedor_id' => $container->id, 'patio_id' => $selectedPatio?->id, 'bloque_id' => $selectedBlock?->id]) }}">
                                                            Constancia ingreso
                                                        </a>
                                                        @if(auth()->user()->hasPermission('transfers.operate'))<a href="{{ route('transfers.create', ['contenedor_id' => $container->id]) }}">Trasladar</a>@endif
                                                        @if(auth()->user()->hasPermission('dispatches.operate'))<a href="{{ route('dispatches.create', ['contenedor_id' => $container->id]) }}">Despachar</a>@endif
                                                    @endif
                                                    @if ($showPypActions)
                                                        @if ($isCensored)
                                                            <span>Bloqueado</span>
                                                        @elseif ((auth()->user()->hasPermission('pyp.assign') || $roleId === 4) && (int) $container->estatus_ipsa_id === 1 && ! $container->es_asignado)
                                                            <button type="button" data-pyp-assign data-action="{{ route('pyp-audits.assign', ['container' => $container->id]) }}" data-code="{{ $container->nombre }}" data-patio="{{ $container->patio_id }}" data-bloque="{{ $container->bloque_id }}">Asignar PYP</button>
                                                        @elseif ($container->es_asignado && in_array($roleId, [4, 5], true))
                                                            <a href="{{ route('pyp-audits.edit', ['container' => $container->id, 'patio_id' => $container->patio_id, 'bloque_id' => $container->bloque_id]) }}">
                                                                Ver auditoría
                                                            </a>
                                                        @elseif ($container->es_asignado)
                                                            <span>Asignado</span>
                                                        @else
                                                            <span>Pendiente</span>
                                                        @endif
                                                        @if (! $isCensored && ($isAssigned || in_array($roleId, [1, 2, 3], true)))
                                                            <button type="button" data-pyp-info data-code="{{ $container->nombre }}" data-info="{{ $container->informacion_adicional ?? '' }}">Mas info</button>
                                                        @endif
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                    @endif
                                    <td data-label="Posicion"><span class="position-badge">{{ $container->posicion }}</span></td>
                                    <td data-label="Nombre"><span class="table-name">{{ $isCensored ? 'XXXXXXXXXXX' : $container->nombre }}</span></td>
                                    @if ($globalSearch)
                                        <td data-label="Ubicacion">{{ $container->patio_nombre }}, Bloque {{ $container->bloque_numero }}</td>
                                    @endif
                                    <td data-label="Estatus Unidad">
                                        <span class="{{ $isCensored ? 'status-badge badge-neutral' : $badgeClass($container->estatus, 'unit') }}">
                                            {{ $isCensored ? '---' : ($container->estatus ?? 'N/D') }}
                                        </span>
                                    </td>
                                    <td data-label="Estatus Box">
                                        <span class="{{ $isCensored ? 'status-badge badge-neutral' : $badgeClass($container->estatus_box, 'box') }}">
                                            {{ $isCensored ? '---' : ($container->estatus_box ?? 'N/D') }}
                                        </span>
                                    </td>
                                    <td data-label="Estatus IPSA">
                                        <span class="{{ $isCensored ? 'status-badge badge-danger' : $badgeClass($container->estatus_ipsa, 'ipsa') }}">
                                            {{ $isCensored ? 'No disponible' : ($container->estatus_ipsa ?? 'N/D') }}
                                        </span>
                                    </td>
                                    <td data-label="Fecha Ingreso">
                                        <span class="table-date">{{ $container->fecha_ingreso ? \Carbon\Carbon::parse($container->fecha_ingreso)->format('d/m/Y H:i') : 'N/D' }}</span>
                                    </td>
                                    @if($maintenanceMode)
                                        <td data-label="Mantenimiento">
                                            <div class="mw-inventory-work">
                                                @if($container->maintenance_status)
                                                    <span class="status-badge {{ $container->maintenance_status === 'Reparado' ? 'badge-success' : 'badge-warning' }}">{{ $container->maintenance_status }}</span>
                                                @else
                                                    <span class="table-muted" aria-label="Sin mantenimiento pendiente">&mdash;</span>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                    @if ($showPyp)
                                        <td data-label="Auditoría externa">
                                            <div class="mw-inventory-audit">
                                                @if(!$isCensored && !$container->audit_company_name)<span class="mw-auditor-name">Sin auditora asignada</span>@endif
                                                @if($container->audit_status && !$isCensored)
                                                    <span class="{{ $badgeClass($container->audit_status, 'pyp') }}">{{ $container->audit_status }}</span>
                                                @elseif($isCensored)
                                                    <span class="table-muted">---</span>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td class="table-empty-cell" colspan="{{ $columnCount }}">No hay contenedores para mostrar con estos filtros.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="containers-pagination">
                    <span>Pagina {{ $pagination['page'] }} de {{ $pagination['total_pages'] }} · {{ $pagination['total'] }} registros</span>
                    <div>
                        <button type="button" wire:click="goToPage(1)" @disabled($pagination['page'] <= 1)>&lt;&lt;</button>
                        <button type="button" wire:click="goToPage({{ $pagination['page'] - 1 }})" @disabled($pagination['page'] <= 1)>&lt;</button>
                        <button type="button" wire:click="goToPage({{ $pagination['page'] + 1 }})" @disabled($pagination['page'] >= $pagination['total_pages'])>&gt;</button>
                        <button type="button" wire:click="goToPage({{ $pagination['total_pages'] }})" @disabled($pagination['page'] >= $pagination['total_pages'])>&gt;&gt;</button>
                    </div>
                </div>
            @endif
        </div>
    </section>

    @if ($showPyp && ! $maintenanceMode)
        <dialog class="container-exit-dialog pyp-dialog" data-pyp-dialog="assign" aria-labelledby="pypAssignTitle">
            <span class="pyp-dialog-kicker">Auditoría externa</span>
            <h2 id="pypAssignTitle">Asignar contenedor</h2>
            <p>Se iniciara la auditoria del equipo <strong data-pyp-assign-code></strong>.</p>
            <form method="POST" data-pyp-assign-form>
                @csrf
                <input type="hidden" name="patio_id" data-pyp-assign-patio>
                <input type="hidden" name="bloque_id" data-pyp-assign-bloque>
                <div class="container-exit-dialog-actions">
                    <button type="button" class="button secondary" data-pyp-close>Cancelar</button>
                    <button type="submit" class="button primary">Si, asignar</button>
                </div>
            </form>
        </dialog>

        <dialog class="container-exit-dialog pyp-dialog" data-pyp-dialog="info" aria-labelledby="pypInfoTitle">
            <span class="pyp-dialog-kicker">Auditoría externa</span>
            <h2 id="pypInfoTitle">Informacion adicional</h2>
            <p class="pyp-dialog-container" data-pyp-info-code></p>
            <div class="pyp-dialog-info" data-pyp-info-text></div>
            <div class="container-exit-dialog-actions">
                <button type="button" class="button primary" data-pyp-close>Cerrar</button>
            </div>
        </dialog>
    @endif

    <script>
        (() => {
            if (window.__containersTableMenusReady) {
                return;
            }

            window.__containersTableMenusReady = true;

            const closeColumnMenus = (except = null) => {
                document.querySelectorAll('.containers-header-menu').forEach((menu) => {
                    if (menu !== except) {
                        menu.classList.remove('is-open');
                        window.setTimeout(() => {
                            if (!menu.classList.contains('is-open')) {
                                menu.hidden = true;
                            }
                        }, 160);
                    }
                });
            };

            const closeContainerDropdowns = (except = null) => {
                document.querySelectorAll('.container-dropdown.open').forEach((dropdown) => {
                    if (dropdown !== except) {
                        dropdown.classList.remove('open');
                        dropdown.querySelector('[data-container-dropdown-trigger]')?.setAttribute('aria-expanded', 'false');
                    }
                });
            };

            const closeOptionsMenus = (except = null) => {
                document.querySelectorAll('.row-options.open').forEach((menu) => {
                    if (menu !== except) {
                        menu.classList.remove('open');
                        menu.querySelector('.options-trigger')?.setAttribute('aria-expanded', 'false');
                    }
                });
            };

            document.addEventListener('click', (event) => {
                const assignTrigger = event.target.closest('[data-pyp-assign]');
                if (assignTrigger) {
                    const dialog = document.querySelector('[data-pyp-dialog="assign"]');
                    const form = dialog?.querySelector('[data-pyp-assign-form]');
                    if (dialog && form) {
                        form.action = assignTrigger.dataset.action;
                        form.querySelector('[data-pyp-assign-patio]').value = assignTrigger.dataset.patio;
                        form.querySelector('[data-pyp-assign-bloque]').value = assignTrigger.dataset.bloque;
                        dialog.querySelector('[data-pyp-assign-code]').textContent = assignTrigger.dataset.code;
                        closeOptionsMenus();
                        dialog.showModal();
                    }
                    return;
                }

                const infoTrigger = event.target.closest('[data-pyp-info]');
                if (infoTrigger) {
                    const dialog = document.querySelector('[data-pyp-dialog="info"]');
                    if (dialog) {
                        dialog.querySelector('[data-pyp-info-code]').textContent = infoTrigger.dataset.code;
                        dialog.querySelector('[data-pyp-info-text]').textContent = infoTrigger.dataset.info || 'No hay informacion adicional registrada.';
                        closeOptionsMenus();
                        dialog.showModal();
                    }
                    return;
                }

                const closeTrigger = event.target.closest('[data-pyp-close]');
                if (closeTrigger) {
                    closeTrigger.closest('dialog')?.close();
                    return;
                }

                const containerDropdownTrigger = event.target.closest('[data-container-dropdown-trigger]');
                if (containerDropdownTrigger) {
                    const dropdown = containerDropdownTrigger.closest('.container-dropdown');
                    const willOpen = !dropdown?.classList.contains('open');

                    closeColumnMenus(dropdown?.closest('.containers-header-menu'));
                    closeOptionsMenus();
                    closeContainerDropdowns(dropdown);
                    if (dropdown?.closest('.containers-status-filter')) {
                        const rect = containerDropdownTrigger.getBoundingClientRect();
                        const roomBelow = window.innerHeight - rect.bottom - 16;
                        const above = roomBelow < 120 && rect.top > roomBelow;
                        dropdown.classList.toggle('opens-above', above);
                        dropdown.querySelector('.container-dropdown-menu')?.style.setProperty('max-height', `${Math.max(80, Math.min(172, above ? rect.top - 16 : roomBelow))}px`);
                    }
                    dropdown?.classList.toggle('open', willOpen);
                    containerDropdownTrigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                    return;
                }

                if (event.target.closest('.container-dropdown-menu .topbar-dropdown-option')) {
                    closeContainerDropdowns();
                    return;
                }

                const columnTrigger = event.target.closest('[data-containers-menu-trigger]');
                if (columnTrigger) {
                    const menu = document.getElementById(columnTrigger.getAttribute('data-containers-menu-trigger'));
                    if (menu) {
                        const willOpen = menu.hidden;
                        const column = columnTrigger.closest('th') || columnTrigger;
                        const rect = column.getBoundingClientRect();
                        const width = Math.max(190, menu.offsetWidth || 190);
                        const left = Math.min(rect.left + (rect.width / 2) - (width / 2), window.innerWidth - width - 12);

                        closeColumnMenus(menu);
                        closeOptionsMenus();
                        closeContainerDropdowns();
                        menu.style.setProperty('--column-menu-left', `${Math.max(12, left)}px`);
                        menu.style.setProperty('--column-menu-top', `${rect.bottom + 8}px`);
                        if (willOpen) {
                            menu.hidden = false;
                            window.requestAnimationFrame(() => menu.classList.add('is-open'));
                        } else {
                            menu.classList.remove('is-open');
                            window.setTimeout(() => {
                                if (!menu.classList.contains('is-open')) {
                                    menu.hidden = true;
                                }
                            }, 160);
                        }
                    }
                    return;
                }

                const optionTrigger = event.target.closest('.options-trigger');
                if (optionTrigger) {
                    const wrapper = optionTrigger.closest('.row-options');
                    const dropdown = wrapper?.querySelector('.options-menu');
                    const rect = optionTrigger.getBoundingClientRect();
                    const width = 178;
                    const left = Math.min(rect.left, window.innerWidth - width - 12);
                    const willOpen = !wrapper?.classList.contains('open');

                    closeColumnMenus();
                    closeContainerDropdowns();
                    closeOptionsMenus(wrapper);
                    dropdown?.style.setProperty('--options-left', `${Math.max(12, left)}px`);
                    dropdown?.style.setProperty('--options-top', `${rect.bottom + 8}px`);
                    wrapper?.classList.toggle('open', willOpen);
                    optionTrigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                    return;
                }

                if (!event.target.closest('.containers-header-menu')) {
                    closeColumnMenus();
                }

                if (!event.target.closest('.container-dropdown')) {
                    closeContainerDropdowns();
                }

                if (!event.target.closest('.options-menu')) {
                    closeOptionsMenus();
                }
            });

            document.addEventListener('keydown', (event) => {
                const statusDropdown = event.target.closest?.('.containers-status-filter .container-dropdown');
                if (statusDropdown) {
                    const trigger = statusDropdown.querySelector('[data-container-dropdown-trigger]');
                    const options = [...statusDropdown.querySelectorAll('[role="option"]')];
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (!statusDropdown.classList.contains('open')) trigger.click();
                        const current = options.indexOf(document.activeElement);
                        const next = current < 0 ? (event.key === 'ArrowDown' ? 0 : options.length - 1) : (current + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
                        options[next]?.focus({ preventScroll: true });
                        options[next]?.scrollIntoView({ block: 'nearest' });
                        return;
                    }
                    if (event.key === 'Escape' && statusDropdown.classList.contains('open')) {
                        event.preventDefault();
                        closeContainerDropdowns();
                        trigger.focus();
                        return;
                    }
                }
                if (event.key === 'Escape') {
                    closeColumnMenus();
                    closeContainerDropdowns();
                    closeOptionsMenus();
                }
            });

            window.addEventListener('scroll', (event) => {
                if (event.target instanceof Element && event.target.closest('.containers-status-filter .container-dropdown-menu')) return;
                closeColumnMenus();
                closeOptionsMenus();
            }, true);
            window.addEventListener('resize', () => {
                closeColumnMenus();
                closeContainerDropdowns();
                closeOptionsMenus();
            });
        })();
    </script>
</div>
