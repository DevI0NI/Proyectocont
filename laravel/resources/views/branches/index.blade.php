@extends('layouts.app')

@section('content')
    @php
        $liveTable = true;
    @endphp
    <section class="page-heading branches-heading standard-records-heading">
        <div>
            <span class="eyebrow">Parametros</span>
            <h1>Sucursales</h1>
        </div>
    </section>

    @if ($errors->any())
        <script type="application/json" data-branch-errors>@json($errors->all())</script>
    @endif

    <section class="workboard branches-table standard-records-table">
        @include('partials.administration-toolbar', ['paginator' => $branches, 'createUrl' => route('branches.create'), 'createLabel' => 'Nueva sucursal'])

        <div class="branches-table-panel standard-records-panel">
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        @include('partials.administration-table-head', ['actionsFirst' => true])
                    </tr>
                </thead>
                <tbody>
                    @forelse ($branches as $branch)
                        <tr>
                            <td>
                                <div class="row-options" data-record-options>
                                    <button type="button" class="options-trigger" aria-label="Opciones de {{ $branch->nombre }}" aria-expanded="false" aria-haspopup="true">Opciones<span class="dropdown-caret" data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                                    <div class="options-menu record-options-menu" hidden>
                                    @if((int)$branch->estado_id === 1)
                                    <a href="{{ route('branches.edit', $branch) }}" data-branch-form="edit" data-action="{{ route('branches.update', $branch) }}" data-name="{{ $branch->nombre }}" data-location="{{ $branch->ubicacion }}" data-department="{{ $branch->departamento_codigo }}" data-municipality="{{ $branch->municipio_codigo }}" data-address="{{ $branch->direccion }}">Editar</a>
                                    <form method="POST" action="{{ route('branches.destroy', $branch) }}" data-branch-deactivate data-name="{{ $branch->nombre }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit">Desactivar</button>
                                    </form>
                                    @else
                                        <form method="POST" action="{{ route('branches.reactivate', $branch) }}" data-branch-reactivate data-name="{{ $branch->nombre }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit">Reactivar</button>
                                        </form>
                                    @endif
                                    </div>
                                </div>
                            </td>
                            <td><strong>{{ $branch->nombre }}</strong>@if((int)$branch->estado_id === 2) <span class="table-muted">(Inactiva)</span>@endif</td>
                            <td>{{ $branch->ubicacion }}@if($branch->direccion)<br><small class="table-muted">{{ $branch->direccion }}</small>@endif</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="empty-cell">No hay sucursales para estos filtros.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pagination-wrap">
            @include('partials.administration-pagination', ['paginator' => $branches])
        </div>
        </div>
    </section>
    <dialog class="container-exit-dialog branch-modal action-modal" id="branchFormDialog" aria-labelledby="branchFormTitle">
        <span class="eyebrow">Sucursales</span>
        <h2 id="branchFormTitle">Nueva sucursal</h2>
        <form method="POST" action="{{ route('branches.store') }}" id="branchModalForm" class="record-form" data-create-action="{{ route('branches.store') }}" novalidate>
            @csrf
            <input type="hidden" name="_method" value="POST">
            <label><span>Nombre</span><input type="text" name="nombre" required maxlength="100" autocomplete="off"></label>
            <p class="table-muted" data-branch-legacy hidden></p>
            @include('partials.branch-geography-fields', ['selectedDepartment' => '', 'selectedMunicipality' => '', 'selectedAddress' => ''])
            <div class="container-exit-dialog-actions">
                <button type="button" class="button secondary" data-branch-modal-close>Cancelar</button>
                <button type="submit" class="button primary">Guardar</button>
            </div>
        </form>
    </dialog>
    <dialog class="container-exit-dialog branch-modal action-modal" id="branchDeactivateDialog" aria-labelledby="branchDeactivateTitle" aria-describedby="branchDeactivateDescription">
        <span class="container-exit-dialog-kicker">Desactivar sucursal</span>
        <h2 id="branchDeactivateTitle">¿Desactivar esta sucursal?</h2>
        <p id="branchDeactivateDescription">Dejara de estar disponible para nuevas operaciones. Su historial se conservara. Primero debes reasignar o deshabilitar sus usuarios activos y resolver sus patios activos; no se deshabilitan automaticamente.</p>
        <div class="container-exit-dialog-actions">
            <button type="button" class="button secondary" data-branch-modal-close>Cancelar</button>
            <button type="button" class="button container-exit-leave" data-branch-deactivate-confirm>Desactivar sucursal</button>
        </div>
    </dialog>
    <dialog class="container-exit-dialog branch-modal action-modal" id="branchReactivateDialog" aria-labelledby="branchReactivateTitle" aria-describedby="branchReactivateDescription">
        <span class="eyebrow">Reactivar sucursal</span>
        <h2 id="branchReactivateTitle">¿Reactivar esta sucursal?</h2>
        <p id="branchReactivateDescription">Volvera a estar disponible para operaciones y conservara sus datos e historial. Sus patios y usuarios no se reactivaran automaticamente.</p>
        <div class="container-exit-dialog-actions">
            <button type="button" class="button secondary" data-branch-modal-close>Cancelar</button>
            <button type="button" class="button primary" data-branch-reactivate-confirm>Reactivar sucursal</button>
        </div>
    </dialog>
    <script type="application/json" id="branchGeographyData">@json($geography)</script>
@endsection
