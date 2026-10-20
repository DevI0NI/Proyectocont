@if($actionsFirst ?? false)<th>Opciones</th>@endif
@foreach ($tableColumns as $column)
    <th>
        <button type="button" class="admin-column-trigger" data-admin-column-trigger data-column-key="{{ $column['key'] }}" aria-expanded="false">
            {{ $column['label'] }}<span data-admin-icon="{{ !($column['sortable'] ?? true) ? 'search' : (request('sort', 'name') === $column['key'] ? (request('order') === 'desc' ? 'arrow-down' : 'arrow-up') : 'arrow-up-down') }}" aria-hidden="true"></span>
        </button>
        <div class="admin-column-menu @if($liveTable ?? false) live-column-menu @endif" hidden>
            <strong>{{ $column['label'] }}</strong>
            @if($column['sortable'] ?? true)
            @foreach (['asc' => 'A - Z / Menor a mayor', 'desc' => 'Z - A / Mayor a menor'] as $order => $label)
                <a data-admin-page @class(['selected' => ($liveTable ?? false) && request('sort', 'name') === $column['key'] && request('order', 'asc') === $order]) href="{{ request()->fullUrlWithQuery(['sort' => $column['key'], 'order' => $order, 'page' => 1]) }}">{{ ($column['numeric'] ?? false) ? ($order === 'asc' ? 'Menor a mayor' : 'Mayor a menor') : (($liveTable ?? false) ? ($order === 'asc' ? 'A - Z' : 'Z - A') : $label) }}</a>
            @endforeach
            @endif
            <form method="GET" action="{{ url()->current() }}" data-admin-query @if($liveTable ?? false) data-admin-live-query @endif>
                @include('partials.administration-query-fields', ['exclude' => [], 'excludeFilter' => $column['key']])
                <input type="search" name="filters[{{ $column['key'] }}]" value="{{ is_array(request('filters')) && is_string(request('filters')[$column['key']] ?? null) ? request('filters')[$column['key']] : '' }}" placeholder="Filtrar {{ mb_strtolower($column['label']) }}" maxlength="120" aria-label="Filtrar {{ $column['label'] }}">
                @unless($liveTable ?? false)
                    <button type="submit">Aplicar filtro</button>
                @endunless
            </form>
            @if (is_array(request('filters')) && !empty(request('filters')[$column['key']]))
                <a data-admin-page href="{{ request()->fullUrlWithQuery(['filters' => array_diff_key(request('filters'), [$column['key'] => true]), 'page' => 1]) }}">Quitar filtro</a>
            @endif
        </div>
    </th>
@endforeach
@unless($actionsFirst ?? false)<th>Acciones</th>@endunless
