@extends('layouts.app')
@section('content')
    <div class="parking-heading"><div><span class="eyebrow">Administracion de parqueo</span><h1>Predios y tarifas</h1></div><a class="button secondary" href="{{ route('rentals.index') }}">Volver</a></div>
    <section class="parking-settings-section">
        <h2>Nuevo predio</h2>
        <form method="POST" action="{{ route('rentals.locations.store') }}" class="parking-settings-form">@csrf
            <label>Nombre<input name="name" required maxlength="100" value="{{ old('name') }}"></label>
            <label>Direccion<input name="address" maxlength="200" value="{{ old('address') }}"></label>
            <label>Moneda<select name="currency"><option value="GTQ">GTQ - Quetzales</option><option value="USD">USD - Dolares</option></select></label>
            <button class="button" type="submit">Crear predio</button>
        </form>
    </section>
    @foreach($locations as $location)
        @php($rate = $rates->get($location->id))
        <section class="parking-settings-section">
            <div class="parking-section-heading"><div><h2>{{ $location->name }}</h2><span class="table-muted">{{ $location->address }} · {{ $location->currency }}</span></div><a class="button secondary" href="{{ route('rentals.index', ['location' => $location->id]) }}">Abrir predio</a></div>
            <div class="parking-settings-columns">
                <form method="POST" action="{{ route('rentals.rates.store', $location->id) }}" class="parking-form">@csrf
                    <h3>Tarifas</h3>
                    <div class="parking-fields"><label>Hora iniciada ({{ $location->currency }})<input type="number" name="hourly" min="0.01" max="999999.99" step="0.01" required value="{{ $rate ? number_format($rate->hourly_cents / 100, 2, '.', '') : '' }}"></label><label>Dia iniciado / 24 h ({{ $location->currency }})<input type="number" name="daily" min="0.01" max="999999.99" step="0.01" required value="{{ $rate ? number_format($rate->daily_cents / 100, 2, '.', '') : '' }}"></label></div>
                    <button class="button" type="submit">Guardar tarifas</button>
                </form>
                <form method="POST" action="{{ route('rentals.spaces.store', $location->id) }}" class="parking-form">@csrf
                    <h3>Agregar espacios</h3>
                    <div class="parking-fields"><label>Prefijo<input name="prefix" maxlength="8" pattern="[A-Za-z0-9]{1,8}" value="A" required></label><label>Cantidad<input type="number" name="quantity" min="1" max="100" value="10" required></label></div>
                    <button class="button secondary" type="submit">Agregar espacios</button>
                </form>
            </div>
            <div class="parking-space-settings">
                @forelse($spaces->get($location->id, collect()) as $space)
                    <form method="POST" action="{{ route('rentals.spaces.update', $space->id) }}">@csrf @method('PATCH')<strong>{{ $space->code }}</strong><input type="hidden" name="status" value="{{ $space->status === 'available' ? 'maintenance' : 'available' }}"><button class="button secondary" type="submit">{{ $space->status === 'available' ? 'Bloquear' : 'Habilitar' }}</button></form>
                @empty<span class="table-muted">Sin espacios configurados.</span>@endforelse
            </div>
        </section>
    @endforeach
@endsection
