@extends('layouts.app')
@section('content')
@php
    $photos = collect(json_decode($audit->photos ?? '[]', true))->map(fn ($photo, $index) => (object) [
        'original_name' => $photo['name'],
        'url' => route('pyp-audits.photo', ['container' => $container->id, 'audit' => $audit->id, 'photo' => $index]),
    ]);
@endphp
<section class="page-heading external-audit-heading external-audit-editor-heading">
    <div><span class="eyebrow">Auditoría externa · {{ $externalCompanyName }}</span><h1>{{ $container->nombre }}</h1><p>{{ $container->patio_nombre }} / Bloque {{ $container->bloque_numero }} / Pos. {{ $container->posicion }}</p></div>
    <a class="mw-button" href="{{ $returnUrl }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a>
</section>
<section class="form-panel external-audit-detail" data-correction-history aria-labelledby="auditVerdictTitle">
    @include('partials.audit-verdict', [
        'verdictId' => 'auditVerdict', 'verdictStatus' => $audit->status, 'inspector' => $audit->inspector_name,
        'observedAt' => $audit->created_at, 'shippingLine' => $container->shipping_line_name ?: $container->naviera,
        'notes' => $audit->notes, 'photos' => $photos,
    ])
    <dialog class="mw-photo-dialog notification-review-viewer" data-correction-viewer aria-labelledby="auditEvidencePhotoTitle">
        <header><h2 id="auditEvidencePhotoTitle"></h2><button type="button" class="mw-button mw-icon" data-correction-viewer-close aria-label="Cerrar imagen" title="Cerrar imagen"><span data-notification-icon="x" aria-hidden="true"></span></button></header>
        <div class="mw-photo-viewer-image"><img alt=""></div>
    </dialog>
</section>
@endsection
