@extends('layouts.app')
@section('content')
    @php
        $liveTable = true;
    @endphp
    <section class="page-heading standard-records-heading"><div><span class="eyebrow">Parametros</span><h1>Estatus de contenedores</h1></div></section>
    @if($errors->any())<script type="application/json" data-status-errors>@json($errors->all())</script>@endif
    <section class="workboard statuses-table standard-records-table" data-container-statuses>
        @include('partials.administration-toolbar', ['paginator' => $statuses, 'createUrl' => route('statuses.create', ['tipo' => $selectedType?->nombre]), 'createLabel' => 'Nuevo estatus', 'statusCategories' => $types])
        <div class="standard-records-panel"><div class="table-scroll"><table class="data-table">
            <thead><tr>@include('partials.administration-table-head', ['actionsFirst' => true])</tr></thead>
            <tbody>
                @forelse($statuses as $item)
                <tr>
                    <td>@if(auth()->user()->hasPermission('statuses.manage'))<div class="row-options" data-record-options>
                        <button type="button" class="options-trigger" aria-label="Opciones de {{ $item->nombre }}" aria-haspopup="true" aria-expanded="false">Opciones<span class="dropdown-caret" data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                        <div class="options-menu record-options-menu" hidden>
                            @if((int)$item->estado_id === 2)
                                <button type="button" data-status-action="reactivate" data-name="{{ $item->nombre }}" data-action="{{ route('statuses.reactivate', $item) }}">Reactivar</button>
                            @else
                                <a href="{{ route('statuses.edit', $item) }}" data-status-edit data-name="{{ $item->nombre }}" data-type="{{ $item->tipo_estatus_id }}" data-action="{{ route('statuses.update', $item) }}">Editar</a>
                                <button type="button" data-status-action="deactivate" data-name="{{ $item->nombre }}" data-action="{{ route('statuses.destroy', $item) }}">Desactivar</button>
                            @endif
                        </div>
                    </div>@else<span class="table-muted">Sin acciones</span>@endif</td>
                    <td><strong>{{ $item->nombre }}</strong>@if((int)$item->estado_id === 2) <small class="table-muted">(Inactivo)</small>@endif</td>
                    <td><span class="tag">{{ $item->tipo?->nombre ?? 'Sin categoría' }}</span></td>
                </tr>
                @empty<tr><td colspan="3" class="empty-cell">No hay estatus para estos filtros.</td></tr>@endforelse
            </tbody>
        </table></div><div class="pagination-wrap">@include('partials.administration-pagination', ['paginator' => $statuses])</div></div>
    </section>
    <dialog class="container-exit-dialog action-modal" id="statusFormDialog" aria-labelledby="statusFormTitle">
        <span class="eyebrow">Estatus</span><h2 id="statusFormTitle">Nuevo estatus</h2>
        <form method="POST" action="{{ route('statuses.store') }}" class="record-form" data-status-save data-create-action="{{ route('statuses.store') }}" data-default-type="{{ $selectedType?->id }}" data-current-category="{{ $selectedType?->nombre }}" data-return-base="{{ route('statuses.index') }}" novalidate>
            @csrf<input type="hidden" name="_method" value="POST">
            @include('partials.status-form-fields')
            <div class="container-exit-dialog-actions"><button type="button" class="button secondary" data-status-close>Cancelar</button><button type="submit" class="button primary">Guardar</button></div>
        </form>
    </dialog>
    <dialog class="container-exit-dialog action-modal" id="statusActionDialog" aria-labelledby="statusActionTitle" aria-describedby="statusActionDescription">
        <span class="container-exit-dialog-kicker" data-status-action-kicker>Desactivar estatus</span><h2 id="statusActionTitle">¿Desactivar este estatus?</h2>
        <p id="statusActionDescription"></p>
        <form method="POST" data-status-save data-current-category="{{ $selectedType?->nombre }}" data-return-base="{{ route('statuses.index') }}" novalidate>
            @csrf<input type="hidden" name="_method" value="DELETE">
            <div class="container-exit-dialog-actions"><button type="button" class="button secondary" data-status-close>Cancelar</button><button type="submit" class="button container-exit-leave" data-status-action-confirm>Desactivar estatus</button></div>
        </form>
    </dialog>
@endsection
