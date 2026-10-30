@foreach(['search', 'state', 'per_page', 'sort', 'order', 'shipping_line', 'reentries', 'ingress_from', 'ingress_to', 'exit_from', 'exit_to'] as $parameter)
    @if(!in_array($parameter, $exclude ?? [], true) && is_string(request()->query($parameter)))
        <input type="hidden" name="{{ $parameter }}" value="{{ request()->query($parameter) }}">
    @endif
@endforeach
