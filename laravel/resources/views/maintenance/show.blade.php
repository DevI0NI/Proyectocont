@extends('layouts.app')
@section('content')
@php
    $starting = $starting ?? false;
    $materialValues = old('materials', $materials->map(fn($item) => ['part_id' => $item->part_id, 'quantity' => $item->quantity])->all());
    $afterPhotos = $evidence->where('stage', 'after');
    $beforePhotos = $evidence->where('stage', 'before');
    $initialStep = $errors->hasAny(['diagnosis', 'evidence_before', 'evidence_before.*']) ? 0 : ($errors->hasAny(['work_performed', 'evidence', 'evidence_after', 'evidence_after.*']) ? 2 : max(0, min(2, (int) old('active_step', request()->integer('step', $starting ? 0 : 1)))));
@endphp
<section class="page-heading mw-repair-heading"><div><span class="eyebrow">Mantenimiento interno</span><h1>{{ $starting ? 'Reparar ' : '' }}{{ $job->container_name }}</h1>
<div class="mw-heading-status">
    <span class="mw-work-status" @if(!$starting) data-state="{{ $job->status }}" @endif>{{ $starting ? 'Por atender' : match($job->status) { 'pending_review' => 'Pendiente de revisión', 'completed' => 'Reparación finalizada', 'returned_for_repair' => 'Devuelto a reparación', default => 'En reparación' } }}</span>
    @if($review)<span>Solicitud {{ str_pad((string) $review->folio, 3, '0', STR_PAD_LEFT) }}</span><time datetime="{{ \Carbon\Carbon::parse($review->created_at)->toIso8601String() }}">{{ \Carbon\Carbon::parse($review->created_at)->format('d/m/Y H:i') }}</time>@endif
</div>
@if(!$editable && !empty($readonlyReason))<p class="mw-heading-readonly">{{ $readonlyReason }}</p>@endif
</div><a class="mw-button" href="{{ $returnUrl ?? route('maintenance.orders', $backParams ?? []) }}"><span data-catalog-icon="arrow-left" aria-hidden="true"></span>Volver</a></section>
<section class="workboard mw-board mw-repair-board">
@if(!$starting && $job->status !== 'in_progress')
    <div class="mw-actions"><a class="mw-button" href="{{ route('corrections.history', ['job' => $job->id, 'return_to' => request()->getRequestUri()]) }}">Reparaciones<span data-catalog-icon="arrow-right" aria-hidden="true"></span></a>@if($job->status === 'returned_for_repair')<a class="mw-button mw-primary" href="{{ route('corrections.show', ['job' => $job->id, 'return_to' => request()->getRequestUri()]) }}">Atender corrección de la auditora<span data-catalog-icon="arrow-right" aria-hidden="true"></span></a>@endif</div>
@endif
@if($errors->any())<div class="mw-errors" role="alert"><strong>No se pudo guardar el trabajo</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul><p>Los archivos nuevos deben seleccionarse otra vez.</p></div>@endif
<form method="POST" action="{{ $starting ? route('maintenance.store') : route('maintenance.update', $job->id) }}" enctype="multipart/form-data" class="mw-form mw-repair-form record-select-fields" data-maintenance-form data-initial-step="{{ $initialStep }}" data-exchange-rate="{{ $exchangeQuote['rate'] ?? '' }}" data-exchange-date="{{ $exchangeQuote['date'] ?? '' }}" data-restored-changes="{{ $errors->any() ? 'true' : 'false' }}" novalidate>@csrf
@if(!empty($exchangeQuote['token']))<input type="hidden" name="exchange_quote" value="{{ $exchangeQuote['token'] }}">@endif
@if($starting)<input type="hidden" name="container_id" value="{{ $container->id }}">@else @method('PATCH') @endif
<input type="hidden" name="version" value="{{ $job->version }}">
<input type="hidden" name="active_step" value="{{ $initialStep }}">
@if(!empty($returnUrl))<input type="hidden" name="return_to" value="{{ $returnUrl }}">@endif
@foreach($backParams ?? [] as $key => $value)<input type="hidden" name="back[{{ $key }}]" value="{{ $value }}">@endforeach
@include('maintenance.timeline')
<section role="tabpanel" id="mw-panel-0" aria-labelledby="mw-tab-0" data-maintenance-panel="0" data-photo-panel hidden>
    <div class="container-form-section-head"><span>01</span><div><h2>Evaluación inicial</h2></div></div>
    <div class="mw-assessment-columns">
        <div class="mw-field"><label for="diagnosis">Diagnóstico y ubicación del daño</label><textarea id="diagnosis" name="diagnosis" maxlength="1000" rows="7" placeholder="Falla encontrada, ubicación y reparación necesaria" @disabled(!$editable)>{{ old('diagnosis', $job->diagnosis) }}</textarea></div>
        <section class="mw-assessment-section" aria-labelledby="beforePhotosTitle" data-maintenance-work-photos>
            <div class="mw-photo-heading"><h3 id="beforePhotosTitle" class="mw-photo-section-label">Fotos de evidencia inicial</h3><span class="plate-help"><button type="button" class="plate-help-trigger" aria-label="Requisitos de las fotos iniciales" aria-describedby="beforePhotosHelp" data-field-help data-help-touch></button><span class="plate-help-tooltip" id="beforePhotosHelp" role="tooltip" hidden>De 1 a 5 fotos. JPG, PNG o WEBP; máximo 2 MB por foto.</span></span></div>
            <div class="mw-photo-upload" @if(!$editable) hidden @endif>
                <button type="button" class="mw-button" data-initial-photo-add @disabled(!$editable || $beforePhotos->count() >= 5)><span data-maintenance-photo-icon="add" aria-hidden="true"></span>Adjuntar fotos</button>
                <input class="mw-photo-native" type="file" name="evidence_before[]" accept="image/jpeg,image/png,image/webp" multiple tabindex="-1" aria-label="Fotos de evidencia inicial" aria-describedby="beforePhotosHelp beforePhotosError" data-initial-photos data-existing-count="{{ $beforePhotos->count() }}" @disabled(!$editable)>
            </div>
            <p class="mw-error" id="beforePhotosError" role="alert" data-initial-photo-error hidden></p>
            @include('maintenance.photo-carousel', ['photos' => $beforePhotos, 'label' => 'Fotos de evidencia inicial'])
        </section>
    </div>
</section>
<section role="tabpanel" id="mw-panel-1" aria-labelledby="mw-tab-1" data-maintenance-panel="1" hidden>
<div class="container-form-section-head"><span>02</span><div><h2>Reparación y materiales</h2></div></div>
<div class="mw-repair-workspace"><div class="mw-repair-details">
<div class="mw-service-pricing">
<label class="mw-field"><span>Tipo de reparación</span><select id="repairType" name="repair_service_id" data-admin-select data-admin-search @disabled(!$editable)><option value="">Selecciona una reparación</option>@foreach($repairServices as $service)<option value="{{ $service->id }}" data-cost="{{ $service->reference_cost }}" data-currency="{{ $service->currency }}" data-unit="{{ $service->unit }}" @selected((int) old('repair_service_id', $job->repair_service_id) === (int) $service->id)>{{ $service->name }}@if((int) $service->status !== 1) · Inactiva @endif</option>@endforeach</select></label>
</div>
<div class="mw-section-title"><h2>Materiales utilizados</h2>@if($editable)<button type="button" class="mw-button" data-material-add><span data-catalog-icon="plus" aria-hidden="true"></span>Agregar material</button>@endif</div>
<div class="mw-material-head" data-material-head @if(!count($materialValues)) hidden @endif><span>Material</span><span>Cantidad</span><span>Precio unitario</span><span>Importe</span><span class="mw-sr-only">Acciones</span></div>
<div class="mw-materials" data-material-list>@foreach($materialValues as $index => $material)@include('maintenance.material-row', ['index' => $index, 'material' => $material])@endforeach</div>
<p class="mw-material-empty" @if(count($materialValues)) hidden @endif>Sin materiales registrados.</p>
@if($editable)<template data-material-template>@include('maintenance.material-row', ['index' => '__INDEX__', 'material' => []])</template>@endif
</div><aside class="mw-cost-summary" aria-labelledby="repairEstimateTitle"><h3 id="repairEstimateTitle">Estimación</h3>
<dl class="mw-estimate" aria-live="polite"><div><dt>Tarifa del servicio</dt><dd data-estimate-labor data-service-cost>—</dd></div><div><dt>Materiales</dt><dd data-estimate-materials>—</dd></div><div><dt>Total estimado</dt><dd data-estimate-total>—</dd></div></dl>
<p class="mw-exchange-note" data-service-original hidden></p>
<p class="mw-exchange-note" data-exchange-notice hidden></p>
<p class="mw-estimate-pending" data-estimate-pending hidden></p>
</aside></div>
</section>
<section role="tabpanel" id="mw-panel-2" aria-labelledby="mw-tab-2" data-maintenance-panel="2" data-photo-panel hidden>
<div class="container-form-section-head"><span>03</span><div><h2>Resultado de la reparación</h2></div></div>
<div class="mw-assessment-columns">
    <div class="mw-field"><label for="workPerformed">Trabajo realizado</label><textarea name="work_performed" id="workPerformed" maxlength="8000" rows="7" placeholder="Explica qué se reparó, cómo quedó y el resultado de las pruebas." @disabled(!$editable)>{{ old('work_performed', $job->work_performed) }}</textarea></div>
    <section class="mw-assessment-section" aria-labelledby="finalPhotosTitle" data-maintenance-work-photos>
        <div class="mw-photo-heading"><h3 id="finalPhotosTitle" class="mw-photo-section-label">Fotos de evidencia final</h3><span class="plate-help"><button type="button" class="plate-help-trigger" aria-label="Requisitos de las fotos finales" aria-describedby="initialPhotosHelp" data-field-help data-help-touch></button><span class="plate-help-tooltip" id="initialPhotosHelp" role="tooltip" hidden>De 1 a 5 fotos. JPG, PNG o WEBP; máximo 2 MB por foto.</span></span></div>
        <div class="mw-photo-upload" @if(!$editable) hidden @endif>
            <button type="button" class="mw-button" data-initial-photo-add @disabled(!$editable || $afterPhotos->count() >= 5)><span data-maintenance-photo-icon="add" aria-hidden="true"></span>Adjuntar fotos</button>
            <input class="mw-photo-native" type="file" name="evidence_after[]" accept="image/jpeg,image/png,image/webp" multiple tabindex="-1" aria-label="Fotos de evidencia final" aria-describedby="initialPhotosHelp initialPhotosError" data-initial-photos data-existing-count="{{ $afterPhotos->count() }}" @disabled(!$editable)>
        </div>
        <p class="mw-error" id="initialPhotosError" role="alert" data-initial-photo-error hidden></p>
        @include('maintenance.photo-carousel', ['photos' => $afterPhotos, 'label' => 'Fotos de evidencia final'])
    </section>
</div>
</section>
<footer class="mw-actions mw-step-actions">
    <button type="button" class="mw-button" data-maintenance-previous><span data-catalog-icon="arrow-left" aria-hidden="true"></span>Anterior</button>
    @if($editable)<button class="mw-button" name="action" value="save" type="submit" data-maintenance-save hidden><span data-catalog-icon="save" aria-hidden="true"></span>Guardar cambios</button>@endif
    <button type="button" class="mw-button mw-primary" data-maintenance-next>Siguiente<span data-catalog-icon="arrow-right" aria-hidden="true"></span></button>
    @if($editable)<button class="mw-button mw-primary" name="action" value="submit" type="submit" data-maintenance-submit hidden><span data-catalog-icon="send" aria-hidden="true"></span>Solicitar revisión</button>@endif
</footer>
</form></section>
@include('maintenance.photo-viewer')
<dialog class="container-exit-dialog" data-maintenance-exit role="alertdialog" aria-labelledby="maintenanceExitTitle" aria-describedby="maintenanceExitDescription">
    <span class="container-exit-dialog-kicker">Cambios sin guardar</span>
    <h2 id="maintenanceExitTitle">¿Salir sin guardar?</h2>
    <p id="maintenanceExitDescription">Los cambios realizados en esta reparación se perderán.</p>
    <div class="container-exit-dialog-actions">
        <button type="button" class="button container-exit-stay" data-maintenance-stay>Seguir editando</button>
        <a href="{{ $returnUrl ?? route('maintenance.orders', $backParams ?? []) }}" class="button container-exit-leave" data-maintenance-leave>Salir sin guardar</a>
    </div>
</dialog>
@endsection
