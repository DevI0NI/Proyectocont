@extends('layouts.app')
@section('content')
<section class="page-heading external-audit-heading external-audit-editor-heading">
    <div><span class="eyebrow">Solicitud de corrección {{ $selectedIntervention->number }}</span><h1>{{ $job->container_name }}</h1><p>{{ $job->repair_type ?: 'Sin tipo registrado' }} · Por corregir</p></div>
    <a class="mw-button" href="{{ $returnUrl }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a>
</section>
<section class="form-panel external-audit-detail" data-correction-history aria-labelledby="correctionRequestVerdictTitle">
    @include('partials.audit-verdict', array_merge($requestContext, [
        'verdictId' => 'correctionRequestVerdict', 'verdictStatus' => 'Rechazado',
        'photos' => collect($requestContext['photos'])->map(fn (array $photo): object => (object) ['original_name' => $photo['name'], 'url' => $photo['url']]),
    ]))
    <dialog class="mw-photo-dialog notification-review-viewer" data-correction-viewer aria-labelledby="correctionRequestPhotoTitle">
        <header><h2 id="correctionRequestPhotoTitle"></h2><button type="button" class="mw-button mw-icon" data-correction-viewer-close aria-label="Cerrar imagen" title="Cerrar imagen"><span data-notification-icon="x" aria-hidden="true"></span></button></header>
        <div class="mw-photo-viewer-image"><img alt=""></div>
    </dialog>
</section>
@endsection
