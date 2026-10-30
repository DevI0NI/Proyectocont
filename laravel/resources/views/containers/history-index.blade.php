@extends('layouts.app')
@section('content')
<div class="administration-main containers-livewire-shell container-history" data-container-history>
<section class="panel-card containers-module standard-records-table">
    <header class="containers-module-head">
        <div class="view-header-copy"><h1>Historial de contenedores</h1><p>{{ $sucursal->nombre }}</p></div>
    </header>
    <div class="containers-table-panel">
        <form class="history-filters record-select-fields" method="get" action="{{ route('containers.history.index') }}" data-admin-query data-admin-live-query data-history-query>
            @include('containers.history-query-fields', ['exclude' => ['search', 'state', 'per_page']])
            <label class="history-search"><span>Contenedor</span><input type="search" name="search" value="{{ request('search') }}" maxlength="60" placeholder="Numero de equipo" /></label>
            <label><span>Estado del contenedor</span><select name="state" data-admin-select>@foreach(['all' => 'Todos', 'active' => 'En patio', 'dispatched' => 'Despachados', 'legacy' => 'Sin ciclo identificado'] as $value => $label)<option value="{{ $value }}" @selected(request('state', 'all') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label><span>Filas</span><select name="per_page" data-admin-select>@foreach([10, 25, 50] as $size)<option value="{{ $size }}" @selected(request('per_page', 10) == $size)>{{ $size }}</option>@endforeach</select></label>
            <a class="mw-button" data-admin-page href="{{ route('containers.history.index') }}">Limpiar</a>
        </form>
        @if($errors->any())<p role="alert" class="history-error">{{ $errors->first() }}</p>@endif
        <div class="containers-table-wrap" role="region" aria-label="Historial de contenedores" tabindex="0">
            <table class="containers-table"><thead><tr><th>Ver</th>
                @include('containers.history-column', ['key' => 'name', 'label' => 'Contenedor'])
                @include('containers.history-column', ['key' => 'shipping_line', 'label' => 'Naviera del ingreso'])
                <th>Estado del contenedor</th>
                @include('containers.history-column', ['key' => 'reentries', 'label' => 'Reingresos'])
                @include('containers.history-column', ['key' => 'ingress', 'label' => 'Ultimo ingreso'])
                @include('containers.history-column', ['key' => 'exit', 'label' => 'Ultima salida'])
            </tr></thead><tbody>
                @forelse($containers as $record)
                <tr>
                    <td><a class="mw-button mw-icon" data-history-view href="{{ route('containers.history.show', ['container' => $record->id, 'return_to' => request()->getRequestUri()]) }}" title="Ver historial de {{ $record->nombre }}" aria-label="Ver historial de {{ $record->nombre }}"><span data-notification-icon="eye" aria-hidden="true"></span></a></td>
                    <td><strong>{{ $record->nombre }}</strong></td>
                    <td>{{ $record->shipping_line_name ?? 'No registrada' }}</td>
                    <td><span class="status-badge {{ $record->history_state === 'active' ? 'badge-success' : 'badge-neutral' }}">{{ ['active' => 'En patio', 'dispatched' => 'Despachado', 'legacy' => 'Sin ciclo identificado'][$record->history_state] }}</span></td>
                    <td>{{ $record->reentry_count }}</td><td>{{ $record->entered_at ? \Carbon\Carbon::parse($record->entered_at)->format('d/m/Y H:i') : 'No registrado' }}</td>
                    <td>{{ $record->closed_at ? \Carbon\Carbon::parse($record->closed_at, 'UTC')->setTimezone('America/Guatemala')->format('d/m/Y H:i') : 'Sin salida registrada' }}</td>
                </tr>
                @empty<tr><td class="table-empty-cell" colspan="7">No hay contenedores para estos filtros.</td></tr>@endforelse
            </tbody></table>
        </div>
        @include('partials.administration-pagination', ['paginator' => $containers])
    </div>
</section>
</div>
@endsection
