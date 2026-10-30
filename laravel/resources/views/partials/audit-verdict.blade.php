<header class="external-audit-detail-head">
    <h2 id="{{ $verdictId }}Title"><span data-notification-icon="shield-check" aria-hidden="true"></span>Dictamen de auditoría</h2>
    <span class="status-badge {{ $verdictStatus === 'Aprobado' ? 'badge-success' : 'badge-danger' }}">{{ $verdictStatus }}</span>
</header>
<dl class="external-audit-detail-meta">
    <div><dt>Inspector</dt><dd>{{ $inspector }}</dd></div>
    <div><dt>Fecha del dictamen</dt><dd><time datetime="{{ \Carbon\Carbon::parse($observedAt, 'UTC')->toIso8601String() }}">{{ \Carbon\Carbon::parse($observedAt, 'UTC')->timezone('America/Guatemala')->format('d/m/Y H:i') }}</time></dd></div>
    <div><dt>Naviera del ingreso</dt><dd>{{ $shippingLine ?: 'Sin registrar' }}</dd></div>
</dl>
<div class="external-audit-detail-columns">
    <section aria-labelledby="{{ $verdictId }}Notes"><h3 id="{{ $verdictId }}Notes">Observaciones de la revisión</h3><div class="external-audit-detail-prose" tabindex="0">{{ $notes ?: 'Sin observaciones registradas.' }}</div></section>
    <section aria-labelledby="{{ $verdictId }}Photos"><h3 id="{{ $verdictId }}Photos">Fotos de la revisión <span>{{ $photos->count() }}</span></h3>
        @include('notifications.evidence-gallery', ['photos' => $photos, 'label' => 'Fotos de la revisión', 'emptyMessage' => 'Sin fotografías adjuntas.'])
    </section>
</div>
