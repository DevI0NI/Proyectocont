@extends('layouts.app')
@section('content')
@php($liveTable = true)
<div class="administration-main containers-livewire-shell">
<section class="panel-card containers-module repair-history-board standard-records-table" aria-label="Reparaciones del contenedor">
    <header class="containers-module-head repair-history-module-head">
        <div class="view-header-copy"><h1>Reparaciones</h1><p>{{ $job->container_name }} · {{ $sucursal->nombre }}</p></div>
        <a class="mw-button" href="{{ $returnUrl }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a>
    </header>
    <div class="containers-table-panel">
    @include('partials.administration-toolbar', ['paginator' => $jobs, 'clearKeys' => ['return_to']])
    <div class="containers-table-wrap" tabindex="0" role="region" aria-label="Reparaciones">
        <table class="containers-table">
            <thead><tr>@include('partials.administration-table-head', ['actionsFirst' => true, 'actionsLabel' => 'Ver', 'defaultSort' => 'date', 'defaultOrder' => 'desc', 'containerFilters' => true])</tr></thead>
            <tbody>
            @forelse($jobs as $repair)
                <tr>
                    <td data-label="Ver">
                        <a class="mw-button mw-icon repair-history-view" href="{{ route('corrections.detail', ['job' => $repair->id, 'return_to' => request()->getRequestUri()]) }}" title="Ver reparación" aria-label="Ver reparación"><span data-notification-icon="eye" aria-hidden="true"></span></a>
                    </td>
                    <td data-label="Fecha de inicio"><time datetime="{{ \Carbon\Carbon::parse($repair->created_at, 'UTC')->toIso8601String() }}">{{ \Carbon\Carbon::parse($repair->created_at, 'UTC')->timezone('America/Guatemala')->format('d/m/Y H:i') }}</time></td>
                    <td data-label="Reparación"><strong>{{ $repair->repair_type ?: 'Sin tipo registrado' }}</strong></td>
                    <td data-label="Mecánico">{{ $repair->mechanic_name }}</td>
                    <td data-label="Estado"><span class="status-badge {{ match($repair->status) { 'completed' => 'badge-success', 'pending_review' => 'badge-warning', 'returned_for_repair' => 'badge-danger', default => 'badge-neutral' } }}">{{ \App\Services\MaintenanceWorkflow::STATES[$repair->status] ?? $repair->status }}</span></td>
                    <td data-label="Correcciones de auditoría" title="Rondas de corrección solicitadas por la auditora">{{ $repair->correction_count }}</td>
                </tr>
            @empty
                <tr><td class="table-empty-cell" colspan="6">No hay reparaciones para estos filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.administration-pagination', ['paginator' => $jobs])
    </div>
</section>
</div>
@endsection
