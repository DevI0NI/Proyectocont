<div class="mw-material-row" data-correction-material-row data-material-row>
    <label class="mw-field"><span>Material</span><select name="materials[{{ $index }}][part_id]" data-admin-select data-admin-search><option value="">Selecciona un material</option>@foreach($parts as $part)<option value="{{ $part->id }}" data-cost="{{ $part->reference_cost }}" data-currency="{{ $part->currency }}" @selected((int) ($material['part_id'] ?? 0) === (int) $part->id)>{{ $part->name }} ({{ $part->unit }})</option>@endforeach</select></label>
    <label class="mw-field"><span>Cantidad</span><input type="number" name="materials[{{ $index }}][quantity]" min="0.001" max="99999" step="0.001" value="{{ $material['quantity'] ?? 1 }}"></label>
    <div class="mw-field mw-price"><span>Precio unitario</span><output data-material-cost>—</output></div>
    <div class="mw-field mw-price"><span>Importe</span><output data-material-subtotal>—</output></div>
    <button class="mw-button mw-icon" type="button" data-correction-remove aria-label="Quitar material" title="Quitar material"><span data-notification-icon="x" aria-hidden="true"></span></button>
</div>
