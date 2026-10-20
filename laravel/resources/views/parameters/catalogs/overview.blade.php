@extends('layouts.app')
@section('content')
    <section class="view-header parameters-hero"><div><span class="dashboard-eyebrow">Administracion</span><h1>Catalogos</h1></div></section>
    <div class="parameters-grid">
        @foreach($catalogs as $key => $item)
            <a href="{{ route('catalogs.index', $key) }}" class="parameter-card">
                <span class="parameter-card-icon catalog-card-icon" aria-hidden="true"><span data-catalog-icon="{{ $item['icon'] }}"></span></span>
                <span class="parameter-card-copy"><strong>{{ $item['title'] }}</strong><span>{{ match($key) {
                    'navieras' => 'Navieras, sucursales de operacion y auditoras vinculadas.',
                    'auditoras' => 'Empresas externas de revision de contenedores.',
                    'iso' => 'Tamano, tipo y dimensiones del contenedor.',
                    'repuestos' => 'Materiales, unidades y costos de referencia.',
                    'reparaciones' => 'Trabajos y tarifas para estimados de reparacion.',
                } }}</span></span>
            </a>
        @endforeach
    </div>
@endsection
