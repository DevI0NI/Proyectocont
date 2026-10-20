@php
    $editing = isset($record['id']);
    $selectedCode = old('code', $record['code'] ?? array_key_first($isoPresets));
    if (!is_string($selectedCode) || !isset($isoPresets[$selectedCode])) {
        $selectedCode = array_key_first($isoPresets);
    }
    $preset = $isoPresets[$selectedCode];
@endphp
<section class="page-heading standard-records-heading user-editor-heading"><div><span class="eyebrow">ISO Code</span><h1>{{ $editing ? 'Editar codigo ISO' : 'Nuevo codigo ISO' }}</h1></div></section>
<section class="form-panel container-form-shell user-editor shipping-line-editor iso-editor" data-shipping-lines>
    @if($errors->any())<script type="application/json" data-shipping-line-errors>@json($errors->all())</script>@endif
    <form method="POST" class="record-form container-form" data-shipping-line-form data-iso-form data-return-url="{{ route('catalogs.index', 'iso') }}" action="{{ $editing ? route('catalogs.update', ['catalog' => 'iso', 'record' => $record['id']]) : route('catalogs.store', 'iso') }}" novalidate>
        @csrf @if($editing) @method('PATCH') @endif
        <script type="application/json" id="isoPresetsData">@json($isoPresets)</script>
        <div class="record-select-fields iso-code-choice"><label><span>Codigo ISO</span><select name="code" data-admin-select data-iso-preset required>
            @foreach($isoPresets as $code => $item)<option value="{{ $code }}" @selected($selectedCode === $code)>{{ $code }} — {{ $item['name'] }}</option>@endforeach
        </select></label></div>
        <div class="iso-preset-summary" aria-live="polite">
            <strong data-iso-name>{{ $preset['name'] }}</strong>
            <span class="table-muted" data-iso-type>{{ $preset['type_name'] }}</span>
            <span data-iso-size>{{ number_format($preset['length_mm']/1000, 3) }} × {{ number_format($preset['width_mm']/1000, 3) }} × {{ number_format($preset['height_mm']/1000, 3) }} m</span>
        </div>
        <label class="shipping-line-description-field"><span class="user-field-title">Descripcion <small class="table-muted">(opcional)</small></span><textarea name="description" maxlength="255" rows="2">{{ old('description', $record['description'] ?? '') }}</textarea></label>
        <div class="form-actions-row container-edit-actions"><a class="container-edit-button cancel" href="{{ route('catalogs.index', 'iso') }}">Cancelar</a><button type="submit" class="container-edit-button save">{{ $editing ? 'Guardar cambios' : 'Crear codigo ISO' }}</button></div>
    </form>
</section>
