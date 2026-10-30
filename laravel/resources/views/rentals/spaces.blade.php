@extends('layouts.app')

@section('content')
    <section class="page-heading">
        <div>
            <span class="eyebrow">Rentas</span>
            <h1>Espacios y ocupacion</h1>
            <p>Vista base migrada desde el prototipo legacy. Los datos comerciales siguen pendientes de conectarse a tablas reales.</p>
        </div>
        <a href="{{ route('rentals.index') }}" class="button secondary">Volver</a>
    </section>

    <section class="stats-grid">
        <article class="stat-card">
            <span>Espacios totales</span>
            <strong>{{ $summary['total'] }}</strong>
        </article>
        <article class="stat-card">
            <span>Ocupados</span>
            <strong>{{ $summary['ocupado'] }}</strong>
        </article>
        <article class="stat-card">
            <span>Disponibles</span>
            <strong>{{ $summary['disponible'] }}</strong>
        </article>
        <article class="stat-card">
            <span>Reservados / bloqueados</span>
            <strong>{{ $summary['bloqueado'] }}</strong>
        </article>
    </section>

    <section class="rental-layout">
        <div class="workboard">
            <div class="table-toolbar">
                <div>
                    <h2 class="section-title">Mapa de espacios</h2>
                    <p class="table-muted">Referencia visual para disponibilidad operativa por espacio.</p>
                </div>
                <div class="tag-list">
                    <span class="tag">Disponible</span>
                    <span class="tag">Ocupado</span>
                    <span class="tag">Reservado</span>
                    <span class="tag">Bloqueado</span>
                </div>
            </div>

            <div class="rental-space-grid">
                @foreach ($spaces as $space)
                    <article class="rental-space rental-space-{{ $space['estado'] }}">
                        <div>
                            <strong>{{ $space['codigo'] }}</strong>
                            <span>{{ ucfirst($space['estado']) }}</span>
                        </div>
                        <p>{{ $space['cliente'] }}</p>
                        <small>{{ $space['placa'] }}</small>
                    </article>
                @endforeach
            </div>
        </div>

        <div class="workboard">
            <h2>Nueva renta</h2>
            <form class="record-form rental-preview-form">
                <label>
                    <span>Cliente / empresa</span>
                    <input type="text" placeholder="Transportes del Norte" disabled>
                </label>
                <label>
                    <span>Placa</span>
                    <input type="text" placeholder="C-784BDF" disabled>
                </label>
                <label>
                    <span>Plan</span>
                    <select disabled>
                        <option>Mensual</option>
                        <option>Semanal</option>
                        <option>Diario</option>
                    </select>
                </label>
                <label>
                    <span>Espacio</span>
                    <select disabled>
                        <option>A-04</option>
                        <option>B-03</option>
                    </select>
                </label>
                <div class="field-wide">
                    <span class="status-badge badge-warning">Formulario pendiente de persistencia</span>
                </div>
            </form>
        </div>
    </section>

    <section class="workboard rental-table-board">
        <div class="table-toolbar">
            <div>
                <h2 class="section-title">Rentas activas</h2>
                <p class="table-muted">Tabla base para conectar vencimientos, renovaciones y cierres.</p>
            </div>
        </div>

        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Contrato</th>
                        <th>Cliente</th>
                        <th>Placa</th>
                        <th>Espacio</th>
                        <th>Plan</th>
                        <th>Desde</th>
                        <th>Hasta</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($activeRentals as $rental)
                        <tr>
                            <td><span class="role-badge">{{ $rental['contrato'] }}</span></td>
                            <td>{{ $rental['cliente'] }}</td>
                            <td>{{ $rental['placa'] }}</td>
                            <td>{{ $rental['espacio'] }}</td>
                            <td>{{ $rental['plan'] }}</td>
                            <td>{{ $rental['desde'] }}</td>
                            <td>{{ $rental['hasta'] }}</td>
                            <td>
                                <span class="status-badge {{ $rental['estado'] === 'Por vencer' ? 'badge-warning' : 'badge-success' }}">
                                    {{ $rental['estado'] }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
