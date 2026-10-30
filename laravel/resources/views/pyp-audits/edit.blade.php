@extends('layouts.app')

@section('content')
    <section class="page-heading external-audit-heading external-audit-editor-heading">
        <div>
            <span class="eyebrow">Auditoría externa · {{ $externalCompanyName }}</span>
            <h1>{{ $container->nombre }}</h1>
            <p>{{ $container->patio_nombre }} / Bloque {{ $container->bloque_numero }} / Pos. {{ $container->posicion }}</p>
        </div>
        <div class="external-audit-heading-actions">
            @if($maintenanceJob && $maintenanceJob->status !== 'in_progress')
                <a class="mw-button" href="{{ route('corrections.detail', array_filter(['job' => $maintenanceJob->id, 'intervention' => $maintenanceCorrectionId, 'return_to' => request()->getRequestUri()])) }}"><span data-notification-icon="wrench" aria-hidden="true"></span>{{ $maintenanceCorrectionId ? 'Ver corrección' : 'Ver reparación' }}</a>
            @endif
            <a href="{{ $returnUrl ?? route('pyp-audits.index', $backParams) }}" class="mw-button"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a>
        </div>
    </section>

    @if ($errors->any())
        <div class="form-error page-error" role="alert">{{ $errors->first() }} Las fotos nuevas deben seleccionarse de nuevo.</div>
    @endif
    <section class="form-panel external-audit-editor" aria-labelledby="externalAuditTitle" data-correction-history>
        <form method="POST" action="{{ route('pyp-audits.update', ['container' => $container->id]) }}" enctype="multipart/form-data" class="record-form record-select-fields external-audit-form" data-external-audit-form @if($canInspect) data-unsaved-form="auditExitDialog" data-restored-changes="{{ $errors->any() ? 'true' : 'false' }}" @endif>
            @csrf
            @method('PATCH')
            @if($maintenanceJob)<input type="hidden" name="maintenance_version" value="{{ $maintenanceJob->version }}">@endif
            <input type="hidden" name="audit_version" value="{{ $container->audit_revision }}">
            @if(!empty($returnUrl))<input type="hidden" name="return_to" value="{{ $returnUrl }}">@endif

            <input type="hidden" name="patio_id" value="{{ $backParams['patio_id'] ?? $container->patio_id }}">
            <input type="hidden" name="bloque_id" value="{{ $backParams['bloque_id'] ?? $container->bloque_id }}">

            <fieldset class="external-audit-entry" @disabled(!$canInspect)>
            <legend class="mw-sr-only">Registrar dictamen de auditoría</legend>
            <header class="external-audit-review-header">
                <div class="external-audit-review-heading">
                    <div class="external-audit-editor-title"><h2 id="externalAuditTitle">Revisión del contenedor</h2><span class="status-badge badge-warning">En revisión</span></div>
                    <p class="external-audit-review-meta">{{ auth()->user()->username }}@if($container->fecha_registro) · Último guardado: <time datetime="{{ \Carbon\Carbon::parse($container->fecha_registro, 'UTC')->toIso8601String() }}">{{ \Carbon\Carbon::parse($container->fecha_registro, 'UTC')->timezone('America/Guatemala')->format('d/m/Y H:i') }}</time>@endif</p>
                </div>
            <label class="external-audit-result"><span>Resultado</span><select name="estatus_pyp" required data-admin-select><option value="" disabled hidden @selected(!old('estatus_pyp'))>Seleccionar resultado</option>@foreach(\App\Services\PypAuditService::RESULTS as $state)<option value="{{ $state }}" @selected(old('estatus_pyp') === $state)>{{ $state }}</option>@endforeach</select></label>
            </header>
            <div class="external-audit-assessment">
            <label class="external-audit-notes">
                <span>Observaciones de la revisión</span>
                <textarea name="informacion_adicional" minlength="30" maxlength="2000" rows="8" required aria-describedby="auditNotesLimit" data-audit-notes>{{ old('informacion_adicional', $container->informacion_adicional) }}</textarea>
                <small id="auditNotesLimit" class="external-audit-notes-limit">Mínimo 30 · <span data-audit-count>{{ mb_strlen(old('informacion_adicional', $container->informacion_adicional) ?? '') }}</span> / 2000 caracteres</small>
            </label>
            <section class="mw-assessment-section" aria-labelledby="auditPhotosTitle" data-correction-photo-group data-photo-limit-message="Puedes adjuntar hasta 5 fotos por auditoría.">
                <div class="mw-photo-heading"><h3 id="auditPhotosTitle" class="mw-photo-section-label">Fotos de la revisión</h3></div>
                <div class="mw-photo-upload"><button type="button" class="mw-button" data-correction-photo-add><span data-notification-icon="plus" aria-hidden="true"></span>Adjuntar fotos</button><input class="mw-photo-native" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple data-correction-photos aria-label="Fotos de la revisión" aria-describedby="auditPhotosHelp"></div>
                <p id="auditPhotosHelp" class="external-audit-photo-help">Hasta 5 fotos JPG, PNG o WEBP de 2 MB cada una. Al rechazar, adjunta al menos una foto del defecto.</p>
                <p class="mw-error" data-correction-photo-error role="alert" hidden></p>
                @php
                    $photos = collect(json_decode($photoReview->after_data ?? '{}', true)['audit']['photos'] ?? [])->map(fn ($photo, $index) => (object) [
                        'id' => $index, 'mime' => $photo['mime'], 'original_name' => $photo['name'],
                        'url' => route('pyp-audits.photo', ['container' => $container->id, 'audit' => $photoReview->id, 'photo' => $index]),
                    ]);
                @endphp
                @include('maintenance.photo-carousel', ['photos' => $photos, 'label' => 'Fotos de la revisión', 'correctionField' => 'photos'])
            </section>
            </div>
            <div class="external-audit-final-row">
                @if($canInspect)<div class="external-audit-final-actions"><button type="submit" name="action" value="save" formnovalidate class="mw-button"><span data-notification-icon="archive" aria-hidden="true"></span>Guardar avance</button><button type="submit" name="action" value="finalize" class="mw-button mw-primary"><span data-notification-icon="check-check" aria-hidden="true"></span>Finalizar auditoría</button></div>@endif
            </div>
            </fieldset>
            @unless($canInspect)<span class="external-audit-blocked" role="status"><span data-notification-icon="shield-check" aria-hidden="true"></span>{{ $maintenanceJob && $maintenanceJob->status === 'returned_for_repair' ? 'Esperando la corrección del mecánico.' : (($maintenanceJob && $maintenanceJob->status !== 'completed') ? 'Esperando la entrega y confirmación del trabajo.' : 'Esperando liberación IPSA.') }}</span>@endunless
        </form>
        <dialog class="mw-photo-dialog notification-review-viewer" data-correction-viewer aria-labelledby="auditPhotoTitle">
            <header><h2 id="auditPhotoTitle"></h2><button type="button" class="mw-button mw-icon" data-correction-viewer-close aria-label="Cerrar imagen" title="Cerrar imagen"><span data-notification-icon="x" aria-hidden="true"></span></button></header>
            <div class="mw-photo-viewer-image"><img alt=""></div>
        </dialog>
    </section>
    @if($canInspect) @include('partials.unsaved-changes', ['exitDialogId' => 'auditExitDialog']) @endif
@endsection
