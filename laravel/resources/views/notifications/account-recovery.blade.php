@extends('layouts.app')
@section('content')
<section class="page-heading standard-records-heading"><div><span class="eyebrow">Notificaciones</span><h1>Recuperación de contraseñas</h1></div><a class="button secondary" href="{{ route('notifications.index') }}">Volver</a></section>
<section class="workboard account-recovery-board">
    <form method="GET" class="account-recovery-filters"><label>Estado<select name="state" onchange="this.form.submit()">@foreach(['pending' => 'Pendientes', 'approved' => 'Aprobadas', 'rejected' => 'Rechazadas', 'obsolete' => 'Sin vigencia', 'all' => 'Todas'] as $value => $label)<option value="{{ $value }}" @selected($state === $value)>{{ $label }}</option>@endforeach</select></label></form>
    <div class="table-scroll"><table class="data-table"><thead><tr><th>Usuario</th><th>Solicitud</th><th>Motivo</th><th>Estado</th><th>Revision</th></tr></thead><tbody>
    @forelse($requests as $item)
        <tr id="solicitud-{{ $item->id }}"><td><strong>{{ $item->username }}</strong><br><span class="table-muted">{{ $item->email }}</span></td><td>{{ \Carbon\Carbon::parse($item->created_at, 'UTC')->timezone('America/Guatemala')->format('d/m/Y H:i') }}</td><td class="account-recovery-reason">{{ $item->reason }}</td><td>{{ ['pending' => 'Pendiente', 'approved' => 'Aprobada', 'rejected' => 'Rechazada', 'obsolete' => 'Sin vigencia'][$item->status] }}</td><td>
        @if($item->status === 'pending')<form class="account-recovery-actions" method="POST" action="{{ route('notifications.recovery.review', $item->id) }}">@csrf<button class="button" name="decision" value="approved" type="submit">Aprobar</button><button class="button secondary" name="decision" value="rejected" type="submit">Rechazar</button></form>@else{{ $item->reviewer ?? 'Sistema' }}@endif
        </td></tr>
    @empty<tr><td colspan="5" class="empty-cell">No hay solicitudes en este estado.</td></tr>@endforelse
    </tbody></table></div>
    <div class="pagination-wrap">{{ $requests->links() }}</div>
</section>
@endsection
