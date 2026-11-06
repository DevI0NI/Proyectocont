@extends('layouts.app')
@section('content')
    <section class="parking-hero">
        <div>
            <span class="eyebrow">Control de estadias</span>
            <h1>Registros de parqueo</h1>
            <p>Vehiculos que estan dentro del predio y estadias ya cobradas. Busca por placa o conductor.</p>
        </div>
        <div class="parking-hero-actions">
            <form method="GET" action="{{ route('rentals.records') }}" class="parking-location-picker"><input type="hidden" name="tab" value="{{ $history ? 'history' : 'active' }}"><label>Predio<select name="location" data-parking-location>@foreach($locations as $item)<option value="{{ $item->id }}" @selected($location->id === $item->id)>{{ $item->name }}</option>@endforeach</select></label></form>
            <a class="button secondary" href="{{ route('rentals.index', ['location' => $location->id]) }}"><span data-parking-icon="arrow-left"></span>Volver al plano</a>
        </div>
    </section>

    <section class="panel-card parking-register">
        <div class="parking-register-toolbar"><nav class="parking-tabs" aria-label="Estadias"><a class="@unless($history) active @endunless" href="{{ route('rentals.records', ['location' => $location->id]) }}">Dentro del predio</a><a class="@if($history) active @endif" href="{{ route('rentals.records', ['location' => $location->id, 'tab' => 'history']) }}">Historial de cobros</a></nav><form method="GET" action="{{ route('rentals.records') }}" class="parking-search"><input type="hidden" name="location" value="{{ $location->id }}"><input type="hidden" name="tab" value="{{ $history ? 'history' : 'active' }}"><input name="q" maxlength="255" value="{{ request('q') }}" placeholder="Buscar placa o conductor" aria-label="Buscar placa o conductor"><button class="parking-icon" type="submit" title="Buscar" aria-label="Buscar"><span data-parking-icon="search"></span></button></form></div>
        <div class="table-scroll"><table class="data-table parking-table"><thead><tr><th>Estadia</th><th>Placa</th><th>Conductor</th><th>Espacio</th><th>Entrada</th>@if($history)<th>Salida</th>@endif<th>Modalidad</th><th>{{ $history ? 'Total' : 'Tarifa' }}</th><th></th></tr></thead><tbody>@forelse($stays as $stay)<tr><td>#{{ str_pad($stay->id, 6, '0', STR_PAD_LEFT) }}</td><td><strong>{{ $stay->plate }}</strong></td><td>{{ $stay->driver }}</td><td>{{ $stay->space_code }}</td><td>{{ \Carbon\CarbonImmutable::parse($stay->entered_at, 'UTC')->setTimezone('America/Guatemala')->format('d/m/Y H:i') }}</td>@if($history)<td>{{ \Carbon\CarbonImmutable::parse($stay->exited_at, 'UTC')->setTimezone('America/Guatemala')->format('d/m/Y H:i') }}</td>@endif<td>{{ $stay->billing_unit === 'hour' ? 'Por hora' : 'Por dia' }}</td><td>{{ $stay->currency }} {{ number_format(($stay->exited_at ? $stay->total_cents : $stay->rate_cents) / 100, 2) }}</td><td><a class="button secondary" href="{{ route('rentals.show', $stay->id) }}">{{ $stay->exited_at ? 'Comprobante' : 'Cobrar salida' }}</a></td></tr>@empty<tr><td colspan="{{ $history ? 9 : 8 }}" class="parking-table-empty">{{ request('q') ? 'No hay resultados para esa busqueda.' : ($history ? 'Todavia no hay estadias cobradas.' : 'No hay vehiculos dentro del predio.') }}</td></tr>@endforelse</tbody></table></div>
        {{ $stays->links() }}
    </section>
@endsection
