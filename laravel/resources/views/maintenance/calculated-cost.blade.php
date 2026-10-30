<section class="mw-calculated-cost" aria-label="Costo calculado">
    <h3>Costo calculado</h3>
    <p>{{ $costScope ?? 'Reparación original' }}</p>
    <dl class="mw-estimate" aria-live="polite">
        <div><dt>Tarifa del servicio</dt><dd data-calculated-labor>{{ $calculatedCost['labor'] ?? '—' }}</dd></div>
        <div><dt>Materiales utilizados</dt><dd data-calculated-materials>{{ $calculatedCost['materials'] ?? '—' }}</dd></div>
        <div><dt>Total calculado</dt><dd data-calculated-total>{{ $calculatedCost['total'] ?? '—' }}</dd></div>
    </dl>
    <p class="mw-estimate-pending" data-calculated-pending @if(!($calculatedCost['incomplete'] ?? false)) hidden @endif>Costo incompleto: faltan precios, cantidades o conversión de moneda.</p>
</section>
