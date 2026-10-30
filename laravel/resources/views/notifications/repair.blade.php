@extends('layouts.app')
@section('content')
@php
    $initialStep = max(0, min(2, request()->integer('step', 0)));
    $readOnlyDetail = $readOnlyDetail ?? false;
    $selectedIntervention = $selectedIntervention ?? null;
    $displayDate = $readOnlyDetail ? $job->created_at : $review->created_at;
    $canConfirmWork = $canConfirmWork ?? false;
    $showManagement = !$selectedIntervention && ($canConfirmWork || $canUpdateTechnicalStates);
@endphp
@if($errors->any())<div class="form-error page-error" role="alert">{{ $errors->first() }}</div>@endif
<section class="page-heading mw-repair-heading notification-review-heading @if($readOnlyDetail) repair-detail-heading @endif">
    <div>
        <span class="eyebrow">
            @if($readOnlyDetail)
                {{ $selectedIntervention ? 'Corrección '.$selectedIntervention->number : 'Detalle de reparación' }}
            @else
                Solicitud {{ str_pad((string) $review->folio, 3, '0', STR_PAD_LEFT) }}
            @endif
        </span>
        <h1>{{ $job->container_name }}</h1>
        <div class="mw-heading-status">
            <span class="mw-work-status" data-state="{{ $selectedIntervention ? match($selectedIntervention->status) { 'approved' => 'completed', 'submitted' => 'pending_review', default => 'returned_for_repair' } : $job->status }}">{{ $selectedIntervention ? match($selectedIntervention->status) { 'draft' => 'Por corregir', 'submitted' => 'Corrección enviada a auditoría', 'approved' => 'Corrección aprobada', default => 'Requiere otra corrección' } : match($job->status) { 'pending_review' => 'Pendiente de revisión', 'completed' => 'Reparación confirmada', 'returned_for_repair' => 'Devuelta a reparación', default => 'En reparación' } }}</span>
            <span>{{ $job->mechanic_name }}</span>
            <time datetime="{{ \Carbon\Carbon::parse($displayDate, 'UTC')->toIso8601String() }}">{{ \Carbon\Carbon::parse($displayDate, 'UTC')->timezone('America/Guatemala')->format('d/m/Y H:i') }}</time>
        </div>
        @if(!$selectedIntervention && $review?->status === 'confirmed')
            <p class="notification-review-confirmation"><span data-notification-icon="check-check" aria-hidden="true"></span><span>Confirmada por <strong>{{ $confirmation?->username ?? 'Usuario no disponible' }}</strong> el <time datetime="{{ \Carbon\Carbon::parse($confirmation?->created_at ?? $review->updated_at)->toIso8601String() }}">{{ \Carbon\Carbon::parse($confirmation?->created_at ?? $review->updated_at)->timezone('America/Guatemala')->format('d/m/Y') }} a las {{ \Carbon\Carbon::parse($confirmation?->created_at ?? $review->updated_at)->timezone('America/Guatemala')->format('H:i') }}</time>.</span></p>
        @endif
    </div>
    <a class="mw-button" href="{{ $returnUrl }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a>
</section>
<section class="workboard mw-board mw-repair-board notification-review" data-notification-review data-initial-step="{{ $initialStep }}">
    <div class="mw-repair-form notification-review-steps">
        <div class="notification-review-toolbar">
            @include('maintenance.timeline')
            @if($selectedIntervention)
                <div class="notification-correction-request" data-correction-history>
                    <button type="button" class="mw-button" data-correction-observation-open aria-haspopup="dialog" aria-controls="repairCorrectionRequest"><span data-notification-icon="shield-check" aria-hidden="true"></span>Solicitud de corrección</button>
                    @include('maintenance.audit-observation', array_merge($requestContext, ['dialogId' => 'repairCorrectionRequest']))
                </div>
            @endif
            @if($showManagement)
            <div class="notification-review-options form-select-dropdown" data-notification-actions>
                <button type="button" class="mw-button mw-icon" data-notification-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="repairReviewOptions" aria-label="Opciones de la reparación" title="Opciones de la reparación"><span data-notification-icon="ellipsis" aria-hidden="true"></span></button>
                <div id="repairReviewOptions" class="form-select-menu notification-review-options-menu" data-notification-popup role="menu" aria-label="Opciones de la reparación" hidden>
                    @if($canConfirmWork)
                        <form method="POST" action="{{ route($readOnlyDetail ? 'corrections.confirm' : 'notifications.confirm', $job->id) }}" role="none">@csrf
                            <input type="hidden" name="version" value="{{ $job->version }}"><input type="hidden" name="return_to" value="{{ $returnUrl }}">
                            <button class="topbar-dropdown-option" role="menuitem" tabindex="-1" type="submit"><span data-notification-icon="check-check" aria-hidden="true"></span>Confirmar trabajo realizado</button>
                        </form>
                    @endif
                    @if($canUpdateTechnicalStates)
                        @if($job->status === 'completed')
                        <a class="topbar-dropdown-option" role="menuitem" tabindex="-1" href="{{ route('containers.edit', ['container' => $job->container_id, 'return_to' => request()->getRequestUri(), 'step' => 1]) }}"><span data-notification-icon="wrench" aria-hidden="true"></span>Actualizar estados del contenedor</a>
                        @else
                        <button class="topbar-dropdown-option" role="menuitem" tabindex="-1" type="button" disabled aria-disabled="true" title="Confirma primero el trabajo realizado"><span data-notification-icon="wrench" aria-hidden="true"></span>Actualizar estados del contenedor</button>
                        @endif
                    @endif
                </div>
            </div>
            @endif
        </div>
        @foreach(['before' => ['Evaluación inicial', 'Diagnóstico y ubicación del daño', $job->diagnosis, 'Fotos de evidencia inicial', 0], 'after' => ['Resultado de la reparación', 'Trabajo realizado', $job->work_performed, 'Fotos de evidencia final', 2]] as $stage => [$title, $textLabel, $text, $photoLabel, $step])
            <section role="tabpanel" id="mw-panel-{{ $step }}" aria-labelledby="mw-tab-{{ $step }}" data-maintenance-panel="{{ $step }}" @if($initialStep !== $step) hidden @endif>
                <div class="container-form-section-head"><span>{{ sprintf('%02d', $step + 1) }}</span><div><h2>{{ $title }}</h2></div></div>
                @if($step === 2)
                    @include('maintenance.calculated-cost', ['calculatedCost' => app(\App\Services\MaintenanceCost::class)->summary($job, $materials), 'costScope' => isset($selectedIntervention) ? 'Corrección '.$selectedIntervention->number : 'Reparación original'])
                @endif
                <div class="mw-assessment-columns notification-review-columns">
                    <section class="notification-review-text" aria-labelledby="reviewText{{ $step }}">
                        <h3 id="reviewText{{ $step }}">{{ $textLabel }}</h3>
                        <div class="notification-review-prose" tabindex="0">{{ $text ?: 'Sin información registrada.' }}</div>
                    </section>
                    <section class="notification-review-photos" aria-labelledby="reviewPhotos{{ $step }}">
                        <h3 id="reviewPhotos{{ $step }}">{{ $photoLabel }}</h3>
                        @include('notifications.evidence-gallery', ['photos' => $evidence->where('stage', $stage)->values(), 'label' => $photoLabel])
                    </section>
                </div>
            </section>
        @endforeach
        <section role="tabpanel" id="mw-panel-1" aria-labelledby="mw-tab-1" data-maintenance-panel="1" @if($initialStep !== 1) hidden @endif>
            <div class="container-form-section-head"><span>02</span><div><h2>Reparación y materiales</h2></div></div>
            <dl class="notification-review-service-info">
                <div><dt>Tipo de reparación</dt><dd>{{ $job->repair_type ?: 'Sin reparación registrada' }}</dd></div>
                <div><dt>Tarifa del servicio</dt><dd>{{ $job->service_unit_cost === null ? 'Precio pendiente' : ($job->service_currency ?: 'GTQ').' '.number_format($job->service_unit_cost, 2) }}</dd></div>
            </dl>
            <h3 class="notification-review-material-title">Materiales utilizados</h3>
            @if($materials->isNotEmpty())
                <div class="notification-table-wrap" tabindex="0" role="region" aria-label="Materiales utilizados">
                    <table>
                        <thead><tr><th>Material</th><th>Cantidad</th><th>Unidad</th><th>Precio unitario</th><th>Importe</th></tr></thead>
                        <tbody>@foreach($materials as $material)
                            <tr><td>{{ $material->name }}</td><td>{{ rtrim(rtrim(number_format($material->quantity, 3, '.', ''), '0'), '.') }}</td><td>{{ $material->unit }}</td><td>{{ $material->unit_cost === null ? 'Pendiente' : $material->currency.' '.number_format($material->unit_cost, 2) }}</td><td>{{ $material->unit_cost === null ? 'Pendiente' : $material->currency.' '.number_format($material->unit_cost * $material->quantity, 2) }}</td></tr>
                        @endforeach</tbody>
                    </table>
                </div>
            @else
                <p class="notification-review-empty">Sin materiales registrados.</p>
            @endif
        </section>
        <footer class="mw-actions notification-review-navigation">
            <button type="button" class="mw-button" data-review-previous @if($initialStep === 0) hidden @endif><span data-notification-icon="chevron-left" aria-hidden="true"></span>Anterior</button>
            <a class="mw-button" href="{{ $returnUrl }}" data-review-finish @if($initialStep !== 2) hidden @endif>Volver a la vista anterior</a>
            <button type="button" class="mw-button mw-primary" data-review-next @if($initialStep === 2) hidden @endif>Siguiente<span data-notification-icon="chevron-right" aria-hidden="true"></span></button>
        </footer>
    </div>
    <dialog class="mw-photo-dialog notification-review-viewer" data-review-viewer aria-labelledby="reviewViewerTitle">
        <header><h2 id="reviewViewerTitle" data-review-viewer-name></h2><button type="button" class="mw-button mw-icon" data-review-viewer-close aria-label="Cerrar imagen" title="Cerrar imagen"><span data-notification-icon="x" aria-hidden="true"></span></button></header>
        <div class="mw-photo-viewer-image"><img data-review-viewer-image alt=""></div>
    </dialog>
</section>
@endsection
