@extends('layouts.app')

@section('content')
    <section class="view-header parameters-hero">
        <div>
            <span class="dashboard-eyebrow">Administracion</span>
            <h1>Parametros del sistema</h1>
            <p>Configuracion administrativa, usuarios y estructura de la operacion diaria.</p>
        </div>
    </section>

    <section class="panel-card parameters-panel">
        <div class="panel-header">
            <div>
                <h2>Centro administrativo</h2>
                <p>Selecciona la categoria que necesitas configurar.</p>
            </div>
        </div>

        <div class="parameters-grid">
            @foreach ($items as $item)
                <a href="{{ route($item['route'], $item['params'] ?? []) }}" class="parameter-card">
                    <span class="parameter-card-icon" aria-hidden="true">
                        <img src="{{ asset('imagenes/'.$item['icon']) }}" alt="">
                    </span>
                    <span class="parameter-card-copy">
                        <strong>{{ $item['label'] }}</strong>
                        <span>{{ $item['desc'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    </section>
@endsection
