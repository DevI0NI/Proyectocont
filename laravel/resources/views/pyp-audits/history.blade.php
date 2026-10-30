@extends('layouts.app')
@section('content')
@php($liveTable = true)
<div class="administration-main containers-livewire-shell">
<section class="panel-card containers-module repair-history-board standard-records-table" aria-label="Auditorías del contenedor">
    <header class="containers-module-head repair-history-module-head">
        <div class="view-header-copy"><h1>Auditorías</h1><p>{{ $container->nombre }} · {{ $sucursal->nombre }}</p></div>
        <a class="mw-button" href="{{ $returnUrl }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a>
    </header>
    <div class="containers-table-panel">
        @include('partials.administration-toolbar', ['paginator' => $audits, 'clearKeys' => ['return_to', 'patio_id', 'bloque_id']])
        <div class="containers-table-wrap" tabindex="0" role="region" aria-label="Auditorías">
            <table class="containers-table">
                <thead><tr>@include('partials.administration-table-head', ['actionsFirst' => true, 'actionsLabel' => 'Ver', 'defaultSort' => 'date', 'defaultOrder' => 'desc', 'containerFilters' => true])</tr></thead>
                <tbody>
                @forelse($audits as $audit)
                    <tr>
                        <td data-label="Ver"><a class="mw-button mw-icon repair-history-view" href="{{ route('pyp-audits.show', ['container' => $container->id, 'audit' => $audit->id, 'return_to' => request()->getRequestUri()]) }}" title="Ver auditoría" aria-label="Ver auditoría"><span data-notification-icon="eye" aria-hidden="true"></span></a></td>
                        <td data-label="Fecha"><time datetime="{{ \Carbon\Carbon::parse($audit->created_at, 'UTC')->toIso8601String() }}">{{ $audit->display_date }}</time></td>
                        <td data-label="Inspector">{{ $audit->inspector_name }}</td>
                        <td data-label="Resultado"><span class="status-badge {{ $audit->status === 'Aprobado' ? 'badge-success' : 'badge-danger' }}">{{ $audit->status }}</span></td>
                        <td data-label="Observaciones"><span class="audit-notes-preview" data-audit-notes-preview tabindex="0">{{ $audit->notes ?: 'Sin observaciones registradas.' }}</span></td>
                    </tr>
                @empty
                    <tr><td class="table-empty-cell" colspan="5">No hay auditorías para estos filtros.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('partials.administration-pagination', ['paginator' => $audits])
    </div>
</section>
</div>
@endsection
