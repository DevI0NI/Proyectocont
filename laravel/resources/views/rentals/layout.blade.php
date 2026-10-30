<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Parqueo - PROPORTSA</title>
    <script>document.documentElement.dataset.theme = localStorage.getItem('proportsa-theme') || 'light';</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="parking-body">
    <header class="parking-header print-hidden">
        <a class="parking-brand" href="{{ route('rentals.index') }}"><img src="{{ asset('imagenes/IPSA.png') }}" alt="IPSA"><span><small>PROPORTSA</small><strong>Parqueo</strong></span></a>
        <nav aria-label="Parqueo">
            <a class="@if(request()->routeIs('rentals.index', 'rentals.show')) active @endif" href="{{ route('rentals.index') }}">Operacion</a>
            <a class="@if(request()->routeIs('rentals.settings')) active @endif" href="{{ route('rentals.settings') }}">Configuracion</a>
        </nav>
        <div class="parking-account"><span>{{ auth()->user()->username }}</span><button type="button" class="parking-icon" data-parking-theme title="Cambiar tema" aria-label="Cambiar tema"><span data-parking-icon="sun"></span></button><a class="parking-icon" href="{{ route('dashboard') }}" title="Volver al sistema portuario" aria-label="Volver al sistema portuario"><span data-parking-icon="arrow-left"></span></a></div>
    </header>
    <main class="parking-main">
        @if(session('status'))<div class="parking-notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="parking-errors" role="alert"><strong>No se pudo completar la operacion</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
</body>
</html>
