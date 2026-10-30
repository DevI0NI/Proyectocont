@extends('layouts.app')
@section('content')
    @php
        $isEdit = $mode === 'edit';
    @endphp
    <section class="page-heading standard-records-heading user-editor-heading"><div><span class="eyebrow">Estatus</span><h1>{{ $isEdit ? 'Editar estatus' : 'Nuevo estatus' }}</h1></div></section>
    <section class="form-panel container-form-shell user-editor" data-container-statuses>
        @if($errors->any())<script type="application/json" data-status-errors>@json($errors->all())</script>@endif
        <form method="POST" action="{{ $isEdit ? route('statuses.update', $status) : route('statuses.store') }}" class="record-form container-form" data-status-save data-status-standalone data-return-base="{{ route('statuses.index') }}" novalidate>
            @csrf
            @if($isEdit) @method('PATCH') @endif
            @include('partials.status-form-fields', ['statusName' => $status->nombre, 'statusType' => $status->tipo_estatus_id])
            <div class="form-actions-row container-edit-actions"><a class="container-edit-button cancel" href="{{ route('statuses.index', ['tipo' => $selectedType?->nombre]) }}">Cancelar</a><button type="submit" class="container-edit-button save">{{ $isEdit ? 'Guardar cambios' : 'Crear estatus' }}</button></div>
        </form>
    </section>
@endsection
