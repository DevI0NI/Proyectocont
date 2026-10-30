<div class="dashboard-livewire-shell" id="dashboardLivewireRoot">
    <section class="view-header dashboard-heading">
        <div>
            <span class="dashboard-eyebrow">Resumen operativo</span>
            <h1>{{ $sucursal->nombre ?? 'Sucursal activa' }}</h1>
            <p>Estado actual de inventario, capacidad y seguimiento.</p>
        </div>
    </section>

    <section class="dashboard-focus-grid">
        <article class="panel-card dashboard-hero">
            <div class="panel-header">
                <div>
                    <h2>Inventario activo</h2>
                    <p>Contenedores dentro de la sucursal</p>
                </div>
                <span class="hero-kpi">{{ $occupancyPercent }}%</span>
            </div>

            <div class="hero-number-row">
                <strong>{{ number_format($stats['activos']) }}</strong>
                <span>de {{ number_format($stats['capacidad']) }} espacios</span>
            </div>

            <div class="occupancy-track">
                <div class="occupancy-fill" style="width: {{ min($occupancyPercent, 100) }}%;"></div>
            </div>

            <div class="dashboard-mini-metrics">
                <div>
                    <span>Disponibles</span>
                    <strong>{{ number_format($stats['disponibles']) }}</strong>
                </div>
                <div>
                    <span>Espacios libres</span>
                    <strong>{{ number_format($freeCapacity) }}</strong>
                </div>
                <div>
                    <span>Despachados</span>
                    <strong>{{ number_format($stats['despachados']) }}</strong>
                </div>
            </div>
        </article>

        <article class="panel-card status-panel">
            <div class="panel-header">
                <div>
                    <h2>Estado operativo</h2>
                    <p>Distribución de contenedores activos</p>
                </div>
            </div>

            <div class="status-chart-wrap">
                <div class="status-chart-canvas-wrap" wire:ignore>
                    <canvas
                        class="status-chart-canvas"
                        aria-label="Gráfica de estado operativo"
                        role="img"
                        data-labels='@json(collect($statusSegments)->pluck('label'))'
                        data-values='@json(collect($statusSegments)->pluck('value'))'
                        data-colors='@json(collect($statusSegments)->pluck('color'))'
                        data-total="{{ $stats['activos'] }}"
                    ></canvas>
                </div>

                <div class="status-legend">
                    @foreach ($statusSegments as $segment)
                        <div class="status-legend-row" wire:key="status-segment-{{ $segment['key'] }}">
                            <span class="status-dot" style="--dot-color: {{ $segment['color'] }}"></span>
                            <strong>{{ $segment['label'] }}</strong>
                            <small>{{ number_format($segment['value']) }} · {{ $segment['percent'] }}%</small>
                        </div>
                    @endforeach
                </div>
            </div>
        </article>

        <article class="panel-card pyp-panel">
            <div class="panel-header">
                <div>
                    <h2>Seguimiento PYP</h2>
                    <p>Cobertura sobre inventario activo</p>
                </div>
            </div>

            <div class="pyp-chart-layout">
                <div class="pyp-chart-canvas-wrap" wire:ignore>
                    <canvas
                        class="pyp-chart-canvas"
                        aria-label="Gráfica de cobertura PYP"
                        role="img"
                        data-audited="{{ $stats['auditados'] }}"
                        data-total="{{ $stats['activos'] }}"
                        data-percent="{{ $pypCoverage }}"
                    ></canvas>
                </div>

                <div class="pyp-copy">
                    <strong>{{ number_format($stats['auditados']) }}</strong>
                    <span>contenedores auditados de {{ number_format($stats['activos']) }}</span>
                </div>
            </div>
        </article>
    </section>

    <section class="dashboard-detail-grid">
        <article class="panel-card patios-panel yard-chart-panel">
            <div class="panel-header">
                <div>
                    <h2>Ocupación por patio</h2>
                    <p>Comparativa de uso contra capacidad</p>
                </div>
            </div>

            @php
                $yardChartData = $yardOccupancy->map(function ($yard) {
                    $capacity = (int) $yard->capacidad;
                    $occupied = (int) $yard->ocupados;

                    return [
                        'label' => $yard->nombre,
                        'occupied' => $occupied,
                        'capacity' => $capacity,
                        'percent' => $capacity > 0 ? (int) round(($occupied / $capacity) * 100) : 0,
                    ];
                })->values();
            @endphp

            @if ($yardChartData->isNotEmpty())
                <div class="yard-chart-canvas-wrap" wire:ignore>
                    <div class="yard-chart-canvas-body">
                        <canvas
                            class="yard-chart-canvas"
                            aria-label="Gráfica horizontal de ocupación por patio"
                            role="img"
                            data-labels='@json($yardChartData->pluck('label'))'
                            data-values='@json($yardChartData->pluck('percent'))'
                            data-occupied='@json($yardChartData->pluck('occupied'))'
                            data-capacity='@json($yardChartData->pluck('capacity'))'
                        ></canvas>
                    </div>
                </div>
            @else
                    <p class="empty-copy">No hay patios activos para esta sucursal.</p>
            @endif
        </article>

        @if ($isInternalDashboard)
            <article class="panel-card activity-panel">
                <div class="panel-header">
                    <div>
                        <h2>Actividad reciente</h2>
                        <p>Últimos eventos</p>
                    </div>
                </div>

                <div class="activity-list">
                    @forelse ($recentActivity->take(6) as $event)
                        <article class="activity-item" wire:key="activity-{{ $event->fecha_evento }}-{{ $event->contenedor ?? 'none' }}">
                            <time>{{ \Carbon\Carbon::parse($event->fecha_evento)->format('d/m H:i') }}</time>
                            <div>
                                <strong>{{ $event->operacion }}</strong>
                                <span>{{ $event->contenedor ?? 'N/D' }} · {{ $event->usuario ?? 'N/D' }}</span>
                            </div>
                        </article>
                    @empty
                        <p class="empty-copy">No hay actividad reciente.</p>
                    @endforelse
                </div>
            </article>
        @endif
    </section>
</div>
