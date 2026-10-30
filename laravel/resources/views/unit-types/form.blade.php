@extends('layouts.app')
@section('content')
    @php
        $isEdit = $mode !== 'create';
        $isReactivate = $mode === 'reactivate';
        $title = $isReactivate ? 'Reactivar tipo de unidad' : ($isEdit ? 'Editar tipo de unidad' : 'Nuevo tipo de unidad');
    @endphp
    <section class="page-heading standard-records-heading user-editor-heading"><div><span class="eyebrow">Tipos de unidad</span><h1>{{ $title }}</h1></div></section>
    <section class="form-panel container-form-shell user-editor unit-type-editor" data-unit-types>
        @if($errors->any())<script type="application/json" data-unit-type-errors>@json($errors->all())</script>@endif
        <form method="POST" action="{{ $isEdit ? route('unit-types.update', $type) : route('unit-types.store') }}" class="record-form container-form" data-unit-type-form data-return-url="{{ route('unit-types.index') }}" novalidate>
            @csrf
            @if($isEdit) @method('PATCH') @endif
            @if($isReactivate)<input type="hidden" name="reactivate" value="1">@endif
            <div class="user-field-grid">
                <label><span>Nombre</span><input type="text" name="nombre" value="{{ old('nombre', $type->nombre) }}" required maxlength="50" placeholder="Nombre del tipo"></label>
                <label><span class="user-field-title">Descripción <small class="table-muted">(opcional)</small></span><textarea name="descripcion" rows="1" maxlength="255">{{ old('descripcion', $type->descripcion) }}</textarea></label>
            </div>
            <div class="model-list-field">
                <div class="field-heading"><span>Modelos</span><small class="model-count" data-model-count aria-live="polite"></small></div>
                @if($isReactivate)<p class="table-muted">Se habilitarán únicamente los modelos que mantengas en esta lista.</p>@endif
                <div class="model-rows" data-model-rows>
                    @foreach(old('modelos_nombres', array_column($modelos, 'nombre') ?: ['']) as $index => $modelName)
                        <div class="model-row">
                            <input type="hidden" name="modelos_ids[]" value="{{ old('modelos_ids.'.$index, $modelos[$index]['id'] ?? 0) }}">
                            <span class="model-row-number" data-model-number aria-hidden="true">{{ str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <input type="text" name="modelos_nombres[]" value="{{ $modelName }}" maxlength="50" placeholder="Nombre del modelo" aria-label="Nombre del modelo {{ $index + 1 }}">
                            <button type="button" class="admin-action danger" data-remove-model aria-label="Quitar modelo" title="Quitar modelo"></button>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="model-add-button" data-add-model><span data-admin-icon="plus" aria-hidden="true"></span>Agregar modelo</button>
            </div>
            <div class="form-actions-row container-edit-actions"><a class="container-edit-button cancel" href="{{ route('unit-types.index') }}">Cancelar</a><button type="submit" class="container-edit-button save">{{ $isReactivate ? 'Reactivar tipo' : ($isEdit ? 'Guardar cambios' : 'Crear tipo') }}</button></div>
        </form>
    </section>
@endsection
