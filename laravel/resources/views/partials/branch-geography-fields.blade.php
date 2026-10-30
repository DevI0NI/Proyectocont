<div class="branch-geography-fields record-select-fields">
    <label><span>Departamento</span>
        <select name="departamento_codigo" data-admin-select required>
            <option value="">Selecciona un departamento</option>
            @foreach ($geography as $department)
                <option value="{{ $department['code'] }}" @selected($selectedDepartment === $department['code'])>{{ $department['name'] }}</option>
            @endforeach
        </select>
    </label>
    <label><span>Municipio</span>
        <select name="municipio_codigo" data-admin-select required @disabled(!$selectedDepartment)>
            <option value="">Selecciona un municipio</option>
            @foreach (\App\Services\GuatemalaGeography::municipalities($selectedDepartment) as $municipality)
                <option value="{{ $municipality['code'] }}" @selected($selectedMunicipality === $municipality['code'])>{{ $municipality['name'] }}</option>
            @endforeach
        </select>
    </label>
</div>
<label><span class="branch-address-title">Direccion exacta <small>(opcional)</small></span><textarea name="direccion" rows="2" maxlength="255">{{ $selectedAddress }}</textarea></label>
