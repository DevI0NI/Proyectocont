@extends('layouts.app')
@section('content')
    @php
        $blocked = $spaces->where('status', 'maintenance')->count();
        $occupiedSpaces = $spaces->whereNotNull('plate')->count();
    @endphp
    <section class="parking-hero">
        <div>
            <span class="eyebrow">Configuracion de predio</span>
            <h1>{{ $location->name }}</h1>
            <p>{{ $location->address ?: 'Sin direccion registrada' }} · {{ $location->currency }} · {{ $spaces->count() }} espacios</p>
        </div>
        <div class="parking-hero-actions">
            <a class="button secondary" href="{{ route('rentals.settings') }}"><span data-parking-icon="arrow-left"></span>Volver a predios</a>
            <a class="button" href="{{ route('rentals.index', ['location' => $location->id]) }}">Abrir plano</a>
        </div>
    </section>

    <section class="parking-settings-grid">
        <article class="panel-card parking-settings-card">
            <div class="parking-step-label"><span>1</span>Tarifas</div>
            <p class="parking-settings-hint">{{ $rate ? 'Las estadias que ya estan dentro conservan la tarifa con la que entraron.' : 'Sin tarifas no se pueden registrar entradas en este predio.' }}</p>
            <form method="POST" action="{{ route('rentals.rates.store', $location->id) }}" class="parking-form">@csrf
                <div class="parking-fields">
                    <label>Por hora iniciada ({{ $location->currency }})<input type="number" name="hourly" min="0.01" max="999999.99" step="0.01" required value="{{ old('hourly', $rate ? number_format($rate->hourly_cents / 100, 2, '.', '') : '') }}" placeholder="0.00"></label>
                    <label>Por dia de 24 h ({{ $location->currency }})<input type="number" name="daily" min="0.01" max="999999.99" step="0.01" required value="{{ old('daily', $rate ? number_format($rate->daily_cents / 100, 2, '.', '') : '') }}" placeholder="0.00"></label>
                </div>
                <button class="button" type="submit">Guardar tarifas</button>
            </form>
        </article>
        <article class="panel-card parking-settings-card">
            <div class="parking-step-label"><span>2</span>Agregar espacios</div>
            <p class="parking-settings-hint">Se crean con el prefijo y numeros seguidos. Por ejemplo, prefijo A y cantidad 3 crea A-01, A-02 y A-03 (los que ya existen se saltan).</p>
            <form method="POST" action="{{ route('rentals.spaces.store', $location->id) }}" class="parking-form">@csrf
                <div class="parking-fields">
                    <label>Prefijo<input name="prefix" maxlength="8" pattern="[A-Za-z0-9]{1,8}" value="{{ old('prefix', 'A') }}" required class="container-code-input"></label>
                    <label>Cantidad<input type="number" name="quantity" min="1" max="100" value="{{ old('quantity', 10) }}" required></label>
                </div>
                <button class="button secondary" type="submit">Agregar espacios</button>
            </form>
        </article>
    </section>

    <section class="panel-card parking-settings-card">
        <div class="parking-board-header">
            <div><div class="parking-step-label"><span>3</span>Espacios del predio</div><p class="parking-settings-hint">Bloquea un espacio cuando este en mantenimiento. Los ocupados no se pueden bloquear hasta cobrar su salida.</p></div>
            <div class="parking-legend"><span class="available">Libre · {{ $spaces->count() - $blocked - $occupiedSpaces }}</span><span class="occupied">Ocupado · {{ $occupiedSpaces }}</span><span class="maintenance">Bloqueado · {{ $blocked }}</span></div>
        </div>
        <div class="parking-space-admin">
            @forelse($spaces as $space)
                @php $state = $space->plate ? 'occupied' : $space->status; @endphp
                <form method="POST" action="{{ route('rentals.spaces.update', $space->id) }}" class="parking-space-admin-item {{ $state }}">@csrf @method('PATCH')
                    <strong>{{ $space->code }}</strong>
                    <small>{{ $state === 'occupied' ? $space->plate : ($state === 'maintenance' ? 'Bloqueado' : 'Libre') }}</small>
                    <input type="hidden" name="status" value="{{ $space->status === 'available' ? 'maintenance' : 'available' }}">
                    <button class="button secondary" type="submit" @disabled($state === 'occupied')>{{ $state === 'occupied' ? 'Ocupado' : ($space->status === 'available' ? 'Bloquear' : 'Habilitar') }}</button>
                </form>
            @empty
                <p class="parking-table-empty">Este predio todavia no tiene espacios. Agregalos en el paso 2.</p>
            @endforelse
        </div>
    </section>
@endsection
