@php
    $loginBackground = 'imagenes/login-fondo.webp';
    $loginPlaceholder = file_exists(public_path('imagenes/login-fondo-mini.webp')) ? 'data:image/webp;base64,'.base64_encode(file_get_contents(public_path('imagenes/login-fondo-mini.webp'))) : '';
    $loginBackgroundVersion = file_exists(public_path($loginBackground)) ? filemtime(public_path($loginBackground)) : time();
    $loginBackgroundUrl = asset($loginBackground).'?v='.$loginBackgroundVersion;
@endphp
<!doctype html>
<html lang="es" class="login-html" style="--login-image: url('{{ $loginBackgroundUrl }}'); --login-placeholder: url('{{ $loginPlaceholder }}')">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ ($passwordSetup ?? false) ? 'Definir contraseña' : 'Ingreso' }} | {{ config('app.name') }}</title>
    <link rel="preload" as="image" href="{{ $loginBackgroundUrl }}" fetchpriority="high">
    @include('partials.app-icons', ['themeColor' => '#0e6f73'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-white antialiased">
    <section class="login-page-background overflow-hidden">
            <img
                src="{{ $loginBackgroundUrl }}"
                alt=""
                fetchpriority="high"
                decoding="async"
                data-login-background
                class="login-enter-background absolute inset-0 h-full w-full object-cover"
            >
            <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(2,6,23,.54),rgba(2,44,46,.28)_45%,rgba(2,6,23,.48))]"></div>
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(255,255,255,.08),transparent_28%),radial-gradient(circle_at_80%_80%,rgba(128,203,196,.10),transparent_30%)]"></div>
        </section>
    <main class="login-page-scroll">

        <section class="login-page-content relative z-10 mx-auto grid w-full max-w-[1120px] content-center gap-6 px-5 py-7 sm:px-8 lg:grid-cols-[minmax(390px,520px)_minmax(360px,420px)] lg:items-center lg:justify-center lg:gap-10 lg:px-10 xl:gap-14">
            <div class="login-enter-brand flex flex-col items-center justify-center text-center lg:items-start lg:text-left">
                <img src="{{ asset('imagenes/logo-ipsa.webp') }}" alt="Logo IPSA Web" class="mb-5 h-16 w-28 object-contain drop-shadow-[0_16px_30px_rgba(0,0,0,.35)] sm:h-20 sm:w-36 lg:mb-9 lg:h-24 lg:w-40">
                <h1 class="max-w-[560px] text-4xl font-extrabold leading-[.98] tracking-normal text-white drop-shadow-lg sm:text-5xl lg:text-6xl xl:text-7xl">
                    @if($passwordSetup ?? false) Protege<br>tu cuenta @else Bienvenido<br>de nuevo @endif
                </h1>
                <p class="mt-4 hidden max-w-[420px] text-sm leading-6 text-white/82 sm:text-base lg:mt-7 lg:block lg:max-w-[540px] lg:text-lg">
                    Accede al control operativo de patios, contenedores y movimientos de Proportsa.
                </p>
            </div>

            <div class="flex items-center justify-center">
                <div class="login-enter-form w-full max-w-[390px] rounded-2xl border border-white/15 bg-slate-950/24 p-5 shadow-[0_28px_80px_rgba(0,0,0,.32)] backdrop-blur-[2px] sm:max-w-[420px] sm:p-7 lg:p-9">
                    <div class="mb-5 sm:mb-7">
                        <h2 class="{{ ($passwordSetup ?? false) ? 'account-password-title' : 'text-3xl sm:text-4xl' }} font-extrabold tracking-normal text-white">{{ ($passwordSetup ?? false) ? 'Nueva contraseña' : 'Iniciar sesión' }}</h2>
                    </div>

                    @if ($errors->any())
                        <div data-account-flash class="account-flash-slot" role="alert"><div class="account-flash-inner"><div class="mb-5 border border-red-300/35 bg-red-500/20 px-4 py-3 text-sm font-semibold text-red-50 shadow-sm">
                            {{ $errors->first() }}
                        </div></div></div>
                    @endif

                    @if(session('status'))<div data-account-flash class="account-flash-slot" role="status"><div class="account-flash-inner"><p class="account-login-status">{{ session('status') }}</p></div></div>@endif

                    <form method="POST" action="{{ ($passwordSetup ?? false) ? route('account.password.store') : route('login.store') }}" id="loginForm" class="space-y-3.5 sm:space-y-4">
                        @csrf

                        @unless($passwordSetup ?? false)
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-semibold text-white/90">Correo electrónico</span>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                maxlength="255"
                                value="{{ old('email') }}"
                                placeholder="Ingrese su correo"
                                required
                                autofocus
                                class="h-11 w-full rounded-xl border border-white/25 bg-white px-3 text-base text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[#80cbc4] focus:ring-4 focus:ring-[#80cbc4]/25 sm:text-[15px]"
                            >
                        </label>
                        @endunless

                        <label class="block">
                            <span class="mb-1.5 block text-xs font-semibold text-white/90">Contraseña</span>
                            <span class="relative block">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    maxlength="{{ ($passwordSetup ?? false) ? 64 : 128 }}"
                                    autocomplete="{{ ($passwordSetup ?? false) ? 'new-password' : 'current-password' }}"
                                    @if($passwordSetup ?? false) minlength="8" aria-describedby="accountPasswordRules" data-account-password @endif
                                    placeholder="Ingrese su contraseña"
                                    required
                                    class="h-11 w-full rounded-xl border border-white/25 bg-white px-3 pr-12 text-base text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[#80cbc4] focus:ring-4 focus:ring-[#80cbc4]/25 sm:text-[15px]"
                                >
                                <button
                                    type="button"
                                    id="togglePassword"
                                    class="password-visibility-toggle absolute right-1 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-[#00695c]"
                                    aria-label="Mostrar contraseña"
                                >
                                    <svg class="password-eye password-eye-open" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M2.75 12s3.35-6.25 9.25-6.25S21.25 12 21.25 12 17.9 18.25 12 18.25 2.75 12 2.75 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M12 14.75a2.75 2.75 0 1 0 0-5.5 2.75 2.75 0 0 0 0 5.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    <svg class="password-eye password-eye-closed" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M3.5 3.5 20.5 20.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
                                        <path d="M9.3 5.98A9.2 9.2 0 0 1 12 5.75c5.9 0 9.25 6.25 9.25 6.25a15.6 15.6 0 0 1-2.3 3.03" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M14.12 14.12A2.75 2.75 0 0 1 9.88 9.88" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M6.35 7.35C3.96 9.08 2.75 12 2.75 12s3.35 6.25 9.25 6.25c1.08 0 2.08-.2 3-.53" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                            </span>
                        </label>

                        @if($passwordSetup ?? false)
                            <div class="account-password-help" id="accountPasswordHelp" aria-hidden="true"><div class="account-password-help-inner">
                            <ul class="account-password-rules" id="accountPasswordRules" aria-label="Requisitos de la contraseña">
                                @foreach(['length' => 'Mínimo 8 caracteres', 'uppercase' => 'Una mayúscula (A-Z)', 'number' => 'Un número', 'symbol' => 'Un símbolo'] as $rule => $label)
                                    <li data-account-password-rule="{{ $rule }}"><input type="checkbox" disabled tabindex="-1" aria-label="{{ $label }}"><span>{{ $label }}</span></li>
                                @endforeach
                            </ul>
                            </div></div>
                            <label class="block"><span class="mb-1.5 block text-xs font-semibold text-white/90">Confirmar contraseña</span><input class="h-11 w-full rounded-xl border border-white/25 bg-white px-3 text-slate-900" type="password" name="password_confirmation" data-password-confirmation aria-describedby="passwordMatchHint" autocomplete="new-password" minlength="8" maxlength="64" required><span id="passwordMatchHint" class="account-password-match" role="status" hidden></span></label>
                        @endif

                        <div class="account-login-actions">
                        <button
                            type="submit"
                            id="loginButton"
                            class="group mt-3 flex h-11 items-center justify-center gap-2 rounded-xl bg-[#29bdb6] px-5 text-sm font-bold text-slate-950 shadow-[0_14px_34px_rgba(41,189,182,.28)] transition hover:-translate-y-0.5 hover:bg-[#38d2ca] active:translate-y-0"
                        >
                            <span id="loginButtonText">{{ ($passwordSetup ?? false) ? 'Guardar contraseña' : 'Iniciar sesión' }}</span>
                            <span id="loginSpinner" class="hidden h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                        </button>
                        @if(!($passwordSetup ?? false) && config('account-recovery.enabled'))
                            <button class="account-recovery-link" type="button" data-recovery-open>¿Olvidaste tu contraseña?</button>
                        @endif
                        </div>
                    </form>
                </div>
            </div>
        </section>
    </main>

    @if(!($passwordSetup ?? false) && config('account-recovery.enabled'))
        <dialog class="account-recovery-dialog" id="accountRecoveryDialog" aria-labelledby="recoveryTitle">
            <form method="POST" action="{{ route('account.recovery') }}">
                @csrf
                <header class="account-recovery-header">
                    <div class="account-review-heading">
                        <span class="account-review-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg></span>
                        <div><span class="eyebrow">Recuperación de contraseña</span><h2 id="recoveryTitle">Recuperar acceso</h2></div>
                    </div>
                    <button type="button" data-recovery-dismiss aria-label="Cerrar" title="Cerrar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
                </header>
                <label>Correo registrado<input type="email" name="email" autocomplete="email" maxlength="100" value="{{ old('email') }}" required></label>
                <label>Motivo de la solicitud<textarea name="reason" minlength="60" maxlength="150" rows="4" aria-describedby="recoveryReasonHint" data-recovery-reason required>{{ old('reason') }}</textarea></label>
                <small class="account-recovery-hint" id="recoveryReasonHint" role="status" hidden></small>
                <footer class="account-recovery-actions"><button type="button" data-recovery-close>Cancelar</button><button type="submit">Enviar solicitud</button></footer>
            </form>
        </dialog>
    @endif

    <script>
        const loginForm = document.getElementById('loginForm');
        const loginButton = document.getElementById('loginButton');
        const loginButtonText = document.getElementById('loginButtonText');
        const loginSpinner = document.getElementById('loginSpinner');
        const passwordInput = document.getElementById('password');
        const togglePassword = document.getElementById('togglePassword');

        togglePassword?.addEventListener('click', function () {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            togglePassword.setAttribute('aria-label', isHidden ? 'Ocultar contraseña' : 'Mostrar contraseña');
            togglePassword.classList.toggle('is-visible', isHidden);
            passwordInput.classList.add('password-pop');
            window.setTimeout(() => passwordInput.classList.remove('password-pop'), 180);
        });

        loginForm?.addEventListener('submit', function () {
            loginButton.disabled = true;
            loginButton.classList.add('cursor-wait', 'opacity-90');
            loginButtonText.textContent = passwordInput.hasAttribute('data-account-password') ? 'Guardando...' : 'Ingresando...';
            loginSpinner.classList.remove('hidden');
        });
    </script>
</body>
</html>
