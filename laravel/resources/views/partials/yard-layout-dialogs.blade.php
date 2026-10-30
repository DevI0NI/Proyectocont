    <dialog class="container-exit-dialog action-modal" id="yardEditorDialog" aria-labelledby="yardEditorTitle">
        <span class="eyebrow">Patios y bloques</span><h2 id="yardEditorTitle">Nuevo patio</h2>
        <form method="POST" class="record-form" data-yard-save id="yardEditorForm" novalidate>
            @csrf<input type="hidden" name="_method" value="POST"><input type="hidden" name="patio_id" value="{{ $selectedPatio?->id }}">
            <div data-yard-name-field><label><span>Nombre del patio</span><input type="text" name="nombre" maxlength="255" autocomplete="off"></label></div>
            <div data-yard-capacity-field hidden><label><span>Capacidad del bloque</span><input type="number" name="capacidad_maxima" min="1" max="25" step="1" value="25" disabled><small class="table-muted" data-yard-capacity-hint>De 1 a 25 contenedores.</small></label></div>
            <div class="container-exit-dialog-actions"><button type="button" class="button secondary" data-yard-close>Cancelar</button><button type="submit" class="button primary" data-yard-save-label>Crear patio</button></div>
        </form>
    </dialog>
    <dialog class="container-exit-dialog action-modal" id="yardActionDialog" aria-labelledby="yardActionTitle" aria-describedby="yardActionDescription">
        <span class="container-exit-dialog-kicker">Desactivar</span><h2 id="yardActionTitle"></h2><p id="yardActionDescription"></p>
        <form method="POST" data-yard-save novalidate>@csrf @method('DELETE')
            <div class="container-exit-dialog-actions"><button type="button" class="button secondary" data-yard-close>Cancelar</button><button type="submit" class="button container-exit-leave">Desactivar</button></div>
        </form>
    </dialog>
