@extends('layouts.app')
@section('content')
    <div class="parking-heading"><div><span class="eyebrow">Control de estadias</span><h1>Parqueo de transporte</h1></div><a class="button secondary" href="{{ route('rentals.settings') }}"><span data-parking-icon="settings"></span>Configuracion</a></div>
    @if(! $location)
        <section class="parking-empty"><span data-parking-icon="truck"></span><h2>Configura tu primer predio</h2><a class="button" href="{{ route('rentals.settings') }}">Crear predio</a></section>
    @else
        @php
            $occupied = $spaces->whereNotNull('stay_id')->count();
            $blocked = $spaces->where('status', 'maintenance')->count();
            $free = $spaces->whereNull('stay_id')->where('status', 'available');
            $sceneData = $spaces->map(fn($s) => ['id' => $s->id, 'code' => $s->code, 'state' => $s->stay_id ? 'occupied' : $s->status, 'plate' => $s->plate, 'url' => $s->stay_id ? route('rentals.show', $s->stay_id) : null]);
        @endphp
        <div class="parking-location-bar"><form method="GET" action="{{ route('rentals.index') }}"><label>Predio<select name="location" data-parking-location>@foreach($locations as $item)<option value="{{ $item->id }}" @selected($location->id === $item->id)>{{ $item->name }}</option>@endforeach</select></label></form><span class="parking-state available">{{ $free->count() }} disponibles</span><span class="parking-state occupied">{{ $occupied }} ocupados</span><span class="parking-state maintenance">{{ $blocked }} bloqueados</span><span class="parking-capacity">{{ $spaces->count() }} espacios</span></div>
        @if(! $rate)<div class="parking-warning">Este predio no tiene tarifas. <a href="{{ route('rentals.settings') }}">Configurar tarifas</a></div>@endif
        <section class="parking-operation">
            <div class="parking-entry">
                <div class="parking-section-heading"><h2>Nueva entrada</h2><span class="parking-state neutral">Caseta</span></div>
                <form method="POST" action="{{ route('rentals.store') }}" class="parking-form" id="parking-entry-form">@csrf
                    <div class="parking-fields"><label>Placa<input name="plate" required maxlength="20" autocomplete="off" value="{{ old('plate') }}" class="parking-plate-input"></label><label>Espacio<select name="space_id" id="parking-space-select" required><option value="">Selecciona un espacio</option>@foreach($free as $space)<option value="{{ $space->id }}" @selected((string)old('space_id') === (string)$space->id)>{{ $space->code }}</option>@endforeach</select></label></div>
                    <label>Conductor<input name="driver" required maxlength="120" value="{{ old('driver') }}" autocomplete="name"></label>
                    <label>Cliente / empresa<input name="customer" maxlength="120" value="{{ old('customer') }}"></label>
                    <fieldset class="parking-billing"><legend>Modalidad de cobro</legend><label><input type="radio" name="billing_unit" value="hour" @checked(old('billing_unit','hour') === 'hour')><span>Por hora<strong>{{ $location->currency }} {{ number_format(($rate?->hourly_cents ?? 0) / 100, 2) }}</strong><small>Hora iniciada</small></span></label><label><input type="radio" name="billing_unit" value="day" @checked(old('billing_unit') === 'day')><span>Por dia<strong>{{ $location->currency }} {{ number_format(($rate?->daily_cents ?? 0) / 100, 2) }}</strong><small>24 horas iniciadas</small></span></label></fieldset>
                    <label>Observaciones<textarea name="notes" maxlength="1000" rows="2">{{ old('notes') }}</textarea></label>
                    <button class="button" type="submit" @disabled(! $rate || $free->isEmpty())>Registrar entrada</button>
                </form>
            </div>
            <div class="parking-map-section">
                <div class="parking-section-heading"><h2>{{ $location->name }}</h2><button class="parking-icon" type="button" data-parking-reset title="Restablecer vista" aria-label="Restablecer vista"><span data-parking-icon="rotate-ccw"></span></button></div>
                <div class="parking-canvas-stage" data-parking-scene data-spaces="{{ $sceneData->toJson() }}"><canvas aria-label="Plano 3D de espacios de parqueo"></canvas><div class="parking-map-tooltip" hidden></div><div class="parking-map-error" hidden>Vista 3D no disponible. Puedes seleccionar un espacio en la lista.</div></div>
                <div class="parking-map-footer"><span class="parking-map-selection" id="parking-selection">Sin espacio seleccionado</span><div class="parking-legend"><span class="available">Libre</span><span class="occupied">Ocupado</span><span class="maintenance">Bloqueado</span></div></div>
                <div class="parking-space-list" aria-label="Espacios del predio">@foreach($spaces as $space)<button type="button" class="parking-space-option {{ $space->stay_id ? 'occupied' : $space->status }}" data-space-id="{{ $space->id }}" @if($space->stay_id) data-stay-url="{{ route('rentals.show', $space->stay_id) }}" @endif @disabled($space->status === 'maintenance') title="{{ $space->plate ?: ($space->status === 'maintenance' ? 'Bloqueado' : 'Disponible') }}">{{ $space->code }}</button>@endforeach</div>
            </div>
        </section>
        <section class="parking-register">
            <div class="parking-register-toolbar"><nav class="parking-tabs" aria-label="Estadias"><a class="@if(request('tab') !== 'history') active @endif" href="{{ route('rentals.index', ['location' => $location->id]) }}">Estadias activas</a><a class="@if(request('tab') === 'history') active @endif" href="{{ route('rentals.index', ['location' => $location->id, 'tab' => 'history']) }}">Historial</a></nav><form method="GET" action="{{ route('rentals.index') }}" class="parking-search"><input type="hidden" name="location" value="{{ $location->id }}"><input type="hidden" name="tab" value="{{ request('tab','active') }}"><input name="q" maxlength="255" value="{{ request('q') }}" placeholder="Buscar placa o conductor" aria-label="Buscar placa o conductor"><button class="parking-icon" type="submit" title="Buscar" aria-label="Buscar"><span data-parking-icon="search"></span></button></form></div>
            <div class="table-scroll"><table class="data-table parking-table"><thead><tr><th>Estadia</th><th>Placa</th><th>Conductor</th><th>Espacio</th><th>Entrada</th><th>Modalidad</th><th>{{ request('tab') === 'history' ? 'Total' : 'Tarifa' }}</th><th></th></tr></thead><tbody>@forelse($stays as $stay)<tr><td>#{{ str_pad($stay->id, 6, '0', STR_PAD_LEFT) }}</td><td><strong>{{ $stay->plate }}</strong></td><td>{{ $stay->driver }}</td><td>{{ $stay->space_code }}</td><td>{{ \Carbon\CarbonImmutable::parse($stay->entered_at,'UTC')->setTimezone('America/Guatemala')->format('d/m/Y H:i') }}</td><td>{{ $stay->billing_unit === 'hour' ? 'Por hora' : 'Por dia' }}</td><td>{{ $stay->currency }} {{ number_format(($stay->exited_at ? $stay->total_cents : $stay->rate_cents) / 100, 2) }}</td><td><a class="button secondary" href="{{ route('rentals.show', $stay->id) }}">{{ $stay->exited_at ? 'Comprobante' : 'Liquidar' }}</a></td></tr>@empty<tr><td colspan="8" class="parking-table-empty">{{ request('tab') === 'history' ? 'Sin estadias liquidadas.' : 'Sin estadias activas.' }}</td></tr>@endforelse</tbody></table></div>
            {{ $stays->links() }}
        </section>
    @endif
@endsection
