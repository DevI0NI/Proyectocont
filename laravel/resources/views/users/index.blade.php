@extends('layouts.app')
@section('content')
    @php
        $liveTable = true;
    @endphp
    <section class="page-heading standard-records-heading"><div><span class="eyebrow">Parametros</span><h1>Usuarios del sistema</h1></div></section>
    @if($errors->any())<script type="application/json" data-user-errors>@json($errors->all())</script>@endif
    <section class="workboard users-table standard-records-table">
        @include('partials.administration-toolbar', ['paginator' => $users, 'createUrl' => route('users.create'), 'createLabel' => 'Nuevo usuario'])
        <div class="standard-records-panel"><div class="table-scroll"><table class="data-table">
            <thead><tr>@include('partials.administration-table-head', ['actionsFirst' => true])</tr></thead>
            <tbody>
            @forelse($users as $item)
                <tr>
                    <td>@if(auth()->user()->hasPermission('users.manage'))<div class="row-options" data-record-options>
                        <button type="button" class="options-trigger" aria-label="Opciones de {{ $item->username }}" aria-expanded="false" aria-haspopup="true">Opciones<span class="dropdown-caret" data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                        <div class="options-menu record-options-menu" hidden>
                            @if((int)$item->estado_id === 2)
                                <a href="{{ route('users.edit', ['user' => $item, 'reactivar' => 1]) }}">Reactivar</a>
                            @else
                                <a href="{{ route('users.edit', $item) }}">Editar</a>
                                @if((int)$item->rol_id !== 1)
                                    <button type="button" data-user-deactivate data-action="{{ route('users.destroy', $item) }}" data-name="{{ $item->username }}" @disabled((int)auth()->id() === (int)$item->id) @if((int)auth()->id() === (int)$item->id) title="No puedes desactivar tu propia cuenta" @endif>Desactivar</button>
                                @endif
                            @endif
                        </div>
                    </div>@else<span class="table-muted">Sin acciones</span>@endif</td>
                    <td><strong>{{ $item->username }}</strong>@if((int)$item->estado_id === 2) <small class="table-muted">(Inactivo)</small>@endif</td>
                    <td>{{ $item->email }}</td>
                    <td><span class="role-badge">{{ $item->rol->nombre ?? 'Sin rol' }}</span></td>
                    <td>{{ $item->sucursal->nombre ?? 'Global / Todas' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty-cell">No hay usuarios para estos filtros.</td></tr>
            @endforelse
            </tbody>
        </table></div><div class="pagination-wrap">@include('partials.administration-pagination', ['paginator' => $users])</div></div>
    </section>
    <dialog class="container-exit-dialog action-modal user-modal" id="userDeactivateDialog" aria-labelledby="userDeactivateTitle" aria-describedby="userDeactivateDescription">
        <span class="container-exit-dialog-kicker">Desactivar usuario</span><h2 id="userDeactivateTitle">¿Desactivar este usuario?</h2>
        <p id="userDeactivateDescription">Perdera el acceso al sistema. Sus registros e historial se conservaran.</p>
        <form method="POST" id="userDeactivateForm" novalidate>@csrf @method('DELETE')<div class="container-exit-dialog-actions"><button type="button" class="button secondary" data-user-modal-close>Cancelar</button><button type="submit" class="button container-exit-leave">Desactivar usuario</button></div></form>
    </dialog>
@endsection
