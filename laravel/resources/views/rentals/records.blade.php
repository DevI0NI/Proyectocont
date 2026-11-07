@extends('layouts.app')
@section('content')
@php($liveTable = true)
<div class="administration-main containers-livewire-shell parking-records">
<section class="panel-card containers-module standard-records-table" aria-label="Registros de parqueo">
    <header class="containers-module-head">
        <div class="view-header-copy"><h1>Registros de parqueo</h1><p>{{ $location->name }} · {{ $history ? 'Estadias ya cobradas' : 'Vehiculos dentro del predio' }}</p></div>
        <div class="parking-records-actions">
            <div class="admin-page-size admin-state-choice">
                <span class="topbar-sucursal-label">Predio</span>
                <div class="form-select-dropdown" data-admin-page-size>
                    <button type="button" class="form-select-trigger" aria-expanded="false" aria-label="Predio de parqueo"><span>{{ $location->name }}</span><span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                    <div class="form-select-menu">
                        @foreach($locations as $item)
                            <a class="topbar-dropdown-option {{ $location->id === $item->id ? 'selected' : '' }}" data-admin-page href="{{ route('rentals.records', ['location' => $item->id, 'tab' => $history ? 'history' : null]) }}">{{ $item->name }}</a>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="admin-page-size admin-state-choice">
                <span class="topbar-sucursal-label">Estado</span>
                <div class="form-select-dropdown" data-admin-page-size>
                    <button type="button" class="form-select-trigger" aria-expanded="false" aria-label="Estado de las estadias"><span>{{ $history ? 'Cobradas' : 'Dentro del predio' }}</span><span data-admin-icon="chevron-down" aria-hidden="true"></span></button>
                    <div class="form-select-menu">
                        <a class="topbar-dropdown-option {{ $history ? '' : 'selected' }}" data-admin-page href="{{ route('rentals.records', ['location' => $location->id]) }}">Dentro del predio</a>
                        <a class="topbar-dropdown-option {{ $history ? 'selected' : '' }}" data-admin-page href="{{ route('rentals.records', ['location' => $location->id, 'tab' => 'history']) }}">Cobradas</a>
                    </div>
                </div>
            </div>
            <a class="button secondary" href="{{ route('rentals.index', ['location' => $location->id]) }}"><span data-admin-icon="chevron-left" aria-hidden="true"></span>Volver al plano</a>
        </div>
    </header>
    <div class="containers-table-panel">
        @include('partials.administration-toolbar', ['paginator' => $stays, 'clearKeys' => ['location', 'tab']])
        <div class="containers-table-wrap" tabindex="0" role="region" aria-label="Estadias de parqueo">
            <table class="containers-table">
                <thead><tr>@include('partials.administration-table-head', ['actionsFirst' => true, 'actionsLabel' => 'Ver', 'defaultSort' => 'date', 'defaultOrder' => 'desc', 'containerFilters' => true])</tr></thead>
                <tbody>
                @forelse($stays as $stay)
                    @php($local = fn ($time) => \Carbon\CarbonImmutable::parse($time, 'UTC')->setTimezone('America/Guatemala')->format('d/m/Y H:i'))
                    <tr>
                        <td data-label="Ver"><a class="button secondary" href="{{ route('rentals.show', ['stay' => $stay->id, 'return_to' => request()->getRequestUri()]) }}">{{ $stay->exited_at ? 'Comprobante' : 'Cobrar salida' }}</a></td>
                        <td data-label="Entrada">{{ $local($stay->entered_at) }}</td>
                        @if($history)<td data-label="Salida">{{ $local($stay->exited_at) }}</td>@endif
                        <td data-label="Placa"><strong>{{ $stay->plate }}</strong></td>
                        <td data-label="Conductor">{{ $stay->driver }}</td>
                        <td data-label="Espacio">{{ $stay->space_code }}</td>
                        <td data-label="Modalidad"><span class="status-badge {{ $stay->billing_unit === 'hour' ? 'badge-success' : 'badge-neutral' }}">{{ $stay->billing_label }}</span></td>
                        <td data-label="{{ $history ? 'Total cobrado' : 'Tarifa' }}">{{ $stay->currency }} {{ number_format($stay->amount_cents / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td class="table-empty-cell" colspan="{{ count($tableColumns) + 1 }}">{{ $history ? 'No hay estadias cobradas para estos filtros.' : 'No hay vehiculos dentro del predio para estos filtros.' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('partials.administration-pagination', ['paginator' => $stays])
    </div>
</section>
</div>
@endsection
