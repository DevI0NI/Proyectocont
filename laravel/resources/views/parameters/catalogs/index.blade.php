@extends('layouts.app')
@section('content')
    @if(in_array($catalog, ['navieras', 'auditoras', 'iso', 'repuestos', 'reparaciones'], true))
        @include('parameters.catalogs.shipping-lines-index')
    @else
<section class="page-heading"><div><span class="eyebrow">Catalogos</span><h1>{{ $definition['title'] }}</h1></div></section>
@include('parameters.catalogs.nav')
@if($errors->any())<div class="form-error page-error">{{ $errors->first() }}</div>@endif
<section class="workboard">
@include('partials.administration-toolbar', ['paginator' => $records, 'createUrl' => route('catalogs.create', $catalog), 'createLabel' => 'Nuevo registro'])
<div class="catalog-toolbar">
<nav class="catalog-status-tabs" aria-label="Estado"><a href="{{ route('catalogs.index', $catalog) }}" @class(['active' => $status === 1])>Activos</a><a href="{{ route('catalogs.index', ['catalog' => $catalog, 'status' => 'inactive']) }}" @class(['active' => $status === 2])>Inactivos</a></nav>
</div>
<div class="table-scroll"><table class="data-table catalog-table"><thead><tr>
@include('partials.administration-table-head')
</tr></thead><tbody>
@forelse($records as $record)<tr>
@unless(in_array($catalog, ['navieras', 'auditoras'], true))<td><strong>{{ $record->code }}</strong></td>@endunless
<td><strong>{{ $record->name }}</strong></td>
@if($catalog === 'navieras')
@foreach([$branches, $auditors] as $links)<td><div class="tag-list">@forelse($links->get($record->id, collect()) as $linked)<span class="tag">{{ $linked->name }}{{ (int)$linked->status === 2 ? ' (inactivo)' : '' }}</span>@empty<span class="table-muted">Pendiente de vincular</span>@endforelse</div></td>@endforeach
@elseif($catalog === 'auditoras')
<td>{{ $record->contact_name ?: 'N/D' }}</td><td>{{ $record->email ?: 'N/D' }}<br><span class="table-muted">{{ $record->phone ?: '' }}</span></td>
@elseif($catalog === 'iso')
<td>{{ $record->type_name }}</td><td>{{ number_format($record->length_mm / 1000, 3) }} × {{ number_format($record->width_mm / 1000, 3) }} × {{ number_format($record->height_mm / 1000, 3) }} m</td>
@else
<td>{{ $record->unit }}</td><td>{{ $record->reference_cost === null ? 'Sin definir' : $record->currency.' '.number_format((float)$record->reference_cost, 2) }}</td>
@endif
<td><div class="row-actions">
<a class="catalog-icon-button" href="{{ route('catalogs.edit', ['catalog' => $catalog, 'record' => $record->id]) }}" title="Editar {{ $record->name }}" aria-label="Editar {{ $record->name }}"><span data-catalog-icon="pencil" aria-hidden="true"></span></a>
<button type="button" class="catalog-icon-button {{ $status === 1 ? 'danger' : '' }}" data-catalog-status data-action="{{ route('catalogs.status', ['catalog' => $catalog, 'record' => $record->id]) }}" data-status="{{ $status === 1 ? 2 : 1 }}" data-name="{{ $record->name }}" title="{{ $status === 1 ? 'Desactivar' : 'Reactivar' }} {{ $record->name }}" aria-label="{{ $status === 1 ? 'Desactivar' : 'Reactivar' }} {{ $record->name }}"><span data-catalog-icon="{{ $status === 1 ? 'circle-pause' : 'rotate-ccw' }}" aria-hidden="true"></span></button>
</div></td></tr>
@empty<tr><td colspan="6" class="empty-cell">No hay registros {{ $status === 1 ? 'activos' : 'inactivos' }}{{ request('q') ? ' para esta busqueda' : '' }}.</td></tr>@endforelse
</tbody></table></div><div class="pagination-wrap">@include('partials.administration-pagination', ['paginator' => $records])</div>
</section>
<dialog class="container-exit-dialog" id="catalogStatusDialog" aria-labelledby="catalogStatusTitle" aria-describedby="catalogStatusDescription">
<h2 id="catalogStatusTitle">Cambiar estado</h2><p id="catalogStatusDescription"></p>
<form method="POST" id="catalogStatusForm">@csrf @method('PATCH')<input type="hidden" name="status" id="catalogNewStatus"><div class="catalog-dialog-actions"><button type="button" class="button secondary" data-catalog-close>Cancelar</button><button type="submit" class="button primary">Confirmar</button></div></form>
</dialog>
    @endif
@endsection
