@php
    $chartStates = [
        ['key' => 'ready', 'label' => 'Pendientes de inspección', 'color' => '#5498d5'],
        ['key' => 'in_review', 'label' => 'En revisión', 'color' => '#e8b349'],
        ['key' => 'approved', 'label' => 'Aprobados', 'color' => '#25af91'],
        ['key' => 'rejected', 'label' => 'Rechazados', 'color' => '#e36e78'],
        ['key' => 'waiting', 'label' => 'Esperando liberación', 'color' => '#94a6b1'],
    ];
@endphp
<div class="dashboard-livewire-shell external-audit-dashboard" wire:poll.60s data-audit-overview>
    <script type="application/json" data-audit-chart-data>@json(['totals' => $totals, 'branches' => $rows->values()->all(), 'states' => $chartStates])</script>
    <section class="view-header dashboard-heading">
        <div><span class="dashboard-eyebrow">Auditoría externa · Supervisor</span><h1>{{ $company }}</h1><p>{{ $rows->count() }} {{ $rows->count() === 1 ? 'sucursal autorizada' : 'sucursales autorizadas' }}</p></div>
    </section>
    <section class="dashboard-focus-grid external-audit-focus" aria-label="Resumen de auditoría externa">
        <article class="panel-card external-audit-assigned">
            <header class="panel-header"><div><h2>Contenedores asignados</h2><p>Inventario de las sucursales autorizadas</p></div></header>
            <div class="hero-number-row" data-audit-metric="total"><strong>{{ number_format($totals['total']) }}</strong><span>contenedores</span></div>
            <dl class="external-audit-metrics">
                @foreach(['ready' => 'Pendientes de inspección', 'in_review' => 'En revisión', 'waiting' => 'Esperando liberación'] as $key => $label)
                    <div data-audit-metric="{{ $key }}"><dt>{{ $label }}</dt><dd>{{ number_format($totals[$key]) }}</dd></div>
                @endforeach
            </dl>
        </article>
        <article class="panel-card status-panel external-audit-chart" aria-labelledby="auditStatusChartHeading">
                <header class="panel-header"><div><h2 id="auditStatusChartHeading">Distribución por estado</h2><p>Situación actual de las asignaciones</p></div></header>
                <div class="status-chart-wrap">
                <div class="status-chart-canvas-wrap external-audit-chart-stage" wire:ignore>
                    <canvas data-audit-chart="states" role="img" aria-label="Distribución de contenedores asignados por estado. Valores detallados en el resumen y la leyenda."></canvas>
                </div>
                <ul class="status-legend external-audit-chart-legend" aria-label="Estados de auditoría">
                    @foreach($chartStates as $state)
                        <li class="status-legend-row" data-audit-metric="{{ $state['key'] }}"><span class="status-dot" style="--dot-color: {{ $state['color'] }}" aria-hidden="true"></span><strong>{{ $state['label'] }}</strong><small>{{ number_format($totals[$state['key']]) }}</small></li>
                    @endforeach
                </ul>
                </div>
                @if(!$totals['total'])<p class="external-audit-chart-empty">Sin contenedores asignados.</p>@endif
        </article>
        <article class="panel-card external-audit-chart" aria-labelledby="auditBranchChartHeading">
                <header class="panel-header"><div><h2 id="auditBranchChartHeading">Carga por sucursal</h2><p>Contenedores por estado de auditoría</p></div></header>
                <div class="external-audit-branch-chart-scroll">
                    <div class="external-audit-chart-stage external-audit-branch-chart" wire:ignore>
                        <canvas data-audit-chart="branches" role="img" aria-label="Contenedores por sucursal, agrupados por estado. Valores detallados en la tabla inferior."></canvas>
                    </div>
                </div>
                @if(!$totals['total'])<p class="external-audit-chart-empty">Sin actividad en las sucursales autorizadas.</p>@endif
        </article>
    </section>
    <section class="panel-card external-audit-summary">
        <header class="panel-header external-audit-section-heading"><div><h2>Actividad por sucursal</h2><p>Resumen de las asignaciones actuales</p></div><span>{{ now('America/Guatemala')->format('d/m/Y · H:i') }}</span></header>
        <div class="notification-table-wrap external-audit-branch-table" tabindex="0" aria-label="Resumen por sucursal">
            <table><thead><tr><th>Sucursal</th><th>Asignados</th><th>Pendientes</th><th>En revisión</th><th>Aprobados</th><th>Rechazados</th><th>Esperando liberación</th></tr></thead><tbody>
            @foreach($rows as $row)
                <tr @class(['is-active-branch' => $row['id'] === (int) session('sucursal_activa')])><th scope="row">{{ $row['name'] }}</th>@foreach(['total', 'ready', 'in_review', 'approved', 'rejected', 'waiting'] as $key)<td>{{ number_format($row[$key]) }}</td>@endforeach</tr>
            @endforeach
            </tbody></table>
        </div>
    </section>
</div>
