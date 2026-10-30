@extends('layouts.app')

@section('content')
    @php($isEdit = $mode === 'edit')

    <section class="page-heading">
        <div>
            <span class="eyebrow">Sucursales</span>
            <h1>{{ $isEdit ? 'Editar sucursal' : 'Nueva sucursal' }}</h1>
        </div>
        <p>{{ $isEdit ? 'Actualiza el nombre y ubicacion de la sucursal.' : 'Registra una nueva sede operativa.' }}</p>
    </section>

    <section class="form-panel">
        @if ($errors->any())
            <script type="application/json" data-branch-errors>@json($errors->all())</script>
        @endif

        <form method="POST" action="{{ $isEdit ? route('branches.update', $branch) : route('branches.store') }}" class="record-form">
            @csrf
            @if ($isEdit)
                @method('PATCH')
            @endif

            <label>
                <span>Nombre</span>
                <input type="text" name="nombre" value="{{ old('nombre', $branch->nombre) }}" required maxlength="100">
            </label>

            @if($branch->id && !$branch->municipio_codigo)
                <p class="table-muted">Ubicacion anterior: {{ $branch->ubicacion }}</p>
            @endif
            @include('partials.branch-geography-fields', ['selectedDepartment' => old('departamento_codigo', $branch->departamento_codigo ?? ''), 'selectedMunicipality' => old('municipio_codigo', $branch->municipio_codigo ?? ''), 'selectedAddress' => old('direccion', $branch->direccion ?? '')])

            <div class="form-actions-row">
                <a class="button secondary" href="{{ route('branches.index') }}">Cancelar</a>
                <button type="submit" class="button primary">Guardar</button>
            </div>
        </form>
    </section>
    <script type="application/json" id="branchGeographyData">@json($geography)</script>
@endsection
