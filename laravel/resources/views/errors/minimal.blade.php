@php
    $code = (int) trim($__env->yieldContent('code'));
    $titles = [
        401 => 'Inicia sesión para continuar',
        403 => 'No tienes acceso a esta sección',
        404 => 'No encontramos esta página',
        419 => 'Tu sesión expiró',
        429 => 'Demasiados intentos',
        500 => 'Algo salió mal',
        503 => 'Estamos en mantenimiento',
    ];
    $defaults = [
        401 => 'Necesitas iniciar sesión para ver esta página.',
        403 => 'Tu usuario no tiene permiso para entrar aquí.',
        404 => 'La dirección no existe o ya no está disponible.',
        419 => 'Recarga la página e intenta de nuevo.',
        429 => 'Espera un momento antes de volver a intentarlo.',
        500 => 'Ocurrió un error inesperado. Intenta de nuevo en unos minutos.',
        503 => 'El sistema volverá a estar disponible en breve.',
    ];
    $exceptionMessage = isset($exception) ? $exception->getMessage() : '';
    $message = in_array($code, [403, 419, 429], true) && $exceptionMessage !== '' && ! in_array($exceptionMessage, ['Forbidden', 'Not Found', 'Unauthorized', 'Server Error', 'Service Unavailable', 'Too Many Requests', 'Page Expired'], true)
        ? $exceptionMessage
        : ($defaults[$code] ?? 'No pudimos mostrar esta página.');
    $previous = url()->previous();
    $canGoBack = $previous !== url()->current() && $previous !== url('/');
    $title = $titles[$code] ?? 'No pudimos mostrar esta página';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · PROPORTSA</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        :root { --brand: #0f766e; --ink: #12313a; --muted: #5b7480; --card: #ffffff; --page: #eef5f6; --line: #dce8ec; --soft: #e3f5f2; }
        @media (prefers-color-scheme: dark) {
            :root { --ink: #e6f1f2; --muted: #9db5bb; --card: #15272e; --page: #0d1b20; --line: #28424a; --soft: #173d39; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--page); color: var(--ink); font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; }
        .card { width: min(460px, 100%); background: var(--card); border: 1px solid var(--line); border-radius: 24px; padding: 36px 28px 30px; text-align: center; box-shadow: 0 28px 80px rgba(31, 62, 72, 0.14); }
        .logo { height: 46px; margin-bottom: 22px; }
        @media (prefers-color-scheme: dark) { .logo { filter: brightness(0) invert(1); opacity: 0.9; } }
        .icon { width: 64px; height: 64px; margin: 0 auto 18px; border-radius: 999px; background: var(--soft); color: var(--brand); display: grid; place-items: center; }
        .icon svg { width: 30px; height: 30px; }
        .code { font-size: 13px; font-weight: 700; letter-spacing: 0.22em; color: var(--brand); margin: 0 0 6px; }
        h1 { font-size: 22px; margin: 0 0 10px; }
        p { color: var(--muted); font-size: 15px; line-height: 1.5; margin: 0 0 26px; }
        .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        a { font: inherit; font-size: 15px; font-weight: 600; border-radius: 999px; padding: 11px 22px; cursor: pointer; text-decoration: none; }
        .primary { background: var(--brand); color: #fff; border: 0; }
        .secondary { background: transparent; color: var(--ink); border: 1px solid var(--line); }
    </style>
</head>
<body>
    <main class="card" role="main">
        <img class="logo" src="{{ asset('imagenes/IPSA.png') }}" alt="IPSA">
        <div class="icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                @if (in_array($code, [401, 403], true))
                    <rect x="5" y="10.5" width="14" height="10" rx="2.2"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>
                @elseif ($code === 404)
                    <circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>
                @else
                    <circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v5M12 16.2v.1"/>
                @endif
            </svg>
        </div>
        <p class="code">ERROR {{ $code }}</p>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <div class="actions">
            @if ($canGoBack)
                <a class="secondary" href="{{ $previous }}">Regresar</a>
            @endif
            <a class="primary" href="{{ url('/') }}">Ir al inicio</a>
        </div>
    </main>
</body>
</html>
