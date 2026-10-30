@php
    $editing = isset($record['id']);
    $repair = $catalog === 'reparaciones';
    $units = \App\Services\OperationalCatalog::PART_UNITS;
    if (!empty($record['unit']) && !in_array($record['unit'], $units, true)) {
        $units[] = $record['unit'];
    }
@endphp
<section class="page-heading standard-records-heading user-editor-heading"><div><span class="eyebrow">{{ $definition['title'] }}</span><h1>{{ ($editing ? 'Editar ' : ($repair ? 'Nueva ' : 'Nuevo ')).$definition['singular'] }}</h1></div></section>
<section class="form-panel container-form-shell user-editor" data-shipping-lines>
    @if($errors->any())<script type="application/json" data-shipping-line-errors>@json($errors->all())</script>@endif
    <form method="POST" class="record-form container-form" data-shipping-line-form data-catalog-item-form data-return-url="{{ route('catalogs.index', $catalog) }}" action="{{ $editing ? route('catalogs.update', ['catalog' => $catalog, 'record' => $record['id']]) : route('catalogs.store', $catalog) }}" novalidate>
        @csrf @if($editing) @method('PATCH') @endif
        <div class="user-field-grid record-select-fields">
            <label><span>Codigo</span><input class="catalog-code" value="{{ $record['code'] ?? '' }}" readonly placeholder="Automatico al guardar" aria-label="{{ $repair ? 'Codigo automatico de la reparacion' : 'Codigo automatico del repuesto' }}"></label>
            <label><span>Nombre</span><input name="name" value="{{ old('name', $record['name'] ?? '') }}" maxlength="120" required placeholder="{{ $repair ? 'Nombre de la reparacion' : 'Nombre del repuesto' }}"></label>
            @if(!$repair)<label><span>Unidad de medida</span><select name="unit" data-admin-select required><option value="">Selecciona una unidad</option>@foreach($units as $unit)<option value="{{ $unit }}" @selected(old('unit', $record['unit'] ?? '') === $unit)>{{ $unit }}</option>@endforeach</select></label>@endif
            <label><span>Moneda</span><select name="currency" data-admin-select required>@foreach(['GTQ' => 'GTQ - Quetzales', 'USD' => 'USD - Dolares'] as $value => $label)<option value="{{ $value }}" @selected(old('currency', $record['currency'] ?? 'GTQ') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label><span class="user-field-title">{{ $repair ? 'Tarifa' : 'Costo' }} de referencia <small class="table-muted">(opcional)</small></span><input name="reference_cost" type="number" value="{{ old('reference_cost', $record['reference_cost'] ?? '') }}" min="0" max="99999999.99" step="0.01" placeholder="0.00" data-nonnegative-cost></label>
        </div>
        <label><span class="user-field-title">Descripcion <small class="table-muted">(opcional)</small></span><textarea name="description" maxlength="255" rows="2">{{ old('description', $record['description'] ?? '') }}</textarea></label>
        <div class="form-actions-row user-form-actions"><a class="button container-exit-leave" href="{{ route('catalogs.index', $catalog) }}">Cancelar</a><button class="button primary" type="submit">{{ $editing ? 'Guardar cambios' : 'Crear '.$definition['singular'] }}</button></div>
    </form>
</section>
