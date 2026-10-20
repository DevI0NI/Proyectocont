<div class="admin-table-toolbar">
    <form method="GET" action="{{ url()->current() }}" data-admin-query @if($liveTable ?? false) data-admin-live-query @endif class="admin-table-search">
        @include('partials.administration-query-fields', ['exclude' => ['q']])
        <input type="search" name="q" value="{{ is_string(request('q')) ? request('q') : '' }}" maxlength="120" placeholder="Buscar en tabla" aria-label="Buscar en tabla">
        @unless($liveTable ?? false)
            <button type="submit" class="admin-action" title="Buscar" aria-label="Buscar"><span data-admin-icon="search" aria-hidden="true"></span></button>
        @endunless
    </form>
    @if(isset($branchState))
        <div class="admin-page-size admin-state-choice">
            <span class="topbar-sucursal-label">Estado</span>
            <div class="form-select-dropdown" data-admin-page-size>
                <button type="button" class="form-select-trigger" aria-expanded="false" aria-label="Estado de sucursales"><span>{{ ['activas' => 'Activas', 'inactivas' => 'Inactivas', 'todas' => 'Todas'][$branchState] }}</span><span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                <div class="form-select-menu">
                    @foreach(['activas' => 'Activas', 'inactivas' => 'Inactivas', 'todas' => 'Todas'] as $value => $label)
                        <a class="topbar-dropdown-option {{ $branchState === $value ? 'selected' : '' }}" data-admin-page href="{{ request()->fullUrlWithQuery(['estado' => $value, 'page' => 1]) }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
    @if(isset($userState))
        <div class="admin-page-size admin-state-choice">
            <span class="topbar-sucursal-label">Estado</span>
            <div class="form-select-dropdown" data-admin-page-size>
                <button type="button" class="form-select-trigger" aria-expanded="false" aria-label="Estado de usuarios" @disabled((int)auth()->user()->rol_id !== 1)><span>{{ ['activos' => 'Activos', 'inactivos' => 'Inactivos', 'todos' => 'Todos'][$userState] }}</span><span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                @if((int)auth()->user()->rol_id === 1)
                <div class="form-select-menu">
                    @foreach(['activos' => 'Activos', 'inactivos' => 'Inactivos', 'todos' => 'Todos'] as $value => $label)
                        <a class="topbar-dropdown-option {{ $userState === $value ? 'selected' : '' }}" data-admin-page href="{{ request()->fullUrlWithQuery(['estado' => $value, 'page' => 1]) }}">{{ $label }}</a>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
    @endif
    @if(isset($statusCategories))
        <div class="admin-page-size admin-state-choice">
            <span class="topbar-sucursal-label">Categoría</span>
            <div class="form-select-dropdown" data-admin-page-size>
                <button type="button" class="form-select-trigger" aria-expanded="false" aria-label="Categoría de estatus"><span>{{ $selectedType?->nombre ?? 'Sin categoría' }}</span><span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                <div class="form-select-menu">
                    @foreach($statusCategories as $category)
                        <a class="topbar-dropdown-option {{ (int)$selectedType?->id === (int)$category->id ? 'selected' : '' }}" data-admin-page href="{{ request()->fullUrlWithQuery(['tipo' => $category->nombre, 'page' => 1]) }}">{{ $category->nombre }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
    @if(isset($recordState))
        <div class="admin-page-size admin-state-choice">
            <span class="topbar-sucursal-label">Estado</span>
            <div class="form-select-dropdown" data-admin-page-size>
                <button type="button" class="form-select-trigger" aria-expanded="false" aria-label="{{ $recordStateLabel ?? 'Estado de tipos de unidad' }}"><span>{{ ['activos' => 'Activos', 'inactivos' => 'Inactivos', 'todos' => 'Todos'][$recordState] }}</span><span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                <div class="form-select-menu">
                    @foreach(['activos' => 'Activos', 'inactivos' => 'Inactivos', 'todos' => 'Todos'] as $value => $label)
                        <a class="topbar-dropdown-option {{ $recordState === $value ? 'selected' : '' }}" data-admin-page href="{{ request()->fullUrlWithQuery(['estado' => $value, 'page' => 1]) }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
    <div class="admin-page-size">
        <span class="topbar-sucursal-label">Filas</span>
        <div class="form-select-dropdown" data-admin-page-size>
            <button type="button" class="form-select-trigger" aria-expanded="false" aria-label="Filas por pagina"><span>{{ $paginator->perPage() }}</span><span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
            <div class="form-select-menu">
                @foreach ([5, 10, 25] as $size)
                    <a class="topbar-dropdown-option {{ $paginator->perPage() === $size ? 'selected' : '' }}" data-admin-page href="{{ request()->fullUrlWithQuery(['per_page' => $size, 'page' => 1]) }}">{{ $size }}</a>
                @endforeach
            </div>
        </div>
    </div>
    <a class="container-clear-button" data-admin-page href="{{ url()->current().(count(request()->only($clearKeys ?? ['estado', 'status', 'tipo'])) ? '?'.http_build_query(request()->only($clearKeys ?? ['estado', 'status', 'tipo'])) : '') }}">Limpiar</a>
    @php
        $currentPermission = \App\Services\AccessPermissions::required(request());
        $mayCreate = !$currentPermission || auth()->user()->hasPermission(explode('.', $currentPermission)[0].'.manage');
    @endphp
    @if(($createAction ?? null) && $mayCreate)
        <button type="button" class="container-new-button" data-yard-new="{{ $createKind }}" data-action="{{ $createAction }}"><span data-admin-icon="plus" aria-hidden="true"></span>{{ $createLabel }}</button>
    @elseif (($createUrl ?? null) && $mayCreate)
        <a class="container-new-button" href="{{ $createUrl }}"><span data-admin-icon="plus" aria-hidden="true"></span>{{ $createLabel ?? 'Nuevo' }}</a>
    @endif
</div>
