<div class="record-select-fields">
    <label><span>Nombre</span><input type="text" name="nombre" required maxlength="255" value="{{ old('nombre', $statusName ?? '') }}" autocomplete="off"></label>
    <label><span>Categoría</span><select name="tipo_estatus_id" data-admin-select required>
        <option value="">Selecciona una categoría</option>
        @foreach($types as $type)<option value="{{ $type->id }}" @selected((int)old('tipo_estatus_id', $statusType ?? $selectedType?->id) === (int)$type->id)>{{ $type->nombre }}</option>@endforeach
    </select></label>
</div>
