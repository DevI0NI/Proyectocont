@extends('layouts.app')
@section('content')
    @if(! $location)
        <div class="parking-heading"><div><span class="eyebrow">Control de estadias</span><h1>Parqueo de transporte</h1></div><a class="button secondary" href="{{ route('rentals.settings') }}"><span data-parking-icon="settings"></span>Configuracion</a></div>
        <section class="parking-empty"><span data-parking-icon="truck"></span><h2>Configura tu primer predio</h2><a class="button" href="{{ route('rentals.settings') }}">Crear predio</a></section>
    @else
        @php
            $occupied = $spaces->whereNotNull('stay_id')->count();
            $blocked = $spaces->where('status', 'maintenance')->count();
            $free = $spaces->whereNull('stay_id')->where('status', 'available');
            $total = $spaces->count();
            $occupancy = $total > 0 ? (int) round($occupied / $total * 100) : 0;
            $sceneData = $spaces->map(fn($s) => ['id' => $s->id, 'code' => $s->code, 'state' => $s->stay_id ? 'occupied' : $s->status, 'plate' => $s->plate, 'url' => $s->stay_id ? route('rentals.show', $s->stay_id) : null]);
            $selectedSpace = $free->firstWhere('id', (int) old('space_id'));
        @endphp

        <section class="parking-hero">
            <div>
                <span class="eyebrow">Control de estadias</span>
                <h1>Parqueo de transporte</h1>
                <p>Toca un espacio <strong class="parking-text-available">libre</strong> para registrar una entrada, o uno <strong class="parking-text-occupied">ocupado</strong> para cobrar la salida.</p>
            </div>
            <div class="parking-hero-actions">
                <form method="GET" action="{{ route('rentals.index') }}" class="parking-location-picker"><label>Predio<select name="location" data-parking-location>@foreach($locations as $item)<option value="{{ $item->id }}" @selected($location->id === $item->id)>{{ $item->name }}</option>@endforeach</select></label></form>
                <a class="button secondary" href="{{ route('rentals.records', ['location' => $location->id]) }}"><span data-parking-icon="list"></span>Registros</a>
                <a class="button secondary" href="{{ route('rentals.settings') }}"><span data-parking-icon="settings"></span>Configuracion</a>
            </div>
        </section>

        <section class="parking-summary" aria-label="Resumen del predio">
            <div class="parking-stat available"><span class="parking-stat-dot"></span><div><small>Libres</small><strong>{{ $free->count() }}</strong></div></div>
            <div class="parking-stat occupied"><span class="parking-stat-dot"></span><div><small>Ocupados</small><strong>{{ $occupied }}</strong></div></div>
            <div class="parking-stat maintenance"><span class="parking-stat-dot"></span><div><small>Bloqueados</small><strong>{{ $blocked }}</strong></div></div>
            <div class="parking-stat parking-occupancy"><div><small>Ocupacion · {{ $total }} espacios</small><strong>{{ $occupancy }} %</strong></div><div class="parking-occupancy-bar" aria-hidden="true"><span style="width: {{ $occupancy }}%"></span></div></div>
        </section>

        @if(! $rate)
            <div class="parking-warning parking-warning-card"><span><strong>Falta configurar las tarifas de este predio.</strong> Sin tarifas no se pueden registrar entradas.</span><a class="button" href="{{ route('rentals.settings.location', $location->id) }}">Configurar tarifas</a></div>
        @endif

        <section class="parking-workspace">
            <article class="panel-card parking-board">
                <div class="parking-board-header">
                    <div><h2>Espacios de {{ $location->name }}</h2><div class="parking-legend"><span class="available">Libre</span><span class="occupied">Ocupado</span><span class="maintenance">Bloqueado</span></div></div>
                    <div class="parking-view-toggle" role="tablist" aria-label="Tipo de vista"><button type="button" class="active" role="tab" aria-selected="true" data-parking-view="plan">Plano</button><button type="button" role="tab" aria-selected="false" data-parking-view="3d">Vista 3D</button></div>
                </div>
                <div class="parking-grid" data-parking-view-panel="plan" aria-label="Espacios del predio">
                    @forelse($spaces as $space)
                        @php $state = $space->stay_id ? 'occupied' : $space->status; @endphp
                        <button type="button" class="parking-tile parking-space-option {{ $state }}" data-space-id="{{ $space->id }}" data-space-code="{{ $space->code }}" @if($space->stay_id) data-stay-url="{{ route('rentals.show', $space->stay_id) }}" @endif @disabled($state === 'maintenance') title="{{ $state === 'occupied' ? 'Cobrar salida de '.$space->plate : ($state === 'maintenance' ? 'Espacio bloqueado' : 'Elegir '.$space->code) }}">
                            <span class="parking-tile-code">{{ $space->code }}</span>
                            @if($state === 'occupied')
                                <strong class="parking-tile-plate">{{ $space->plate }}</strong>
                                @php $enteredAt = \Carbon\CarbonImmutable::parse($space->entered_at, 'UTC'); $insideSeconds = max(0, now('UTC')->getTimestamp() - $enteredAt->getTimestamp()); @endphp
                                <small>Desde {{ $enteredAt->setTimezone('America/Guatemala')->format('d/m H:i') }}</small>
                                <small class="parking-tile-elapsed" data-live-since="{{ $insideSeconds }}">{{ intdiv($insideSeconds, 3600) }} h {{ str_pad(intdiv($insideSeconds % 3600, 60), 2, '0', STR_PAD_LEFT) }} min dentro</small>
                                <span class="parking-tile-action">Cobrar salida</span>
                            @elseif($state === 'maintenance')
                                <small>Bloqueado</small>
                            @else
                                <small>Libre</small>
                                <span class="parking-tile-action">Elegir</span>
                            @endif
                        </button>
                    @empty
                        <p class="parking-table-empty">Este predio no tiene espacios. Agregalos en Configuracion.</p>
                    @endforelse
                </div>
                <div class="parking-3d" data-parking-view-panel="3d" hidden>
                    <div class="parking-canvas-stage" data-parking-scene data-spaces="{{ $sceneData->toJson() }}"><canvas aria-label="Plano 3D de espacios de parqueo"></canvas><div class="parking-map-tooltip" hidden></div><div class="parking-map-error" hidden>Vista 3D no disponible. Usa el plano.</div><button class="parking-icon parking-3d-reset" type="button" data-parking-reset title="Restablecer vista" aria-label="Restablecer vista"><span data-parking-icon="rotate-ccw"></span></button></div>
                    <p class="parking-3d-help">Arrastra para girar, usa la rueda para acercar y toca un espacio para elegirlo.</p>
                </div>
            </article>

            <aside class="panel-card parking-entry-card">
                <div class="parking-entry-title"><h2>Registrar entrada</h2><p>Completa los tres pasos.</p></div>
                <form method="POST" action="{{ route('rentals.store') }}" class="parking-form" id="parking-entry-form">@csrf
                    <div class="parking-step">
                        <div class="parking-step-label"><span>1</span>Espacio</div>
                        <div class="parking-chosen @unless($selectedSpace) is-empty @endunless" id="parking-selection" data-empty-text="Toca un espacio libre en el plano">{{ $selectedSpace?->code ?? 'Toca un espacio libre en el plano' }}</div>
                        <select name="space_id" id="parking-space-select" class="parking-plain-select" data-plain-select required aria-label="Espacio"><option value="">Selecciona un espacio</option>@foreach($free as $space)<option value="{{ $space->id }}" @selected((string) old('space_id') === (string) $space->id)>{{ $space->code }}</option>@endforeach</select>
                    </div>
                    <div class="parking-step">
                        <div class="parking-step-label"><span>2</span>Vehiculo y conductor</div>
                        <label class="parking-field"><span class="parking-field-title">Placa @include('partials.field-help', ['helpId' => 'parkingPlateHelp', 'helpLabel' => 'Formato de placa', 'helpText' => '6 a 8 letras o numeros, con al menos un numero. Se permiten espacios o guiones. Ejemplos: C123ABC, C-123ABC o TC-12ABC.'])</span><input name="plate" required maxlength="15" autocomplete="off" value="{{ old('plate') }}" class="parking-plate-input container-code-input" data-plate-max="{{ \App\Services\TransportPlate::MAX_ALPHANUMERIC_LENGTH }}" pattern="{{ \App\Services\TransportPlate::PATTERN }}" data-parking-check="plate" placeholder="Ej. C123ABC"><small class="parking-field-check" aria-live="polite"></small></label>
                        <label class="parking-field"><span class="parking-field-title">Conductor @include('partials.field-help', ['helpId' => 'parkingDriverHelp', 'helpLabel' => 'Ayuda de conductor', 'helpText' => 'Nombre y apellidos de quien maneja el vehiculo. Solo letras, maximo 80 caracteres.'])</span><input name="driver" required maxlength="80" value="{{ old('driver') }}" autocomplete="off" data-parking-check="driver" placeholder="Nombre y apellidos"><small class="parking-field-check" aria-live="polite"></small></label>
                        <label class="parking-field"><span class="parking-field-title">Cliente o empresa <span class="parking-optional">(opcional)</span> @include('partials.field-help', ['helpId' => 'parkingCustomerHelp', 'helpLabel' => 'Ayuda de cliente', 'helpText' => 'Empresa o persona que paga el parqueo, si es distinta del conductor. Maximo 100 caracteres.'])</span><input name="customer" maxlength="100" value="{{ old('customer') }}" autocomplete="off" data-parking-check="company" placeholder="Transportista o empresa"><small class="parking-field-check" aria-live="polite"></small></label>
                    </div>
                    <div class="parking-step">
                        <div class="parking-step-label"><span>3</span>Forma de cobro</div>
                        <fieldset class="parking-billing"><legend class="sr-only">Modalidad de cobro</legend><label><input type="radio" name="billing_unit" value="hour" @checked(old('billing_unit','hour') === 'hour')><span>Por hora<strong>{{ $location->currency }} {{ number_format(($rate?->hourly_cents ?? 0) / 100, 2) }}</strong><small>Cada hora iniciada</small></span></label><label><input type="radio" name="billing_unit" value="day" @checked(old('billing_unit') === 'day')><span>Por dia<strong>{{ $location->currency }} {{ number_format(($rate?->daily_cents ?? 0) / 100, 2) }}</strong><small>Cada 24 horas</small></span></label></fieldset>
                        <label class="parking-field"><span class="parking-field-title">Observaciones <span class="parking-optional">(opcional)</span></span><textarea name="notes" maxlength="500" rows="2" data-parking-counter placeholder="Ej. trae carga refrigerada">{{ old('notes') }}</textarea><small class="parking-field-check parking-field-counter is-visible" aria-live="polite">{{ mb_strlen(old('notes', '')) }} / 500</small></label>
                    </div>
                    <button class="button parking-submit" type="submit" data-parking-submit @disabled(! $rate || $free->isEmpty())>{{ $selectedSpace ? 'Registrar entrada en '.$selectedSpace->code : 'Registrar entrada' }}</button>
                    @if(! $rate)<p class="parking-form-hint">Configura las tarifas para habilitar las entradas.</p>@elseif($free->isEmpty())<p class="parking-form-hint">No hay espacios libres en este predio.</p>@endif
                </form>
            </aside>
        </section>

    @endif
@endsection
