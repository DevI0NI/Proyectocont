@if($actionsFirst ?? false)<th>{{ $actionsLabel ?? 'Opciones' }}</th>@endif
@foreach ($tableColumns as $column)
    <th @if($containerFilters ?? false) class="containers-header-cell" @endif>
        <button type="button" class="{{ ($containerFilters ?? false) ? 'containers-column-trigger' : 'admin-column-trigger' }}" data-admin-column-trigger data-column-key="{{ $column['key'] }}" aria-expanded="false" @if(isset($column['description'])) title="{{ $column['description'] }}" @endif>
            {{ $column['label'] }}@if($containerFilters ?? false)<i class="containers-sort-mark @if(request('sort', $defaultSort ?? 'name') === $column['key']) is-active {{ request('order', $defaultOrder ?? 'asc') === 'desc' ? 'desc' : 'asc' }} @endif" aria-hidden="true"></i>@else<span data-admin-icon="{{ !($column['sortable'] ?? true) ? 'search' : (request('sort', $defaultSort ?? 'name') === $column['key'] ? (request('order', $defaultOrder ?? 'asc') === 'desc' ? 'arrow-down' : 'arrow-up') : 'arrow-up-down') }}" aria-hidden="true"></span>@endif
        </button>
        <div class="{{ ($containerFilters ?? false) ? 'containers-header-menu' : 'admin-column-menu' }} @if(($liveTable ?? false) && !($containerFilters ?? false)) live-column-menu @endif" hidden>
            <strong>{{ $column['label'] }}</strong>
            @if($column['sortable'] ?? true)
            @foreach (['asc' => 'A - Z / Menor a mayor', 'desc' => 'Z - A / Mayor a menor'] as $order => $label)
                @if($containerFilters ?? false)
                <button type="button" data-admin-page data-url="{{ request()->fullUrlWithQuery(['sort' => $column['key'], 'order' => $order, 'page' => 1]) }}" @class(['selected' => request('sort', $defaultSort ?? 'name') === $column['key'] && request('order', $defaultOrder ?? 'asc') === $order])>{{ ($column['numeric'] ?? false) ? ($order === 'asc' ? 'Menor primero' : 'Mayor primero') : ($column['key'] === 'date' ? ($order === 'asc' ? 'Más antigua' : 'Más reciente') : ($order === 'asc' ? 'A - Z' : 'Z - A')) }}</button>
                @else
                <a data-admin-page @class(['selected' => ($liveTable ?? false) && request('sort', $defaultSort ?? 'name') === $column['key'] && request('order', $defaultOrder ?? 'asc') === $order]) href="{{ request()->fullUrlWithQuery(['sort' => $column['key'], 'order' => $order, 'page' => 1]) }}">{{ ($column['numeric'] ?? false) ? ($order === 'asc' ? 'Menor a mayor' : 'Mayor a menor') : (($liveTable ?? false) ? ($order === 'asc' ? 'A - Z' : 'Z - A') : $label) }}</a>
                @endif
            @endforeach
            @endif
            @if(isset($filterOptions[$column['key']]))
                @php
                    $currentFilters = is_array(request('filters')) ? request('filters') : [];
                    $selectedValue = is_string($currentFilters[$column['key']] ?? null) ? $currentFilters[$column['key']] : '';
                @endphp
                <div class="containers-status-filter">
                    <span id="history-filter-{{ $column['key'] }}-label">Filtrar {{ mb_strtolower($column['label']) }}</span>
                    <div class="form-select-dropdown container-dropdown" data-admin-page-size data-admin-choice>
                        <button type="button" class="form-select-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="history-filter-{{ $column['key'] }}-options" aria-labelledby="history-filter-{{ $column['key'] }}-label history-filter-{{ $column['key'] }}-value">
                            <span id="history-filter-{{ $column['key'] }}-value">{{ $filterOptions[$column['key']][$selectedValue] ?? 'Todos' }}</span>
                            <svg class="dropdown-caret" viewBox="0 0 20 20" aria-hidden="true"><path d="M5.75 7.5 10 11.75 14.25 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <div id="history-filter-{{ $column['key'] }}-options" class="form-select-menu container-dropdown-menu" role="listbox" aria-labelledby="history-filter-{{ $column['key'] }}-label" inert>
                    <button type="button" data-admin-page role="option" aria-selected="{{ $selectedValue === '' ? 'true' : 'false' }}" class="topbar-dropdown-option {{ $selectedValue === '' ? 'selected' : '' }}" data-url="{{ request()->fullUrlWithQuery(['filters' => array_diff_key($currentFilters, [$column['key'] => true]), 'page' => 1]) }}">Todos</button>
                    @foreach($filterOptions[$column['key']] as $value => $label)
                        <button type="button" data-admin-page role="option" aria-selected="{{ $selectedValue === (string) $value ? 'true' : 'false' }}" class="topbar-dropdown-option {{ $selectedValue === (string) $value ? 'selected' : '' }}" data-url="{{ request()->fullUrlWithQuery(['filters' => array_replace($currentFilters, [$column['key'] => $value]), 'page' => 1]) }}">{{ $label }}</button>
                    @endforeach
                        </div>
                    </div>
                </div>
            @else
            <form method="GET" action="{{ url()->current() }}" data-admin-query @if($liveTable ?? false) data-admin-live-query @endif>
                @include('partials.administration-query-fields', ['exclude' => [], 'excludeFilter' => $column['key']])
                <input type="search" name="filters[{{ $column['key'] }}]" value="{{ is_array(request('filters')) && is_string(request('filters')[$column['key']] ?? null) ? request('filters')[$column['key']] : '' }}" placeholder="Filtrar {{ mb_strtolower($column['label']) }}" maxlength="120" aria-label="Filtrar {{ $column['label'] }}">
                @unless($liveTable ?? false)
                    <button type="submit">Aplicar filtro</button>
                @endunless
            </form>
            @endif
            @if (is_array(request('filters')) && !empty(request('filters')[$column['key']]))
                @if($containerFilters ?? false)
                <button type="button" data-admin-page data-url="{{ request()->fullUrlWithQuery(['filters' => array_diff_key(request('filters'), [$column['key'] => true]), 'page' => 1]) }}">Quitar filtro</button>
                @else
                <a data-admin-page href="{{ request()->fullUrlWithQuery(['filters' => array_diff_key(request('filters'), [$column['key'] => true]), 'page' => 1]) }}">Quitar filtro</a>
                @endif
            @endif
        </div>
    </th>
@endforeach
@unless($actionsFirst ?? false)<th>Acciones</th>@endunless
