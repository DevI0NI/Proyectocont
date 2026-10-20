@extends('layouts.app')
@section('content')
    @php
        $liveTable = true;
    @endphp
    <section class="page-heading standard-records-heading"><div><span class="eyebrow">Parametros</span><h1>Tipos de unidad</h1></div></section>
    @if($errors->any())<script type="application/json" data-unit-type-errors>@json($errors->all())</script>@endif
    <section class="workboard unit-types-table standard-records-table" data-unit-types>
        @include('partials.administration-toolbar', ['paginator' => $types, 'createUrl' => route('unit-types.create'), 'createLabel' => 'Nuevo tipo'])
        <div class="standard-records-panel"><div class="table-scroll"><table class="data-table">
            <thead><tr>@include('partials.administration-table-head', ['actionsFirst' => true])</tr></thead>
            <tbody>
                @forelse($types as $type)
                <tr>
                    <td>@if(auth()->user()->hasPermission('unit-types.manage'))<div class="row-options" data-record-options>
                        <button type="button" class="options-trigger" aria-label="Opciones de {{ $type->nombre }}" aria-expanded="false" aria-haspopup="true">Opciones<span class="dropdown-caret" data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                        <div class="options-menu record-options-menu" hidden>
                            @if((int)$type->estado_id === 2)
                                <a href="{{ route('unit-types.edit', ['unitType' => $type, 'reactivar' => 1]) }}">Reactivar</a>
                            @else
                                <a href="{{ route('unit-types.edit', $type) }}">Editar</a>
                                <button type="button" data-unit-type-deactivate data-action="{{ route('unit-types.destroy', $type) }}" data-name="{{ $type->nombre }}">Desactivar</button>
                            @endif
                        </div>
                    </div>@else<span class="table-muted">Sin acciones</span>@endif</td>
                    <td><strong>{{ $type->nombre }}</strong>@if((int)$type->estado_id === 2) <small class="table-muted">(Inactivo)</small>@endif</td>
                    <td>{{ $type->descripcion ?: 'Sin descripción' }}</td>
                    <td><div class="tag-list">
                        @forelse($type->modelosActivos as $model)<span class="tag">{{ $model->nombre }}</span>
                        @empty<span class="table-muted">Sin modelos activos</span>@endforelse
                    </div></td>
                </tr>
                @empty<tr><td colspan="4" class="empty-cell">No hay tipos de unidad para estos filtros.</td></tr>@endforelse
            </tbody>
        </table></div><div class="pagination-wrap">@include('partials.administration-pagination', ['paginator' => $types])</div></div>
    </section>
    <dialog class="container-exit-dialog action-modal" id="unitTypeDeactivateDialog" aria-labelledby="unitTypeDeactivateTitle" aria-describedby="unitTypeDeactivateDescription">
        <span class="container-exit-dialog-kicker">Desactivar tipo de unidad</span><h2 id="unitTypeDeactivateTitle">¿Desactivar este tipo de unidad?</h2>
        <p id="unitTypeDeactivateDescription">El tipo y sus modelos dejarán de estar disponibles para nuevos ingresos. Se conservará el historial. No se puede desactivar si lo usan contenedores activos.</p>
        <form method="POST" id="unitTypeDeactivateForm" novalidate>@csrf @method('DELETE')
            <div class="container-exit-dialog-actions"><button type="button" class="button secondary" data-unit-type-close>Cancelar</button><button type="submit" class="button container-exit-leave">Desactivar tipo</button></div>
        </form>
    </dialog>
@endsection
