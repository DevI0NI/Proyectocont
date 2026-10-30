@extends('layouts.app')
@section('content')
<div class="dispatch-eir-page">
<section class="page-heading dispatch-eir-heading">
    <div><span class="eyebrow">Despachos</span><h1>EIR de salida</h1><p>{{ $sucursal->nombre }}</p></div>
    <div class="toolbar-actions">
        @if($data)
        <a class="mw-button" href="{{ route('documents.dispatch.eir.pdf', ['event_id' => $data['event_id']]) }}" target="_blank" rel="noopener"><span data-notification-icon="eye" aria-hidden="true"></span>Ver PDF</a>
        <a class="mw-button primary" href="{{ route('documents.dispatch.eir.pdf', ['event_id' => $data['event_id'], 'download' => 1]) }}"><span data-notification-icon="download" aria-hidden="true"></span>Descargar EIR</a>
        @endif
        <a class="mw-button" href="{{ route('dispatches.create') }}"><span data-notification-icon="chevron-left" aria-hidden="true"></span>Volver</a>
    </div>
</section>
@if($data)
<section class="form-panel dispatch-eir-document" aria-label="Constancia de salida {{ $data['numero'] }}">
    <header class="dispatch-eir-document-head">
        <div><span class="eyebrow">Constancia de salida</span><h2>{{ $data['equipo'] }}</h2><p>{{ $data['numero'] }}</p></div>
        <span class="status-badge badge-success">Salida registrada</span>
    </header>
    <section class="dispatch-eir-section" aria-labelledby="eirRegistrationHeading">
        <h3 id="eirRegistrationHeading">Registro de salida</h3>
        <dl class="dispatch-eir-meta">
            <div><dt>Sucursal</dt><dd>{{ $data['sucursal'] }}</dd></div>
            <div><dt>Fecha de salida</dt><dd>{{ $data['fecha'] }} {{ $data['hora'] }}</dd></div>
            <div><dt>Registrado por</dt><dd>{{ $data['usuario'] }}</dd></div>
            <div><dt>Ubicacion de retiro</dt><dd>{{ $data['patio'] }} · Bloque {{ $data['bloque'] }} · Posicion {{ $data['posicion'] }}</dd></div>
        </dl>
    </section>
    <div class="dispatch-eir-columns">
        <section class="dispatch-eir-section" aria-labelledby="eirEquipmentHeading">
            <h3 id="eirEquipmentHeading">Datos del equipo</h3>
            <dl class="dispatch-eir-meta three-columns">
                <div><dt>Tipo de unidad</dt><dd>{{ $data['tipo'] }}</dd></div><div><dt>Modelo</dt><dd>{{ $data['modelo'] }}</dd></div>
                <div><dt>ISO Code</dt><dd>{{ $data['iso_code'] }}</dd></div><div><dt>Marca</dt><dd>{{ $data['marca'] }}</dd></div>
                <div><dt>Naviera</dt><dd>{{ $data['naviera'] }}</dd></div>
                <div><dt>Auditora externa</dt><dd>{{ $data['auditora'] }}</dd></div>
            </dl>
        </section>
        <section class="dispatch-eir-section" aria-labelledby="eirTransportHeading">
            <h3 id="eirTransportHeading">Transporte de salida</h3>
            <dl class="dispatch-eir-meta two-columns">
                <div><dt>Transportista / empresa</dt><dd>{{ $data['transportista'] }}</dd></div><div><dt>Piloto</dt><dd>{{ $data['conductor'] }}</dd></div>
                <div><dt>Placa cabezal</dt><dd>{{ $data['placa_cabezal'] }}</dd></div><div><dt>Placa chasis</dt><dd>{{ $data['placa_chasis'] }}</dd></div>
            </dl>
        </section>
    </div>
    <section class="dispatch-eir-section" aria-labelledby="eirConditionsHeading">
        <h3 id="eirConditionsHeading">Condiciones al despacho</h3>
        <dl class="dispatch-eir-meta four-columns">
            <div><dt>Estado de unidad</dt><dd>{{ $data['estado_unidad'] }}</dd></div><div><dt>Estado de estructura</dt><dd>{{ $data['estado_box'] }}</dd></div>
            <div><dt>Estado IPSA</dt><dd>{{ $data['ipsa'] }}</dd></div><div><dt>Resultado de auditoria</dt><dd>{{ $data['auditoria'] }}</dd></div>
        </dl>
    </section>
    <section class="dispatch-eir-section dispatch-eir-notes" aria-labelledby="eirNotesHeading">
        <h3 id="eirNotesHeading">Observaciones de salida</h3><p>{{ $data['referencia'] }}</p>
    </section>
</section>
@else
<section class="panel-card containers-module standard-records-table dispatch-eir-history" aria-labelledby="eirHistoryHeading">
    <header class="containers-module-head"><div class="view-header-copy"><h2 id="eirHistoryHeading">Constancias de salida</h2></div><span class="transfer-count">{{ $documents->total() }} registros</span></header>
    <div class="containers-table-panel">
    <div class="containers-table-wrap" tabindex="0" role="region" aria-label="Constancias de salida"><table class="containers-table"><thead><tr><th scope="col">Ver</th><th scope="col">Constancia</th><th scope="col">Contenedor</th><th scope="col">Fecha de salida</th></tr></thead><tbody>
    @forelse($documents as $document)
        <tr @class(['dispatch-eir-current' => $data && (int) $data['event_id'] === (int) $document->id])><td data-label="Ver"><a class="mw-button mw-icon" aria-label="Ver EIR {{ $document->folio }}" title="Ver EIR" href="{{ route('documents.dispatch.eir', ['event_id' => $document->id]) }}"><span data-notification-icon="eye" aria-hidden="true"></span></a></td><td data-label="Constancia">{{ $document->folio }}</td><td data-label="Contenedor">{{ $document->equipo }}</td><td data-label="Fecha de salida">{{ $document->fecha }}</td></tr>
    @empty<tr><td colspan="4" class="table-empty-cell">Sin constancias de salida registradas.</td></tr>@endforelse
    </tbody></table></div>
    @include('partials.administration-pagination', ['paginator' => $documents, 'liveTable' => true])
    </div>
</section>
@endif
</div>
@endsection
