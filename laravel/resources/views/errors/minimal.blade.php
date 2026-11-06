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
    $backgroundPlaceholder = 'data:image/webp;base64,UklGRuoAAABXRUJQVlA4IN4AAADwBQCdASowABgAPslUoUunpKMhsAqo8BkJZgC+d6uTjyg0QMFX0rVKXpwqm1X6M79h0COuTvHogAD+WZpKnHJr6FPRqvomszA6eP24WoF0VKoLIa38cJogWY7vqQW7+W0zT1Kb3zWBC0Xra4Q1ysIZGzn2gVlobdlU7fXt6/Bvj/zrqlnLuzihEVFhLAbpJ8PyI4ilDDGa7DaS1ZU9/QnuLUT6wkfITZI8ICUAqPgDySGYiXEqAac3H/FP9PvCcDF56jKqfQcci1+q0bUIj1okiM5L7h4OwQDThbgAAAA=';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }} · PROPORTSA</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preload" as="image" href="{{ asset('imagenes/login-fondo.webp') }}">
    <style>
        @view-transition { navigation: auto; }
        :root { --brand: #14b8a6; --brand-deep: #0f766e; --ink: #f1fbfb; --muted: rgba(226, 244, 243, 0.8); }
        * { box-sizing: border-box; }
        html { background: #0b2a2c; }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: grid; place-items: center; padding: 28px 16px; color: var(--ink); font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; overflow-x: hidden; }
        .backdrop { position: fixed; inset: 0; z-index: -2; background: url("{{ $backgroundPlaceholder }}") center / cover no-repeat; }
        .backdrop::before { content: ""; position: absolute; inset: 0; background: url("{{ asset('imagenes/login-fondo.webp') }}") center / cover no-repeat; animation: photoIn 1.1s ease-out both; }
        .backdrop::after { content: ""; position: absolute; inset: 0; background: radial-gradient(circle at 18% 22%, rgba(45, 212, 191, 0.2), transparent 34%), radial-gradient(circle at 82% 78%, rgba(128, 203, 196, 0.16), transparent 36%), linear-gradient(135deg, rgba(2, 6, 23, 0.82), rgba(4, 47, 46, 0.7) 50%, rgba(2, 6, 23, 0.86)); backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px); }
        .glow { position: fixed; z-index: -1; width: 420px; height: 420px; border-radius: 50%; filter: blur(70px); opacity: 0.35; animation: drift 14s ease-in-out infinite alternate; pointer-events: none; }
        .glow.one { background: #14b8a6; top: -140px; left: -120px; }
        .glow.two { background: #0e7490; bottom: -160px; right: -120px; animation-delay: -7s; }
        .scene { width: min(500px, 100%); text-align: center; animation: rise 0.7s cubic-bezier(0.16, 1, 0.3, 1) both; }
        .big-code { font-size: clamp(88px, 22vw, 150px); font-weight: 800; line-height: 0.9; letter-spacing: 0.04em; margin: 0 0 -26px; background: linear-gradient(180deg, rgba(255, 255, 255, 0.34), rgba(255, 255, 255, 0.02)); -webkit-background-clip: text; background-clip: text; color: transparent; user-select: none; animation: fadeIn 1s ease-out 0.15s both; }
        .card { position: relative; background: rgba(9, 30, 34, 0.58); border: 1px solid rgba(255, 255, 255, 0.14); border-radius: 26px; padding: 34px 28px 30px; box-shadow: 0 30px 90px rgba(0, 0, 0, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.08); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); }
        .logo { height: 54px; margin-bottom: 22px; filter: drop-shadow(0 10px 22px rgba(0, 0, 0, 0.35)); }
        .icon { position: relative; width: 66px; height: 66px; margin: 0 auto 18px; border-radius: 999px; background: linear-gradient(135deg, var(--brand), var(--brand-deep)); color: #fff; display: grid; place-items: center; box-shadow: 0 14px 34px rgba(20, 184, 166, 0.35); animation: float 3.6s ease-in-out infinite; }
        .icon::after { content: ""; position: absolute; inset: -7px; border-radius: inherit; border: 2px solid rgba(45, 212, 191, 0.45); animation: ring 2.6s ease-out infinite; }
        .icon svg { width: 30px; height: 30px; }
        .code { font-size: 12px; font-weight: 700; letter-spacing: 0.26em; color: #5eead4; margin: 0 0 8px; }
        h1 { font-size: 24px; line-height: 1.25; margin: 0 0 10px; }
        .message { color: var(--muted); font-size: 15px; line-height: 1.55; margin: 0 auto 26px; max-width: 360px; }
        .stagger > * { animation: rise 0.6s cubic-bezier(0.16, 1, 0.3, 1) both; }
        .stagger > *:nth-child(1) { animation-delay: 0.1s; }
        .stagger > *:nth-child(2) { animation-delay: 0.16s; }
        .stagger > *:nth-child(3) { animation-delay: 0.22s; }
        .stagger > *:nth-child(4) { animation-delay: 0.28s; }
        .stagger > *:nth-child(5) { animation-delay: 0.34s; }
        .stagger > *:nth-child(6) { animation-delay: 0.4s; }
        .actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 9px; font: inherit; font-size: 15px; font-weight: 650; border-radius: 999px; padding: 12px 24px; text-decoration: none; transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s ease, background-color 0.25s ease, border-color 0.25s ease; }
        .btn svg { width: 18px; height: 18px; transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1); }
        .btn:hover { transform: translateY(-2px); }
        .btn:active { transform: translateY(0) scale(0.97); }
        .btn:focus-visible { outline: 3px solid rgba(94, 234, 212, 0.6); outline-offset: 3px; }
        .primary { background: linear-gradient(135deg, var(--brand), var(--brand-deep)); color: #fff; box-shadow: 0 12px 28px rgba(20, 184, 166, 0.32); }
        .primary:hover { box-shadow: 0 18px 38px rgba(20, 184, 166, 0.48); }
        .primary:hover svg { transform: translateX(4px); }
        .secondary { color: var(--ink); border: 1px solid rgba(255, 255, 255, 0.24); background: rgba(255, 255, 255, 0.04); }
        .secondary:hover { background: rgba(255, 255, 255, 0.1); border-color: rgba(255, 255, 255, 0.4); }
        .secondary:hover svg { transform: translateX(-4px); }
        .footer { margin-top: 18px; font-size: 12px; letter-spacing: 0.08em; color: rgba(226, 244, 243, 0.55); animation: fadeIn 1s ease-out 0.5s both; }
        @keyframes rise { from { opacity: 0; transform: translateY(18px) scale(0.985); } to { opacity: 1; transform: none; } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes photoIn { from { opacity: 0; transform: scale(1.06); } to { opacity: 1; transform: scale(1); } }
        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
        @keyframes ring { from { opacity: 0.9; transform: scale(0.92); } to { opacity: 0; transform: scale(1.35); } }
        @keyframes drift { from { transform: translate(0, 0); } to { transform: translate(60px, 40px); } }
        ::view-transition-old(root), ::view-transition-new(root) { animation-duration: 0.28s; }
        @media (max-width: 480px) { .card { padding: 28px 20px 24px; border-radius: 22px; } h1 { font-size: 21px; } .actions { flex-direction: column-reverse; } .btn { justify-content: center; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
    </style>
</head>
<body>
    <div class="backdrop" aria-hidden="true"></div>
    <div class="glow one" aria-hidden="true"></div>
    <div class="glow two" aria-hidden="true"></div>
    <main class="scene" role="main">
        <p class="big-code" aria-hidden="true">{{ $code }}</p>
        <div class="card stagger">
            <img class="logo" src="{{ asset('imagenes/logo-ipsa.webp') }}" alt="IPSA">
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
            <p class="message">{{ $message }}</p>
            <div class="actions">
                @if ($canGoBack)
                    <a class="btn secondary" href="{{ $previous }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
                        Regresar
                    </a>
                @endif
                <a class="btn primary" href="{{ url('/') }}">
                    Ir al inicio
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </div>
        </div>
        <p class="footer">PROPORTSA · Sistema portuario</p>
    </main>
</body>
</html>
