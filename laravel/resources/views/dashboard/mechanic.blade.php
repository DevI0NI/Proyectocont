@extends('layouts.app')

@section('content')
    <div class="dashboard-livewire-shell mechanic-dashboard" data-mechanic-dashboard>
        <section class="view-header dashboard-heading">
            <div>
                <span class="dashboard-eyebrow">Mi taller</span>
                <h1>{{ $sucursal->nombre }}</h1>
                <p>Tus reparaciones, correcciones pendientes y contenedores por atender.</p>
            </div>
        </section>

        <section class="dashboard-focus-grid">
            <article class="panel-card dashboard-hero">
                <div class="panel-header">
                    <div>
                        <h2>Trabajo abierto</h2>
                        <p>Reparaciones y correcciones a tu cargo</p>
                    </div>
                </div>

                <div class="hero-number-row">
                    <strong>{{ number_format($counts['in_progress'] + $counts['returned_for_repair']) }}</strong>
                    <span>{{ $counts['in_progress'] + $counts['returned_for_repair'] === 1 ? 'trabajo por terminar' : 'trabajos por terminar' }}</span>
                </div>

                <div class="dashboard-mini-metrics">
                    <div>
                        <span>Correcciones</span>
                        <strong>{{ number_format($counts['returned_for_repair']) }}</strong>
                    </div>
                    <div>
                        <span>En revisión</span>
                        <strong>{{ number_format($counts['pending_review']) }}</strong>
                    </div>
                    <div>
                        <span>Finalizados en el mes</span>
                        <strong>{{ number_format($counts['completed_this_month']) }}</strong>
                    </div>
                </div>
            </article>

            <article class="panel-card status-panel">
                <div class="panel-header">
                    <div>
                        <h2>Mis trabajos por estado</h2>
                        <p>Todas tus reparaciones en esta sucursal</p>
                    </div>
                </div>

                <div class="status-chart-wrap">
                    <div class="status-chart-canvas-wrap">
                        <canvas
                            class="status-chart-canvas"
                            aria-label="Gráfica de mis trabajos por estado"
                            role="img"
                            data-labels='@json(collect($segments)->pluck('label'))'
                            data-values='@json(collect($segments)->pluck('value'))'
                            data-colors='@json(collect($segments)->pluck('color'))'
                            data-total="{{ $total }}"
                            data-center-label="{{ $total === 1 ? 'trabajo' : 'trabajos' }}"
                            data-empty-label="Sin trabajos"
                        ></canvas>
                    </div>

                    <div class="status-legend">
                        @foreach ($segments as $segment)
                            <div class="status-legend-row">
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
                        <h2>Por atender</h2>
                        <p>Contenedores listos para iniciar reparación</p>
                    </div>
                </div>

                <div class="pyp-copy mechanic-waiting-total">
                    <strong>{{ number_format($waitingCount) }}</strong>
                    <span>{{ $waitingCount === 1 ? 'contenedor sin trabajo asignado' : 'contenedores sin trabajo asignado' }}</span>
                </div>

                <div class="activity-list">
                    @forelse ($waitingContainers as $container)
                        <a class="activity-item mechanic-dashboard-link" href="{{ route('maintenance.container', $container->id) }}">
                            <time>{{ $container->fecha_ingreso ? \Carbon\Carbon::parse($container->fecha_ingreso)->format('d/m') : 'N/D' }}</time>
                            <div>
                                <strong>{{ $container->nombre }}</strong>
                                <span>{{ $container->yard_name }} · Bloque {{ $container->block_number }}</span>
                            </div>
                        </a>
                    @empty
                        <p class="empty-copy">No hay contenedores esperando reparación.</p>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="dashboard-detail-grid">
            <article class="panel-card yard-chart-panel">
                <div class="panel-header">
                    <div>
                        <h2>Lo que sigue</h2>
                        <p>Primero las correcciones, luego las reparaciones más antiguas</p>
                    </div>
                    <a class="button secondary" href="{{ route('maintenance.jobs', ['mine' => 1]) }}">Ver mis trabajos</a>
                </div>

                <div class="activity-list">
                    @forelse ($nextJobs as $job)
                        <a class="activity-item mechanic-dashboard-link" href="{{ $job->status === 'returned_for_repair' ? route('corrections.show', $job->id) : route('maintenance.show', $job->id) }}">
                            <time>{{ \Carbon\Carbon::parse($job->updated_at)->format('d/m H:i') }}</time>
                            <div>
                                <strong>{{ $job->container_name }}</strong>
                                <span>{{ \App\Services\MechanicDashboard::SEGMENTS[$job->status]['label'] }}{{ $job->repair_type ? ' · '.$job->repair_type : '' }}</span>
                            </div>
                        </a>
                    @empty
                        <p class="empty-copy">No tienes reparaciones ni correcciones pendientes.</p>
                    @endforelse
                </div>
            </article>

            <article class="panel-card activity-panel">
                <div class="panel-header">
                    <div>
                        <h2>Actividad reciente</h2>
                        <p>Movimientos en tus trabajos</p>
                    </div>
                </div>

                <div class="activity-list">
                    @forelse ($activity as $event)
                        <article class="activity-item">
                            <time>{{ \Carbon\Carbon::parse($event->created_at)->format('d/m H:i') }}</time>
                            <div>
                                <strong>{{ \App\Services\MechanicDashboard::ACTIONS[$event->action] }}</strong>
                                <span>{{ $event->container_name }} · {{ $event->actor ?? 'N/D' }}</span>
                            </div>
                        </article>
                    @empty
                        <p class="empty-copy">Todavía no hay actividad en tus trabajos.</p>
                    @endforelse
                </div>
            </article>
        </section>
    </div>
@endsection
