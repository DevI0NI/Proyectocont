@extends('layouts.app')
@section('content')
    @php
        $blocked = $counts['blocked'];
        $occupiedSpaces = $counts['occupied'];
    @endphp
    <section class="parking-hero">
        <div>
            <span class="eyebrow">Configuracion de predio</span>
            <h1>{{ $location->name }}</h1>
            <p>{{ $location->address ?: 'Sin direccion registrada' }} · {{ $location->currency }} · {{ $counts['total'] }} espacios</p>
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
                    <label class="parking-field">Por hora iniciada ({{ $location->currency }})<input type="text" inputmode="decimal" name="hourly" maxlength="9" required autocomplete="off" data-parking-check="money" value="{{ old('hourly', $rate ? number_format($rate->hourly_cents / 100, 2, '.', '') : '') }}" placeholder="0.00"><small class="parking-field-check" aria-live="polite">@error('hourly'){{ $message }}@enderror</small></label>
                    <label class="parking-field">Por dia de 24 h ({{ $location->currency }})<input type="text" inputmode="decimal" name="daily" maxlength="9" required autocomplete="off" data-parking-check="money" data-at-least="hourly" value="{{ old('daily', $rate ? number_format($rate->daily_cents / 100, 2, '.', '') : '') }}" placeholder="0.00"><small class="parking-field-check" aria-live="polite">@error('daily'){{ $message }}@enderror</small></label>
                </div>
                <button class="button" type="submit">Guardar tarifas</button>
            </form>
        </article>
        <article class="panel-card parking-settings-card">
            <div class="parking-step-label"><span>2</span>Agregar espacios</div>
            <p class="parking-settings-hint">Este predio ya tiene <strong>{{ $counts['total'] }} espacios</strong>. Escribe solo cuantos <strong>nuevos</strong> quieres sumar; el sistema les pone el codigo y sigue la numeracion.</p>
            <form method="POST" action="{{ route('rentals.spaces.store', $location->id) }}" class="parking-form">@csrf
                <div class="parking-fields">
                    <label class="parking-field">Espacios nuevos a sumar<input type="text" inputmode="numeric" name="quantity" maxlength="3" required autocomplete="off" data-parking-check="quantity" data-max="{{ max(0, min(100, 500 - $counts['total'])) }}" value="{{ old('quantity') }}" placeholder="Ej. 5"><small class="parking-field-check" aria-live="polite">@error('quantity'){{ $message }}@enderror</small></label>
                    <label class="parking-field"><span class="parking-field-title">Letra de la fila @include('partials.field-help', ['helpId' => 'parkingPrefixHelp', 'helpLabel' => 'Ayuda de letra de la fila', 'helpText' => 'Una o dos letras, como la A de A-01. Usa otra letra (B, C...) si los espacios estan en otra zona del predio.'])</span><input name="prefix" maxlength="2" autocomplete="off" data-parking-check="prefix" value="{{ old('prefix', $suggestedPrefix) }}" required class="container-code-input"><small class="parking-field-check" aria-live="polite">@error('prefix'){{ $message }}@enderror</small></label>
                </div>
                <button class="button" type="submit">Agregar espacios</button>
            </form>
        </article>
    </section>

    <section class="panel-card parking-settings-card" id="espacios">
        <div class="parking-board-header">
            <div><div class="parking-step-label"><span>3</span>Espacios del predio</div><p class="parking-settings-hint">Si un espacio no se puede usar, <strong>bloquealo</strong>. Para achicar el predio solo se quita el <strong>ultimo</strong> de cada fila{{ $lastCodes ? ' ('.implode(', ', $lastCodes).')' : '' }} con la papelera, asi no quedan huecos en la numeracion.</p></div>
            <div class="parking-legend"><span class="available">Libre · {{ $counts['total'] - $blocked - $occupiedSpaces }}</span><span class="occupied">Ocupado · {{ $occupiedSpaces }}</span><span class="maintenance">Bloqueado · {{ $blocked }}</span></div>
        </div>
        <div class="parking-space-admin">
            @forelse($spaces as $space)
                @php $state = $space->plate ? 'occupied' : $space->status; @endphp
                <div class="parking-space-admin-item {{ $state }}">
                    <strong>{{ $space->code }}</strong>
                    <small>{{ $state === 'occupied' ? $space->plate : ($state === 'maintenance' ? 'Bloqueado' : 'Libre') }}</small>
                    <div class="parking-space-admin-actions">
                        <form method="POST" action="{{ route('rentals.spaces.update', $space->id) }}">@csrf @method('PATCH')
                            <input type="hidden" name="status" value="{{ $space->status === 'available' ? 'maintenance' : 'available' }}">
                            <button class="button secondary" type="submit" @disabled($state === 'occupied')>{{ $state === 'occupied' ? 'Ocupado' : ($space->status === 'available' ? 'Bloquear' : 'Habilitar') }}</button>
                        </form>
                        @if(in_array($space->id, $removableIds, true))
                            <button class="parking-space-remove" type="button" data-space-remove data-url="{{ route('rentals.spaces.destroy', $space->id) }}" data-code="{{ $space->code }}" @disabled($state === 'occupied') title="{{ $state === 'occupied' ? 'No se puede quitar mientras este ocupado' : 'Quitar '.$space->code.', el ultimo de la fila' }}" aria-label="Quitar {{ $space->code }}"><span data-parking-icon="trash"></span></button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="parking-table-empty">Este predio todavia no tiene espacios. Agregalos en el paso 2.</p>
            @endforelse
        </div>
        @if($spaces->lastPage() > 1)
            <nav class="admin-pagination parking-space-pagination" aria-label="Paginacion de espacios">
                <span role="status">Pagina {{ $spaces->currentPage() }} de {{ $spaces->lastPage() }} · {{ $spaces->total() }} espacios</span>
                <div class="admin-page-buttons">
                    @foreach([[1, 'chevrons-left', 'Primera pagina', $spaces->onFirstPage()], [max(1, $spaces->currentPage() - 1), 'chevron-left', 'Pagina anterior', $spaces->onFirstPage()], [min($spaces->lastPage(), $spaces->currentPage() + 1), 'chevron-right', 'Pagina siguiente', ! $spaces->hasMorePages()], [$spaces->lastPage(), 'chevrons-right', 'Ultima pagina', ! $spaces->hasMorePages()]] as [$page, $icon, $label, $disabled])
                        @if($disabled)
                            <span class="admin-action disabled" aria-disabled="true" aria-label="{{ $label }}"><span data-parking-icon="{{ $icon }}"></span></span>
                        @else
                            <a class="admin-action" href="{{ $spaces->url($page) }}" title="{{ $label }}" aria-label="{{ $label }}"><span data-parking-icon="{{ $icon }}"></span></a>
                        @endif
                    @endforeach
                </div>
            </nav>
        @endif
    </section>
    <dialog class="parking-dialog parking-form-dialog" id="parking-remove-dialog" aria-labelledby="parking-remove-title">
        <span class="eyebrow">Quitar espacio</span>
        <h2 id="parking-remove-title">Quitar el espacio <span data-remove-code></span>?</h2>
        <p>Es el ultimo de su fila. Dejara de aparecer en el plano y ya no se podran registrar entradas en el. Los cobros que ya tuvo se conservan en los registros, y si despues agregas espacios volvera a aparecer con el mismo codigo.</p>
        <form method="POST" action="" data-remove-form>@csrf @method('DELETE')
            <div class="parking-actions"><button type="button" class="button secondary" data-dialog-close>Cancelar</button><button class="button parking-danger" type="submit">Quitar espacio</button></div>
        </form>
    </dialog>
@endsection
