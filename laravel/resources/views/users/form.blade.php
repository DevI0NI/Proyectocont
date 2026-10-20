@extends('layouts.app')
@section('content')
    @php
        $isEdit = $mode === 'edit';
        $isReactivate = $mode === 'reactivate';
        $title = $isReactivate ? 'Reactivar usuario' : ($isEdit ? 'Editar usuario' : 'Nuevo usuario');
    @endphp
    <section class="page-heading standard-records-heading user-editor-heading"><div><span class="eyebrow">Usuarios</span><h1>{{ $title }}</h1></div></section>
    <section class="form-panel container-form-shell user-editor">
        @if($errors->any())<script type="application/json" data-user-errors>@json($errors->all())</script>@endif
        <form method="POST" action="{{ $isEdit || $isReactivate ? route('users.update', $userModel) : route('users.store') }}" class="record-form container-form" data-user-standalone data-user-id="{{ $userModel->id }}" data-check-url="{{ route('users.check') }}" data-return-url="{{ route('users.index', $isReactivate ? ['estado' => 'inactivos'] : []) }}" novalidate>
            @csrf
            @if($isEdit || $isReactivate) @method('PATCH') @endif
            @if($isReactivate)<input type="hidden" name="reactivate" value="1">@endif
            <div class="shipping-line-editor-tabs" role="tablist" aria-label="Apartados del usuario">
                <button type="button" role="tab" id="userGeneralTab" aria-controls="userGeneralPanel" aria-selected="true" data-user-tab="general">Datos generales</button>
                <button type="button" role="tab" id="userAccessTab" aria-controls="userAccessPanel" aria-selected="false" tabindex="-1" data-user-tab="access">Accesos y permisos</button>
            </div>
            <div id="userGeneralPanel" role="tabpanel" aria-labelledby="userGeneralTab" data-user-panel="general" class="user-tab-general">
            @include('partials.user-form-fields', ['editing' => $isEdit, 'values' => ['username' => old('username', $userModel->username), 'email' => old('email', $userModel->email), 'rol_id' => old('rol_id', $userModel->rol_id), 'sucursal_id' => old('sucursal_id', $userModel->sucursal_id ?: session('sucursal_activa'))]])
            </div>
            <div id="userAccessPanel" role="tabpanel" aria-labelledby="userAccessTab" data-user-panel="access" hidden>
                @include('users.permissions')
            </div>
            <div class="form-actions-row container-edit-actions"><a class="container-edit-button cancel" href="{{ route('users.index', $isReactivate ? ['estado' => 'inactivos'] : []) }}">Cancelar</a><button type="submit" class="container-edit-button save">{{ $isReactivate ? 'Reactivar usuario' : ($isEdit ? 'Guardar cambios' : 'Crear usuario') }}</button></div>
        </form>
    </section>
@endsection
