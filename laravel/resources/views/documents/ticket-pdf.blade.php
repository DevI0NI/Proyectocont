@php
    $operationLabel = $operation === 'ingreso' ? 'Ingreso' : 'Salida';
    $activity = $operation === 'ingreso' ? 'Recepcion de contenedor' : 'Despacho de contenedor';
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px 28px; }
        body { color: #000; font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; margin: 0; }
        .sheet { padding: 16px 26px 94px; }
        .head { display: table; margin-bottom: 16px; width: 100%; }
        .brand, .folio { display: table-cell; vertical-align: middle; width: 50%; }
        .brand-inner { display: table; }
        .logo, .brand-text { display: table-cell; vertical-align: middle; }
        .logo img { max-height: 54px; max-width: 78px; }
        .brand-text { padding-left: 10px; }
        .brand-text strong { color: #06194e; display: block; font-size: 17px; line-height: 1.05; }
        .brand-text span { color: #06194e; display: block; font-size: 8px; font-weight: bold; letter-spacing: 4px; margin-top: 4px; text-transform: uppercase; }
        .folio { color: #06194e; text-align: right; }
        .folio small { display: block; font-size: 8px; font-weight: bold; letter-spacing: 1.4px; text-transform: uppercase; }
        .folio h1 { color: #06194e; font-size: 24px; font-weight: normal; letter-spacing: 1.4px; line-height: 1; margin: 4px 0 7px; }
        .folio p { color: #000; font-size: 11px; font-weight: bold; line-height: 1.2; margin: 2px 0; }
        .title { border-bottom: 1.5px solid #10245c; border-top: 1.5px solid #10245c; color: #0b2461; font-size: 17px; font-weight: bold; margin: 0 0 13px; padding: 7px 8px; text-align: center; }
        .summary { border-bottom: 1px solid #10245c; border-top: 1px solid #10245c; margin-bottom: 14px; padding: 11px 7px 8px; }
        .summary p { font-size: 11px; line-height: 1.32; margin: 0 0 6px; text-align: justify; }
        .summary strong { font-weight: bold; }
        .warning { font-weight: bold; margin-top: 8px !important; text-align: left !important; }
        h2 { color: #0b2461; font-size: 14px; margin: 13px 0 7px; }
        table { border-collapse: collapse; font-size: 10px; margin-bottom: 11px; table-layout: fixed; width: 100%; }
        th, td { border: 1px solid #1a1f2c; padding: 5px 6px; text-align: center; vertical-align: middle; word-wrap: break-word; }
        th { background: #cfe1eb; font-weight: bold; }
        thead th { background: #b8d8ec; }
        .reference { border: 1px solid #1a1f2c; margin: 10px 0 22px; padding: 6px 8px; }
        .reference strong { color: #0b2461; display: block; font-size: 10px; margin-bottom: 3px; }
        .reference p { font-size: 10px; margin: 0; }
        .signatures { display: table; margin: 54px 0 22px; width: 100%; }
        .signature { display: table-cell; padding: 0 16px; width: 50%; }
        .signature span { border-top: 1px solid #000; display: block; height: 14px; }
        .signature strong { font-size: 10px; }
        .company-footer { border-top: 1px solid #000; bottom: 30px; display: table; left: 54px; padding-top: 8px; position: fixed; right: 54px; width: auto; }
        .company-footer-logo, .company-footer-text { display: table-cell; vertical-align: middle; }
        .company-footer-logo { text-align: right; width: 46%; }
        .company-footer-logo img { max-height: 24px; max-width: 42px; }
        .company-footer-text { color: #06194e; font-size: 10px; font-weight: bold; padding-left: 8px; text-align: left; width: 54%; }
        .company-footer-text span { display: block; font-size: 8px; letter-spacing: 2.6px; margin-top: 2px; text-transform: uppercase; }
    </style>
</head>
<body>
    <main class="sheet">
        <header class="head">
            <div class="brand">
                <div class="brand-inner">
                    <div class="logo">
                        @if($logoData)
                            <img src="{{ $logoData }}" alt="IPSA">
                        @endif
                    </div>
                    <div class="brand-text">
                        <strong>Sistema<br>Proportsa</strong>
                        <span>{{ $sucursal->nombre }}</span>
                    </div>
                </div>
            </div>
            <div class="folio">
                <small>No. de constancia EIR</small>
                <h1>{{ $data['numero'] }}</h1>
                <p>Creacion: {{ $data['fecha'] }} a las {{ $data['hora'] }}</p>
                <p>Operacion: {{ $operationLabel }}</p>
            </div>
        </header>

        <div class="title">{{ $operation === 'salida' ? 'EIR de salida' : 'Constancia operativa de ingreso' }}</div>

        <section class="summary">
            <p>
                Esta constancia registra el movimiento de <strong>{{ strtolower($operationLabel) }}</strong> del equipo
                <strong>{{ $data['equipo'] }}</strong> dentro del inventario operativo de
                <strong>{{ $sucursal->nombre }}</strong>.
            </p>
            <p>
                Actividad registrada: <strong>{{ $activity }}</strong>. {{ $operation === 'salida' ? 'El contenedor se retira del' : 'El contenedor queda asignado al' }}
                <strong>{{ $data['patio'] }}</strong>, en el <strong>Bloque {{ $data['bloque'] }}</strong>,
                posicion <strong>{{ $data['posicion'] }}</strong>.
            </p>
            @if($operation === 'salida')<p>Auditora: <strong>{{ $data['auditora'] }}</strong>. Dictamen: <strong>{{ $data['auditoria'] }}</strong>. Registrado por: <strong>{{ $data['usuario'] }}</strong>.</p>@endif
            <p class="warning">CONSTANCIA INTERNA DE INVENTARIO Y CONTROL DE PATIO</p>
        </section>

        <h2>Informacion del Contenedor</h2>
        <table>
            <tbody>
                <tr><th>Contenedor</th><td>{{ $data['equipo'] }}</td><th>Tipo / Modelo</th><td>{{ $data['tipo'] }} / {{ $data['modelo'] }}</td></tr>
                <tr><th>ISO Code</th><td>{{ $data['iso_code'] }}</td><th>Marca</th><td>{{ $data['marca'] }}</td></tr>
                @if($operation === 'salida')<tr><th>Naviera</th><td colspan="3">{{ $data['naviera'] }}</td></tr>
                @else<tr><th>Naviera</th><td>{{ $data['naviera'] }}</td><th>Marchamo</th><td>{{ $data['marchamo'] }}</td></tr>@endif
                <tr><th>Estado Unidad</th><td>{{ $data['estado_unidad'] }}</td><th>Estado Estructura</th><td>{{ $data['estado_box'] }}</td></tr>
                <tr><th>Estatus IPSA</th><td>{{ $data['ipsa'] }}</td><th>Ubicacion</th><td>{{ $data['patio'] }} / B{{ $data['bloque'] }} / P{{ $data['posicion'] }}</td></tr>
            </tbody>
        </table>

        <h2>Transporte y Control</h2>
        <table>
            <thead>
                <tr><th>Transportista</th><th>Conductor</th><th>Placa Cabezal</th><th>Placa Chasis</th></tr>
            </thead>
            <tbody>
                <tr><td>{{ $data['transportista'] }}</td><td>{{ $data['conductor'] }}</td><td>{{ $data['placa_cabezal'] }}</td><td>{{ $data['placa_chasis'] }}</td></tr>
            </tbody>
        </table>

        <section class="reference">
            <strong>Referencia</strong>
            <p>{{ $data['referencia'] }}</p>
        </section>

        <footer>
            <div class="signatures">
                <div class="signature"><span></span><strong>Entrega / Autoriza</strong></div>
                <div class="signature"><span></span><strong>Recibe / Transportista</strong></div>
            </div>
            <div class="company-footer">
                <div class="company-footer-logo">
                    @if($logoData)
                        <img src="{{ $logoData }}" alt="IPSA">
                    @endif
                </div>
                <div class="company-footer-text">
                    Sistema Proportsa
                    <span>{{ $sucursal->nombre }}</span>
                </div>
            </div>
        </footer>
    </main>
</body>
</html>
