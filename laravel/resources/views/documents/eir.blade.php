@extends('layouts.app')

@section('content')
    <section class="page-heading">
        <div>
            <span class="eyebrow">Documento {{ $operation }}</span>
            <h1>Constancia {{ $operation === 'ingreso' ? 'de ingreso' : 'de salida' }}</h1>
            <p>Formulario visual para completar datos documentales del transportista y la operacion.</p>
        </div>
        <div class="toolbar-actions">
            <a href="{{ $operation === 'ingreso' ? route('documents.ingress.ticket', request()->query()) : route('documents.dispatch.ticket', request()->query()) }}" class="button primary">Ver ticket</a>
            <button type="button" onclick="window.print()" class="button secondary">Imprimir</button>
        </div>
    </section>

    <section class="form-panel">
        <form class="record-form wide-form">
            <label>
                <span>Numero de constancia</span>
                <input type="text" value="{{ $data['numero'] }}" readonly>
            </label>
            <label>
                <span>Fecha y hora</span>
                <input type="text" value="{{ $data['fecha'] }} {{ $data['hora'] }}" readonly>
            </label>
            <label>
                <span>Numero de equipo</span>
                <input type="text" maxlength="255" value="{{ $data['equipo'] }}">
            </label>
            <label>
                <span>Tipo / Modelo</span>
                <input type="text" maxlength="255" value="{{ $data['tipo'] }} / {{ $data['modelo'] }} / ISO {{ $data['iso_code'] }}">
            </label>
            <label>
                <span>Marca</span>
                <input type="text" maxlength="255" value="{{ $data['marca'] }}">
            </label>
            <label>
                <span>Naviera</span>
                <input type="text" maxlength="255" value="{{ $data['naviera'] }}">
            </label>
            <label>
                <span>Cliente / Agencia</span>
                <input type="text" maxlength="255" value="{{ $data['cliente'] }}">
            </label>
            <label>
                <span>Transportista</span>
                <input type="text" maxlength="255" value="{{ $data['transportista'] }}">
            </label>
            <label>
                <span>Conductor</span>
                <input type="text" maxlength="255" value="{{ $data['conductor'] }}">
            </label>
            <label>
                <span>Licencia</span>
                <input type="text" maxlength="255" value="{{ $data['licencia'] }}">
            </label>
            <label>
                <span>Placa cabezal</span>
                <input type="text" maxlength="255" value="{{ $data['placa_cabezal'] }}">
            </label>
            <label>
                <span>Placa chasis</span>
                <input type="text" maxlength="255" value="{{ $data['placa_chasis'] }}">
            </label>
            <label>
                <span>Marchamo</span>
                <input type="text" maxlength="255" value="{{ $data['marchamo'] }}">
            </label>
            <label class="field-wide">
                <span>Ubicacion</span>
                <input type="text" maxlength="255" value="{{ $data['patio'] }} / Bloque {{ $data['bloque'] }} / Posicion {{ $data['posicion'] }}">
            </label>
            <label class="field-wide">
                <span>Referencia</span>
                <textarea rows="4" maxlength="255">{{ $data['referencia'] }}</textarea>
            </label>
        </form>
    </section>
@endsection
