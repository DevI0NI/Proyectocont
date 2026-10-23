<div class="external-audit-dashboard" wire:poll.5s>
    <section class="page-heading external-audit-heading">
        <div><span class="eyebrow">Auditoría externa · Supervisor</span><h1>{{ $company }}</h1><p>{{ $rows->count() }} {{ $rows->count() === 1 ? 'sucursal autorizada' : 'sucursales autorizadas' }}</p></div>
        <a class="mw-button" href="{{ route('pyp-audits.index') }}"><span data-notification-icon="inbox" aria-hidden="true" wire:ignore></span>Contenedores</a>
    </section>
    <section class="workboard external-audit-summary" aria-label="Resumen de auditoría externa">
        <dl class="external-audit-metrics">
            @foreach(['total' => 'Contenedores asignados', 'ready' => 'Pendientes de inspección', 'in_review' => 'En revisión', 'approved' => 'Aprobados', 'rejected' => 'Rechazados', 'waiting' => 'Esperando liberación'] as $key => $label)
                <div data-audit-metric="{{ $key }}"><dt>{{ $label }}</dt><dd>{{ number_format($totals[$key]) }}</dd></div>
            @endforeach
        </dl>
        <div class="external-audit-section-heading"><h2>Actividad por sucursal</h2><span>{{ now('America/Guatemala')->format('d/m/Y · H:i') }}</span></div>
        <div class="notification-table-wrap external-audit-branch-table" tabindex="0" aria-label="Resumen por sucursal">
            <table><thead><tr><th>Sucursal</th><th>Asignados</th><th>Pendientes</th><th>En revisión</th><th>Aprobados</th><th>Rechazados</th><th>Esperando liberación</th></tr></thead><tbody>
            @foreach($rows as $row)
                <tr @class(['is-active-branch' => $row['id'] === (int) session('sucursal_activa')])><th scope="row">{{ $row['name'] }}</th>@foreach(['total', 'ready', 'in_review', 'approved', 'rejected', 'waiting'] as $key)<td>{{ number_format($row[$key]) }}</td>@endforeach</tr>
            @endforeach
            </tbody></table>
        </div>
    </section>
</div>
