@foreach (['per_page', 'sort', 'order', 'estado', 'status', 'tipo', 'rol_filtro', 'patio_id', 'q', 'return_to'] as $parameter)
    @if (!in_array($parameter, $exclude ?? [], true) && is_string(request()->query($parameter)))
        <input type="hidden" name="{{ $parameter }}" value="{{ request()->query($parameter) }}">
    @endif
@endforeach
@foreach ($tableColumns as $column)
    @if (($excludeFilter ?? null) !== $column['key'] && is_string(request()->query('filters', [])[$column['key']] ?? null))
        <input type="hidden" name="filters[{{ $column['key'] }}]" value="{{ request()->query('filters', [])[$column['key']] }}">
    @endif
@endforeach
