@extends('layouts.app')

@section('content')
    @php
        $operationLabel = $operation === 'ingreso' ? 'Ingreso' : 'Salida';
        $operationTitle = $operation === 'ingreso' ? 'Ticket de ingreso' : 'Ticket de salida';
        $activity = $operation === 'ingreso' ? 'Recepcion de contenedor' : 'Despacho de contenedor';
        $returnParams = request()->only(['patio_id', 'bloque_id']);
    @endphp

    <section class="page-heading">
        <div>
            <span class="eyebrow">Ticket {{ $operation }}</span>
            <h1>{{ $operationTitle }}</h1>
            <p>Vista imprimible de la constancia operativa.</p>
        </div>
        <div class="toolbar-actions ticket-document-actions">
            <a href="{{ route('containers.index', $returnParams) }}" class="ticket-document-button secondary">
                <span aria-hidden="true">←</span>
                Salir
            </a>
            <a href="{{ $operation === 'ingreso' ? route('documents.ingress.ticket.pdf', request()->query()) : route('documents.dispatch.ticket.pdf', request()->query()) }}" target="_blank" rel="noopener" class="ticket-document-button primary">
                <span aria-hidden="true">PDF</span>
                Imprimir
            </a>
        </div>
    </section>

    <section class="ticket-sheet ticket-formal">
        <header class="ticket-formal-head">
            <div class="ticket-formal-brand">
                <img src="{{ asset('imagenes/IPSA.png') }}" alt="IPSA" class="ticket-formal-logo">
                <div>
                    <strong>Sistema Proportsa</strong>
                    <span>{{ $sucursal->nombre }}</span>
                </div>
            </div>
            <div class="ticket-formal-number">
                <small>No. de constancia EIR</small>
                <h2>{{ $data['numero'] }}</h2>
                <p>Creacion: {{ $data['fecha'] }} a las {{ $data['hora'] }}</p>
                <p>Operacion: {{ $operationLabel }}</p>
            </div>
        </header>

        <div class="ticket-formal-title">
            <span>Constancia operativa de {{ $operation }}</span>
        </div>

        <section class="ticket-formal-summary">
            <div class="ticket-formal-copy">
                <p>
                    Esta constancia registra el movimiento de <strong>{{ strtolower($operationLabel) }}</strong> del equipo
                    <strong>{{ $data['equipo'] }}</strong> dentro del inventario operativo de
                    <strong>{{ $sucursal->nombre }}</strong>.
                </p>
                <p>
                    Actividad registrada: <strong>{{ $activity }}</strong>. El contenedor queda asignado al
                    <strong>{{ $data['patio'] }}</strong>, en el <strong>Bloque {{ $data['bloque'] }}</strong>,
                    posicion <strong>{{ $data['posicion'] }}</strong>.
                </p>
                <p class="ticket-formal-warning">CONSTANCIA INTERNA DE INVENTARIO Y CONTROL DE PATIO</p>
            </div>
        </section>

        <h3 class="ticket-formal-section">Informacion del Contenedor</h3>
        <table class="ticket-formal-table">
            <tbody>
                <tr>
                    <th>Contenedor</th>
                    <td>{{ $data['equipo'] }}</td>
                    <th>Tipo / Modelo</th>
                    <td>{{ $data['tipo'] }} / {{ $data['modelo'] }}</td>
                </tr>
                <tr>
                    <th>ISO Code</th>
                    <td>{{ $data['iso_code'] }}</td>
                    <th>Marca</th>
                    <td>{{ $data['marca'] }}</td>
                </tr>
                <tr>
                    <th>Naviera</th>
                    <td>{{ $data['naviera'] }}</td>
                    <th>Marchamo</th>
                    <td>{{ $data['marchamo'] }}</td>
                </tr>
                <tr>
                    <th>Estado Unidad</th>
                    <td>{{ $data['estado_unidad'] }}</td>
                    <th>Estado Estructura</th>
                    <td>{{ $data['estado_box'] }}</td>
                </tr>
                <tr>
                    <th>Estatus IPSA</th>
                    <td>{{ $data['ipsa'] }}</td>
                    <th>Ubicacion</th>
                    <td>{{ $data['patio'] }} / B{{ $data['bloque'] }} / P{{ $data['posicion'] }}</td>
                </tr>
            </tbody>
        </table>

        <h3 class="ticket-formal-section">Transporte y Control</h3>
        <table class="ticket-formal-table compact">
            <thead>
                <tr>
                    <th>Transportista</th>
                    <th>Conductor</th>
                    <th>Placa Cabezal</th>
                    <th>Placa Chasis</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $data['transportista'] }}</td>
                    <td>{{ $data['conductor'] }}</td>
                    <td>{{ $data['placa_cabezal'] }}</td>
                    <td>{{ $data['placa_chasis'] }}</td>
                </tr>
            </tbody>
        </table>

        <section class="ticket-formal-reference">
            <strong>Referencia</strong>
            <p>{{ $data['referencia'] }}</p>
        </section>

        <footer class="ticket-formal-footer">
            <div class="ticket-formal-signatures">
                <div><span></span><strong>Entrega / Autoriza</strong></div>
                <div><span></span><strong>Recibe / Transportista</strong></div>
            </div>
            <div class="ticket-formal-meta">
                <img src="{{ asset('imagenes/IPSA.png') }}" alt="IPSA">
                <span><strong>Sistema Proportsa</strong><small>{{ $sucursal->nombre }}</small></span>
            </div>
        </footer>
    </section>
@endsection
