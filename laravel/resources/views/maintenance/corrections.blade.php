@extends('layouts.app')
@section('content')
@php
    $initialStep = $errors->hasAny(['diagnosis', 'photos_before', 'photos_before.*']) ? 0
        : ($errors->hasAny(['repair_service_id', 'materials', 'materials.*']) ? 1
        : ($errors->hasAny(['work', 'work_performed', 'photos', 'photos.*']) ? 2
        : max(0, min(2, (int) old('active_step', request()->integer('step', 0))))));
@endphp
<section class="page-heading mw-repair-heading notification-review-heading">
    <div><span class="eyebrow">Corregir reparación</span><h1>{{ $job->container_name }}</h1><p>{{ $job->repair_type ?: 'Reparación' }}@if($draft) · Corrección {{ $draft->number }}@endif</p></div>
    <div class="repair-history-heading-actions"><a class="mw-button" href="{{ route('corrections.detail', ['job' => $job->id, 'return_to' => request()->getRequestUri()]) }}"><span data-notification-icon="wrench" aria-hidden="true"></span>Ver reparación</a><a class="mw-button" href="{{ $returnUrl }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a></div>
</section>
@if($errors->any())<div class="form-error page-error" role="alert">{{ $errors->first() }} Las fotos nuevas deben seleccionarse de nuevo.</div>@endif
<section class="workboard mw-board mw-repair-board correction-workbench" data-correction-history aria-label="Atender corrección de la auditora">
        @if($draft)
                @php($round = $draft)
                    <form class="mw-form mw-repair-form correction-form record-select-fields" method="POST" action="{{ route('corrections.update', $job->id) }}" enctype="multipart/form-data" data-correction-form data-external-audit-form data-unsaved-form="correctionExitDialog" data-restored-changes="{{ $errors->any() ? 'true' : 'false' }}" data-initial-step="{{ $initialStep }}" data-exchange-rate="{{ $exchangeQuote['rate'] ?? '' }}" data-exchange-date="{{ $exchangeQuote['date'] ?? '' }}" novalidate>@csrf @method('PATCH')
                        @if(!empty($exchangeQuote['token']))<input type="hidden" name="exchange_quote" value="{{ $exchangeQuote['token'] }}">@endif
                        <input type="hidden" name="round_id" value="{{ $round->id }}"><input type="hidden" name="version" value="{{ $round->version }}"><input type="hidden" name="return_to" value="{{ $returnUrl }}"><input type="hidden" name="active_step" value="{{ $initialStep }}">
                        <div class="notification-review-toolbar has-interventions correction-toolbar">
                            @include('maintenance.timeline')
                            <div class="notification-review-options">
                                <button type="button" class="mw-button" data-correction-observation-open aria-haspopup="dialog" aria-controls="correctionObservation"><span data-notification-icon="shield-check" aria-hidden="true"></span>Solicitud de corrección</button>
                            </div>
                        </div>
                        <section role="tabpanel" id="mw-panel-0" aria-labelledby="mw-tab-0" data-maintenance-panel="0" data-photo-panel @if($initialStep !== 0) hidden @endif>
                            <div class="container-form-section-head"><span>01</span><div><h2>Evaluación inicial</h2></div></div>
                            <div class="mw-assessment-columns">
                                <div class="mw-field correction-work-field"><label for="correctionDiagnosis">Diagnóstico y ubicación del daño</label><textarea id="correctionDiagnosis" name="diagnosis" rows="7" maxlength="2000" data-correction-text>{{ old('diagnosis', $round->diagnosis) }}</textarea><small>Mínimo 30 al enviar · <span data-correction-count>{{ mb_strlen(old('diagnosis', $round->diagnosis) ?? '') }}</span> / 2000 caracteres</small></div>
                                @include('maintenance.correction-photos', ['field' => 'photos_before', 'stage' => 'before', 'title' => 'Fotos de evidencia inicial'])
                            </div>
                        </section>
                        <section role="tabpanel" id="mw-panel-1" aria-labelledby="mw-tab-1" data-maintenance-panel="1" @if($initialStep !== 1) hidden @endif>
                        <div class="container-form-section-head"><span>02</span><div><h2>Reparación y materiales</h2></div></div>
                        <div class="mw-repair-workspace"><div class="mw-repair-details">
                        <div class="mw-service-pricing"><label class="mw-field"><span>Tipo de reparación</span><select name="repair_service_id" data-admin-select data-admin-search><option value="">Selecciona una reparación</option>@foreach($repairServices as $service)<option value="{{ $service->id }}" data-cost="{{ $service->reference_cost }}" data-currency="{{ $service->currency }}" @selected((int) old('repair_service_id', $round->repair_service_id) === (int) $service->id)>{{ $service->name }}@if((int) $service->status !== 1) · Inactiva @endif</option>@endforeach</select></label></div>
                        <section class="correction-material-editor" aria-label="Materiales adicionales">
                        <div class="mw-section-title"><h3>Materiales adicionales</h3><button type="button" class="mw-button" data-correction-add><span data-notification-icon="plus" aria-hidden="true"></span>Agregar material</button></div>
                        <div class="mw-material-head" data-correction-material-head @if(!count(old('materials', json_decode($round->materials ?? '[]', true)))) hidden @endif><span>Material</span><span>Cantidad</span><span>Precio unitario</span><span>Importe</span><span class="mw-sr-only">Acciones</span></div>
                        <div data-correction-materials>
                            @foreach(old('materials', json_decode($round->materials ?? '[]', true)) as $index => $material)
                                @include('maintenance.correction-material', ['index' => $index, 'material' => $material])
                            @endforeach
                        </div>
                        <template data-correction-template>@include('maintenance.correction-material', ['index' => '__INDEX__', 'material' => []])</template>
                        <p class="mw-material-empty" data-correction-material-empty @if(count(old('materials', json_decode($round->materials ?? '[]', true)))) hidden @endif>Sin materiales adicionales.</p>
                        </section>
                        </div><aside class="mw-cost-summary" aria-labelledby="correctionEstimateTitle"><h3 id="correctionEstimateTitle">Estimación</h3>
                            <dl class="mw-estimate" aria-live="polite"><div><dt>Tarifa del servicio</dt><dd data-estimate-labor>—</dd></div><div><dt>Materiales</dt><dd data-estimate-materials>—</dd></div><div><dt>Total estimado</dt><dd data-estimate-total>—</dd></div></dl>
                            <p class="mw-exchange-note" data-service-original hidden></p><p class="mw-exchange-note" data-exchange-notice hidden></p><p class="mw-estimate-pending" data-estimate-pending hidden></p>
                        </aside></div>
                        </section>
                        <section role="tabpanel" id="mw-panel-2" aria-labelledby="mw-tab-2" data-maintenance-panel="2" data-photo-panel @if($initialStep !== 2) hidden @endif>
                            <div class="container-form-section-head"><span>03</span><div><h2>Resultado de la reparación</h2></div></div>
                            @include('maintenance.calculated-cost', ['costScope' => 'Corrección '.$round->number])
                            <div class="mw-assessment-columns">
                                <div class="mw-field correction-work-field"><label for="correctionWork">Trabajo de corrección realizado</label><textarea id="correctionWork" name="work_performed" rows="7" maxlength="2000" data-correction-work data-correction-text>{{ old('work_performed', $round->work_performed) }}</textarea><small>Mínimo 30 al enviar · <span data-correction-work-count data-correction-count>{{ mb_strlen(old('work_performed', $round->work_performed) ?? '') }}</span> / 2000 caracteres</small></div>
                                @include('maintenance.correction-photos', ['field' => 'photos', 'stage' => 'after', 'title' => 'Fotos de evidencia final'])
                            </div>
                        </section>
                        <footer class="mw-actions mw-step-actions">
                            <button type="button" class="mw-button" data-correction-previous @if($initialStep === 0) hidden @endif><span data-notification-icon="chevron-left" aria-hidden="true"></span>Anterior</button>
                            <button class="mw-button" name="action" value="save" type="submit"><span data-notification-icon="archive" aria-hidden="true"></span>Guardar borrador</button>
                            <button type="button" class="mw-button mw-primary" data-correction-next @if($initialStep === 2) hidden @endif>Siguiente<span data-notification-icon="chevron-right" aria-hidden="true"></span></button>
                            <button class="mw-button mw-primary" name="action" value="submit" type="submit" data-correction-submit @if($initialStep !== 2) hidden @endif><span data-notification-icon="send" aria-hidden="true"></span>Enviar a auditoría</button>
                        </footer>
                    </form>
        @else
            <p class="notification-body">No hay una corrección pendiente para este trabajo.</p>
        @endif
    @if($draft)
        @include('maintenance.audit-observation', [
            'dialogId' => 'correctionObservation', 'statusLabel' => 'Por corregir · Corrección '.$draft->number,
            'inspector' => $draft->requested_by_name ?? 'Auditora externa', 'observedAt' => $draft->created_at, 'notes' => $draft->reason,
            'photos' => collect(json_decode($draft->audit_photos ?? '[]', true))->map(fn ($photo, $index) => [
                'name' => $photo['name'], 'url' => route('corrections.audit-photo', ['job' => $job->id, 'round' => $draft->id, 'photo' => $index]),
            ])->all(),
        ])
    @endif
    <dialog class="mw-photo-dialog notification-review-viewer" data-correction-viewer aria-labelledby="correctionPhotoTitle">
        <header><h2 id="correctionPhotoTitle"></h2><button type="button" class="mw-button mw-icon" data-correction-viewer-close aria-label="Cerrar imagen" title="Cerrar imagen"><span data-notification-icon="x" aria-hidden="true"></span></button></header>
        <div class="mw-photo-viewer-image"><img alt=""></div>
    </dialog>
</section>
@if($draft) @include('partials.unsaved-changes', ['exitDialogId' => 'correctionExitDialog']) @endif
@endsection
