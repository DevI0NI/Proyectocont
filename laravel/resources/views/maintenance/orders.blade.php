@extends('layouts.app')
@section('content')
<section class="page-heading"><div><span class="eyebrow">Mantenimiento</span><h1>Trabajos registrados</h1></div>
@if((int)auth()->user()->rol_id === 4)<a class="mw-button mw-primary" href="{{ route('maintenance.create') }}"><span data-catalog-icon="plus" aria-hidden="true"></span>Nuevo trabajo</a>@endif</section>
<section class="workboard mw-board">
<form method="GET" class="mw-filters" action="{{ route('maintenance.jobs') }}">
<label class="mw-search"><span>Contenedor</span><input name="search" type="search" maxlength="100" value="{{ request('search') }}" placeholder="Buscar numero de contenedor"></label>
<label><span>Estado</span><select name="status"><option value="">Todos</option>@foreach(\App\Services\MaintenanceWorkflow::STATES as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
@if((int)auth()->user()->rol_id === 4)<label class="mw-check"><input type="checkbox" name="mine" value="1" @checked(request()->boolean('mine'))>Solo mis trabajos</label>@endif
<button class="mw-button mw-primary" type="submit"><span data-catalog-icon="search" aria-hidden="true"></span>Buscar</button><a class="mw-button" href="{{ route('maintenance.jobs') }}">Limpiar</a><a class="mw-button" href="{{ route('maintenance.orders') }}">Contenedores</a></form>
<div class="table-scroll"><table class="data-table mw-table"><thead><tr><th>Trabajo</th><th>Contenedor</th><th>Mecanico</th><th>Estado</th><th>Actualizado</th><th></th></tr></thead><tbody>
@forelse($jobs as $job)<tr><td>OT-{{ str_pad($job->id, 6, '0', STR_PAD_LEFT) }}</td><td><strong>{{ $job->container_name }}</strong></td><td>{{ $job->mechanic_name }}</td><td><span class="status-badge {{ $job->status === 'pending_review' ? 'badge-warning' : 'badge-neutral' }}">{{ \App\Services\MaintenanceWorkflow::STATES[$job->status] ?? $job->status }}</span></td><td>{{ \Carbon\Carbon::parse($job->updated_at)->format('d/m/Y H:i') }}</td><td><a class="mw-button" href="{{ route('maintenance.show', $job->id) }}">Ver trabajo<span data-catalog-icon="arrow-right" aria-hidden="true"></span></a></td></tr>
@empty<tr><td colspan="6"><div class="mw-empty"><span data-catalog-icon="wrench" aria-hidden="true"></span><h2>No hay trabajos registrados</h2><p>{{ request()->hasAny(['search', 'status', 'mine']) ? 'No hay resultados para los filtros seleccionados.' : 'Los trabajos de mantenimiento de esta sucursal apareceran aqui.' }}</p></div></td></tr>@endforelse
</tbody></table></div><div class="mw-pagination">{{ $jobs->links() }}</div></section>
@endsection
