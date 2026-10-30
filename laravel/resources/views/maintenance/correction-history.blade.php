@extends('layouts.app')
@section('content')
@php($liveTable = true)
<div class="administration-main containers-livewire-shell">
<section class="panel-card containers-module repair-history-board standard-records-table" aria-label="Correcciones de la reparación">
    <header class="containers-module-head repair-history-module-head">
        <div class="view-header-copy"><h1>Correcciones</h1><p>{{ $job->container_name }} · {{ $job->repair_type ?: 'Sin tipo registrado' }} · {{ $sucursal->nombre }}</p></div>
        <a class="mw-button" href="{{ $returnUrl }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a>
    </header>
    <div class="containers-table-panel">
        @include('partials.administration-toolbar', ['paginator' => $rounds, 'clearKeys' => ['return_to']])
        <div class="containers-table-wrap" tabindex="0" role="region" aria-label="Correcciones">
            <table class="containers-table">
                <thead><tr>@include('partials.administration-table-head', ['actionsFirst' => true, 'actionsLabel' => 'Ver', 'defaultSort' => 'date', 'defaultOrder' => 'desc', 'containerFilters' => true])</tr></thead>
                <tbody>
                @forelse($rounds as $round)
                    <tr>
                        <td data-label="Ver"><a class="mw-button mw-icon repair-history-view" href="{{ route('corrections.detail', ['job' => $job->id, 'intervention' => $round->id, 'return_to' => request()->getRequestUri()]) }}" title="Ver corrección {{ $round->number }}" aria-label="Ver corrección {{ $round->number }}"><span data-notification-icon="eye" aria-hidden="true"></span></a></td>
                        <td data-label="Corrección"><strong>Corrección {{ $round->number }}</strong></td>
                        <td data-label="Fecha de solicitud"><time datetime="{{ \Carbon\Carbon::parse($round->created_at, 'UTC')->toIso8601String() }}">{{ $round->display_date }}</time></td>
                        <td data-label="Solicitada por">{{ $round->requester_name }}</td>
                        <td data-label="Estado"><span class="status-badge {{ match($round->status) { 'approved' => 'badge-success', 'submitted' => 'badge-warning', 'rejected' => 'badge-danger', default => 'badge-neutral' } }}">{{ $round->status_label }}</span></td>
                    </tr>
                @empty
                    <tr><td class="table-empty-cell" colspan="5">No hay correcciones para estos filtros.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('partials.administration-pagination', ['paginator' => $rounds])
    </div>
</section>
</div>
@endsection
