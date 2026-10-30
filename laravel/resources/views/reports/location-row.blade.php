<div class="reports-location-row" data-report-location-row>
    <label class="reports-location-field"><span>{{ in_array($type, ['auditorias', 'reparaciones', 'costos'], true) ? 'Patio actual' : 'Patio' }}</span>
        <select name="locations[{{ $locationIndex }}][patio_id]" data-admin-select data-admin-search data-report-yard>
            <option value="">Seleccionar patio</option>
            @foreach($patios as $patio)<option value="{{ $patio->id }}" @selected((int)($location['patio_id'] ?? 0) === (int)$patio->id)>{{ $patio->nombre }}</option>@endforeach
        </select>
    </label>
    <div class="reports-location-field"><span>{{ in_array($type, ['auditorias', 'reparaciones', 'costos'], true) ? 'Bloques actuales' : 'Bloques' }}</span>
        <div class="form-select-dropdown reports-multiselect" data-report-location="blocks">
            <button type="button" class="form-select-trigger" data-report-location-trigger aria-label="Bloques del patio" aria-expanded="false" @disabled(empty($location['patio_id']))><span data-report-location-value>Todos</span><span data-report-icon="down" aria-hidden="true"></span></button>
            <div class="form-select-menu">
                <label><input type="checkbox" data-report-location-all @checked(empty($location['bloque_ids']))><span>Todos los del patio</span></label>
                <div data-report-block-options>
                @foreach(($patios->firstWhere('id', $location['patio_id'] ?? 0)?->bloquesActivos ?? collect()) as $block)
                    <label><input type="checkbox" name="locations[{{ $locationIndex }}][bloque_ids][]" value="{{ $block->id }}" data-report-block-option data-patio="{{ $location['patio_id'] }}" @checked(in_array($block->id, $location['bloque_ids'] ?? [], true))><span>Bloque {{ $block->numero }}</span></label>
                @endforeach
                </div>
            </div>
        </div>
    </div>
    <button type="button" class="reports-icon-button reports-location-remove" data-report-remove-location title="Quitar patio" aria-label="Quitar patio"><span data-report-icon="remove" aria-hidden="true"></span></button>
</div>
