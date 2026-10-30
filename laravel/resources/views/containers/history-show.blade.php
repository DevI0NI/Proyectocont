@extends('layouts.app')
@section('content')
<div class="administration-main containers-livewire-shell container-history" data-container-history>
    <header class="panel-card history-page-heading">
        <div class="view-header-copy"><p>Historial de contenedores · {{ $sucursal->nombre }}</p><h1>{{ $container->nombre }}</h1></div>
        <a class="mw-button" href="{{ $returnUrl }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a>
    </header>
    @if($cycles->isNotEmpty())
    <form method="get" action="{{ route('containers.history.show', $container->id) }}" class="history-cycle-filter record-select-fields" data-history-cycle-form>
        <input type="hidden" name="return_to" value="{{ $returnUrl }}" />
        <label for="history-cycle"><span>Ingreso del contenedor</span>
        <select id="history-cycle" name="cycle" data-admin-select>
            @foreach($cycles as $cycle)
                @php
                    $ordinal = [1 => 'Primer', 2 => 'Segundo', 3 => 'Tercer', 4 => 'Cuarto', 5 => 'Quinto', 6 => 'Sexto', 7 => 'Septimo', 8 => 'Octavo', 9 => 'Noveno', 10 => 'Decimo'];
                    $cycleLabel = $loop->first ? 'Primer ingreso' : (isset($ordinal[$loop->index]) ? $ordinal[$loop->index].' reingreso' : 'Reingreso '.$loop->index);
                @endphp
                <option value="{{ $cycle->id }}" @selected((int) $selectedCycle?->id === (int) $cycle->id)>{{ $cycleLabel }} · {{ \Carbon\Carbon::parse($cycle->entered_at)->format('d/m/Y H:i') }} · {{ $cycle->closed_at ? 'Finalizado' : 'En patio' }}</option>
            @endforeach
        </select>
        </label>
        <span class="history-cycle-period">{{ \Carbon\Carbon::parse($selectedCycle->entered_at)->format('d/m/Y H:i') }} → {{ $selectedCycle->closed_at ? \Carbon\Carbon::parse($selectedCycle->closed_at, 'UTC')->setTimezone('America/Guatemala')->format('d/m/Y H:i') : 'Sin salida registrada' }}</span>
    </form>
    @endif
    <section class="history-timeline-section" data-history-timeline aria-label="Linea de tiempo del contenedor">
        <div class="history-timeline-heading">
            <h2>Linea de tiempo</h2>
            <div class="history-timeline-tools"><span>{{ max(0, $cycleCount - 1) }} {{ $cycleCount === 2 ? 'reingreso' : 'reingresos' }} · {{ $sucursal->nombre }}</span>
                <button type="button" class="mw-button mw-icon" data-history-previous title="Evento anterior" aria-label="Evento anterior" disabled><span data-notification-icon="chevron-left" aria-hidden="true"></span></button>
                <button type="button" class="mw-button mw-icon" data-history-next title="Evento siguiente" aria-label="Evento siguiente" @disabled($timeline->count() < 2)><span data-notification-icon="chevron-right" aria-hidden="true"></span></button>
            </div>
        </div>
        <ol class="history-timeline" role="tablist" aria-label="Eventos del contenedor" aria-orientation="horizontal">
        @forelse($timeline as $entry)
            @php
                $isIngress = in_array($entry->kind, ['ingress', 'reentry'], true);
                $date = \Carbon\Carbon::parse($entry->occurred_at, 'UTC')->setTimezone('America/Guatemala');
                $icon = match($entry->kind) { 'ingress', 'reentry' => 'container', 'dispatch' => 'ship', 'repair', 'correction_submitted' => 'wrench', 'transfer' => 'arrow-right', 'modification' => 'pencil', default => 'shield-check' };
            @endphp
            <li class="history-timeline-item history-kind-{{ $entry->kind }}" role="presentation">
                <button type="button" class="history-event-tab" role="tab" data-history-event="{{ $loop->index }}" id="history-event-{{ $loop->index }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" aria-controls="history-panel-{{ $loop->index }}" tabindex="{{ $loop->first ? 0 : -1 }}">
                    <time datetime="{{ $date->toIso8601String() }}"><strong>{{ $date->format('d/m/Y') }}</strong><span>{{ $date->format('H:i') }}</span></time>
                    <span class="history-timeline-marker" data-catalog-icon="{{ $icon }}" aria-hidden="true"></span>
                    <span class="history-event-label">{{ $entry->title }}</span>
                </button>
            </li>
        @empty
            <li class="history-empty">Este registro antiguo no tiene un ciclo de ingreso identificado. No se han atribuido eventos a una estancia.</li>
        @endforelse
        </ol>
        <div class="history-event-panels">
        @foreach($timeline as $entry)
            @php
                $isIngress = in_array($entry->kind, ['ingress', 'reentry'], true);
                $date = \Carbon\Carbon::parse($entry->occurred_at, 'UTC')->setTimezone('America/Guatemala');
                $result = $entry->kind === 'repair' ? (\App\Services\MaintenanceWorkflow::STATES[$entry->result] ?? $entry->result) : (\App\Services\MaintenanceCorrections::STATES[$entry->result] ?? $entry->result);
                $photos = $entry->kind === 'external_audit_status' ? ($entry->snapshot['audit']['photos'] ?? []) : [];
            @endphp
            <article class="history-timeline-entry" role="tabpanel" data-history-panel="{{ $loop->index }}" id="history-panel-{{ $loop->index }}" aria-labelledby="history-event-{{ $loop->index }}" tabindex="0" @if(!$loop->first) hidden @endif>
                <div class="history-entry-heading"><h3>{{ $entry->title }}</h3>@if($result)<span class="status-badge {{ match($result) { 'Aprobado', 'Aprobada' => 'badge-success', 'Rechazado' => 'badge-danger', 'En revision', 'En reparacion' => 'badge-warning', default => 'badge-neutral' } }}" @if(in_array($entry->kind, ['repair', 'correction_submitted'], true)) title="Estado actual de esta intervencion" @endif>{{ $result }}</span>@endif</div>
                <p class="history-entry-meta">{{ $date->format('d/m/Y H:i') }} · {{ $entry->cycle_id ? 'Ingreso #'.$entry->cycle_id : 'Sin ingreso identificado' }}@if($entry->actor) · {{ $entry->actor }}@endif</p>
                @if($entry->kind === 'transfer')
                    <div class="history-transfer-route"><div><span class="history-detail-label">Ubicacion anterior</span><strong>{{ $entry->snapshot['origin'] }}</strong></div><span data-notification-icon="arrow-right" aria-hidden="true"></span><div><span class="history-detail-label">Nueva ubicacion</span><strong>{{ $entry->snapshot['destination'] }}</strong></div></div>
                @elseif($entry->kind === 'modification')
                    <div class="history-changes-wrap" tabindex="0" role="region" aria-label="Campos modificados">
                    <table class="history-changes"><thead><tr><th>Campo</th><th>Valor anterior</th><th>Valor nuevo</th></tr></thead><tbody>
                        @foreach($entry->snapshot['changes'] ?? [] as $change)<tr><th scope="row">{{ $change['field'] }}</th><td>{{ $change['before'] ?? 'Sin registrar' }}</td><td>{{ $change['after'] ?? 'Sin registrar' }}</td></tr>@endforeach
                    </tbody></table></div>
                @else
                    @if($entry->details)
                    <dl class="history-detail-grid">
                        @foreach($entry->details as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value ?: 'No registrado' }}</dd></div>@endforeach
                    </dl>
                    @endif
                    <div class="history-detail-sections">
                        @foreach($entry->sections as $label => $value)
                            @if($value)<section><h4>{{ $label }}</h4><p class="history-notes">{{ $value }}</p></section>@endif
                        @endforeach
                    </div>
                    @if(!$entry->details && !$entry->sections && $entry->notes)<p class="history-notes">{{ $entry->notes }}</p>@endif
                    @if($isIngress && empty($entry->snapshot['document']))<p class="history-legacy-notice">Este ingreso antiguo no conserva la ficha tecnica ni la constancia original. Se muestran los datos historicos disponibles.</p>@endif
                    @if($entry->kind === 'dispatch')<p>Salida registrada en esta sucursal.</p>@endif
                @endif
                @if($photos)
                    <section class="history-evidence"><h4>Evidencias de la revision <span>{{ count($photos) }}</span></h4><div class="history-photos" aria-label="Fotos de la auditoria">
                        @foreach($photos as $index => $photo)
                        <a href="{{ route('containers.history.photo', ['container' => $container->id, 'event' => $entry->event_id, 'photo' => $index]) }}" target="_blank" rel="noopener" title="Ver {{ $photo['name'] ?? 'evidencia' }}"><img loading="lazy" src="{{ route('containers.history.photo', ['container' => $container->id, 'event' => $entry->event_id, 'photo' => $index]) }}" alt="{{ $photo['name'] ?? 'Evidencia de auditoria' }}" /><span>{{ $photo['name'] ?? 'Ver evidencia' }}</span></a>
                        @endforeach
                    </div></section>
                @endif
                <div class="history-entry-actions">
                    @if($isIngress && !empty($entry->snapshot['document']))<a class="mw-button" href="{{ route('documents.ingress.ticket', ['cycle_id' => $entry->cycle_id]) }}" target="_blank" rel="noopener"><span data-catalog-icon="book-open" aria-hidden="true"></span>Ver EIR de ingreso</a>@endif
                    @if($entry->job_id)<a class="mw-button" href="{{ route('corrections.detail', array_filter(['job' => $entry->job_id, 'intervention' => $entry->round_id, 'return_to' => request()->getRequestUri()])) }}"><span data-notification-icon="eye" aria-hidden="true"></span>{{ $entry->round_id ? 'Ver correccion' : 'Ver reparacion' }}</a>@endif
                    @if($entry->kind === 'dispatch' && $entry->event_id && auth()->user()->hasPermission('dispatches.view'))<a class="mw-button" href="{{ route('documents.dispatch.ticket', ['event_id' => $entry->event_id]) }}" target="_blank" rel="noopener"><span data-catalog-icon="book-open" aria-hidden="true"></span>Ver EIR de salida</a>@endif
                </div>
            </article>
        @endforeach
        </div>
    </section>
</div>
@endsection
