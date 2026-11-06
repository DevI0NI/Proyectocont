@extends('layouts.app')
@section('content')
    @php $creatingLocation = $errors->hasAny(['name', 'address', 'currency']); @endphp
    <section class="parking-hero">
        <div>
            <span class="eyebrow">Administracion de parqueo</span>
            <h1>Predios y tarifas</h1>
            <p>Elige el predio que quieres configurar. Ahi ajustas sus tarifas, agregas espacios y bloqueas los que esten en mantenimiento.</p>
        </div>
        <div class="parking-hero-actions">
            <a class="button secondary" href="{{ route('rentals.index') }}"><span data-parking-icon="arrow-left"></span>Volver al plano</a>
            <button class="button" type="button" data-dialog-open="parking-location-dialog"><span data-parking-icon="plus"></span>Nuevo predio</button>
        </div>
    </section>

    @if($locations->isEmpty())
        <section class="parking-empty"><span data-parking-icon="truck"></span><h2>Todavia no hay predios</h2><p class="table-muted">Crea el primero para empezar a registrar camiones.</p><button class="button" type="button" data-dialog-open="parking-location-dialog">Crear predio</button></section>
    @else
        <section class="parking-location-grid" aria-label="Predios">
            @foreach($locations as $location)
                @php
                    $counts = $spaceCounts->get($location->id, collect())->pluck('total', 'status');
                    $totalSpaces = (int) $counts->sum();
                    $blocked = (int) ($counts['maintenance'] ?? 0);
                    $rate = $rates->get($location->id);
                @endphp
                <a class="parking-location-card" href="{{ route('rentals.settings.location', $location->id) }}">
                    <span class="parking-location-icon"><span data-parking-icon="truck"></span></span>
                    <span class="parking-location-copy">
                        <strong>{{ $location->name }}</strong>
                        <small>{{ $location->address ?: 'Sin direccion registrada' }} · {{ $location->currency }}</small>
                    </span>
                    <span class="parking-location-facts">
                        <span><b>{{ $totalSpaces }}</b> espacios</span>
                        <span><b>{{ (int) ($occupied[$location->id] ?? 0) }}</b> ocupados</span>
                        <span><b>{{ $blocked }}</b> bloqueados</span>
                    </span>
                    @if($rate)
                        <span class="parking-location-rate">{{ $location->currency }} {{ number_format($rate->hourly_cents / 100, 2) }} / hora · {{ $location->currency }} {{ number_format($rate->daily_cents / 100, 2) }} / dia</span>
                    @else
                        <span class="parking-location-rate is-missing">Falta configurar las tarifas</span>
                    @endif
                    <span class="parking-location-go">Configurar<span data-parking-icon="chevron-right"></span></span>
                </a>
            @endforeach
        </section>
    @endif

    <dialog class="parking-dialog parking-form-dialog" id="parking-location-dialog" aria-labelledby="parking-location-title" @if($creatingLocation) data-open-on-load @endif>
        <span class="eyebrow">Nuevo predio</span>
        <h2 id="parking-location-title">Crear predio de parqueo</h2>
        <p>Despues de crearlo podras agregar sus espacios y tarifas.</p>
        <form method="POST" action="{{ route('rentals.locations.store') }}" class="parking-form">@csrf
            <label>Nombre<input name="name" required maxlength="100" value="{{ old('name') }}" placeholder="Ej. Predio Santo Tomas" autocomplete="off"></label>
            <label><span>Direccion <span class="parking-optional">(opcional)</span></span><input name="address" maxlength="200" value="{{ old('address') }}" autocomplete="off"></label>
            <label>Moneda<select name="currency"><option value="GTQ" @selected(old('currency', 'GTQ') === 'GTQ')>GTQ - Quetzales</option><option value="USD" @selected(old('currency') === 'USD')>USD - Dolares</option></select></label>
            <div class="parking-actions"><button type="button" class="button secondary" data-dialog-close>Cancelar</button><button class="button" type="submit">Crear predio</button></div>
        </form>
    </dialog>
@endsection
