<dialog id="{{ $dialogId }}" class="mw-photo-dialog correction-observation-dialog" data-correction-observation aria-labelledby="{{ $dialogId }}Title">
    <header class="correction-observation-header">
        <div class="correction-observation-title"><span data-notification-icon="shield-check" aria-hidden="true"></span><h2 id="{{ $dialogId }}Title">Solicitud de corrección</h2></div>
        <button type="button" class="mw-button mw-icon" data-correction-observation-close aria-label="Cerrar observación" title="Cerrar observación" autofocus><span data-notification-icon="x" aria-hidden="true"></span></button>
    </header>
    <div class="correction-observation-content external-audit-detail">
        @include('partials.audit-verdict', [
            'verdictId' => $dialogId.'Verdict', 'verdictStatus' => 'Rechazado',
            'inspector' => $inspector, 'observedAt' => $observedAt, 'notes' => $notes, 'shippingLine' => $shippingLine ?? null,
            'photos' => collect($photos)->map(fn (array $photo): object => (object) ['original_name' => $photo['name'], 'url' => $photo['url']]),
        ])
    </div>
</dialog>
