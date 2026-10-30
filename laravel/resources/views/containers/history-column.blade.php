@php($isDate = in_array($key, ['ingress', 'exit'], true))
<th class="containers-header-cell">
    <div class="history-header-label">
        <button type="button" class="containers-column-trigger" data-admin-column-trigger data-column-key="{{ $key }}" aria-expanded="false">
            {{ $label }}<i class="containers-sort-mark @if(request('sort', 'ingress') === $key) is-active {{ request('order', 'desc') === 'desc' ? 'desc' : 'asc' }} @endif" aria-hidden="true"></i>
        </button>
    <div class="containers-header-menu" hidden>
        <strong>{{ $label }}</strong>
        @foreach(['asc', 'desc'] as $order)
            <button type="button" data-admin-page data-url="{{ request()->fullUrlWithQuery(['sort' => $key, 'order' => $order, 'page' => 1]) }}" @class(['selected' => request('sort', 'ingress') === $key && request('order', 'desc') === $order])>
                {{ $isDate ? ($order === 'asc' ? 'Mas antigua' : 'Mas reciente') : ($key === 'reentries' ? ($order === 'asc' ? 'Menor primero' : 'Mayor primero') : ($order === 'asc' ? 'A - Z' : 'Z - A')) }}
            </button>
        @endforeach
        @if($isDate || in_array($key, ['shipping_line', 'reentries'], true))
        <form method="get" action="{{ route('containers.history.index') }}" data-admin-query data-admin-live-query data-history-query class="history-column-fields">
            @include('containers.history-query-fields', ['exclude' => $isDate ? [$key.'_from', $key.'_to'] : [$key]])
            @if($isDate)
                <label><span>Desde</span><input type="date" name="{{ $key }}_from" value="{{ request($key.'_from') }}" aria-label="{{ $label }} desde"></label>
                <label><span>Hasta</span><input type="date" name="{{ $key }}_to" value="{{ request($key.'_to') }}" aria-label="{{ $label }} hasta"></label>
            @elseif($key === 'reentries')
                <label><span>Cantidad de reingresos</span><input type="number" name="reentries" min="0" value="{{ request('reentries') }}" aria-label="Filtrar por cantidad de reingresos"></label>
            @else
                <input type="search" name="shipping_line" maxlength="120" value="{{ request('shipping_line') }}" placeholder="Filtrar naviera" aria-label="Filtrar naviera del ingreso">
            @endif
        </form>
        @if(($isDate && (request()->filled($key.'_from') || request()->filled($key.'_to'))) || (!$isDate && request()->filled($key)))
            <button type="button" data-admin-page data-url="{{ request()->fullUrlWithoutQuery($isDate ? [$key.'_from', $key.'_to', 'page'] : [$key, 'page']) }}">Quitar filtro</button>
        @endif
        @endif
    </div>
    </div>
</th>
