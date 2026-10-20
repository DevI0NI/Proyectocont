<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingreso | {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-white antialiased">
    <main class="relative min-h-dvh overflow-x-hidden">
        <section class="absolute inset-0 overflow-hidden bg-slate-950">
            @php
                $loginBackground = 'imagenes/imagen para el login.jpg';
                $loginBackgroundVersion = file_exists(public_path($loginBackground)) ? filemtime(public_path($loginBackground)) : time();
            @endphp
            <img
                src="{{ asset($loginBackground) }}?v={{ $loginBackgroundVersion }}"
                alt=""
                class="login-enter-background absolute inset-0 h-full w-full object-cover"
            >
            <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(2,6,23,.54),rgba(2,44,46,.28)_45%,rgba(2,6,23,.48))]"></div>
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(255,255,255,.08),transparent_28%),radial-gradient(circle_at_80%_80%,rgba(128,203,196,.10),transparent_30%)]"></div>
        </section>

        <section class="relative z-10 mx-auto grid min-h-dvh w-full max-w-[1120px] content-center gap-6 px-5 py-7 sm:px-8 lg:grid-cols-[minmax(390px,520px)_minmax(360px,420px)] lg:items-center lg:justify-center lg:gap-10 lg:px-10 xl:gap-14">
            <div class="login-enter-brand flex flex-col items-center justify-center text-center lg:items-start lg:text-left">
                <img src="{{ asset('imagenes/New logo IPSA.png') }}" alt="Logo IPSA Web" class="mb-5 h-16 w-28 object-contain drop-shadow-[0_16px_30px_rgba(0,0,0,.35)] sm:h-20 sm:w-36 lg:mb-9 lg:h-24 lg:w-40">
                <h1 class="max-w-[560px] text-4xl font-extrabold leading-[.98] tracking-normal text-white drop-shadow-lg sm:text-5xl lg:text-6xl xl:text-7xl">
                    Bienvenido<br>de nuevo
                </h1>
                <p class="mt-4 hidden max-w-[420px] text-sm leading-6 text-white/82 sm:text-base lg:mt-7 lg:block lg:max-w-[540px] lg:text-lg">
                    Accede al control operativo de patios, contenedores y movimientos de Proportsa.
                </p>
            </div>

            <div class="flex items-center justify-center">
                <div class="login-enter-form w-full max-w-[390px] rounded-2xl border border-white/15 bg-slate-950/24 p-5 shadow-[0_28px_80px_rgba(0,0,0,.32)] backdrop-blur-[2px] sm:max-w-[420px] sm:p-7 lg:p-9">
                    <div class="mb-5 sm:mb-7">
                        <h2 class="text-3xl font-extrabold tracking-normal text-white sm:text-4xl">Iniciar sesion</h2>
                    </div>

                    @if ($errors->any())
                        <div class="mb-5 border border-red-300/35 bg-red-500/20 px-4 py-3 text-sm font-semibold text-red-50 shadow-sm">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login.store') }}" id="loginForm" class="space-y-3.5 sm:space-y-4">
                        @csrf

                        <label class="block">
                            <span class="mb-1.5 block text-xs font-semibold text-white/90">Correo electronico</span>
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

                        <label class="block">
                            <span class="mb-1.5 block text-xs font-semibold text-white/90">Contrasena</span>
                            <span class="relative block">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    placeholder="Ingrese su contrasena"
                                    required
                                    class="h-11 w-full rounded-xl border border-white/25 bg-white px-3 pr-12 text-base text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[#80cbc4] focus:ring-4 focus:ring-[#80cbc4]/25 sm:text-[15px]"
                                >
                                <button
                                    type="button"
                                    id="togglePassword"
                                    class="password-visibility-toggle absolute right-1 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-[#00695c]"
                                    aria-label="Mostrar contrasena"
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

                        <button
                            type="submit"
                            id="loginButton"
                            class="group mt-3 flex h-11 items-center justify-center gap-2 rounded-xl bg-[#29bdb6] px-5 text-sm font-bold text-slate-950 shadow-[0_14px_34px_rgba(41,189,182,.28)] transition hover:-translate-y-0.5 hover:bg-[#38d2ca] active:translate-y-0"
                        >
                            <span id="loginButtonText">Iniciar sesion</span>
                            <span id="loginSpinner" class="hidden h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                        </button>
                    </form>
                </div>
            </div>
        </section>
    </main>

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
            togglePassword.setAttribute('aria-label', isHidden ? 'Ocultar contrasena' : 'Mostrar contrasena');
            togglePassword.classList.toggle('is-visible', isHidden);
            passwordInput.classList.add('password-pop');
            window.setTimeout(() => passwordInput.classList.remove('password-pop'), 180);
        });

        loginForm?.addEventListener('submit', function () {
            loginButton.disabled = true;
            loginButton.classList.add('cursor-wait', 'opacity-90');
            loginButtonText.textContent = 'Ingresando...';
            loginSpinner.classList.remove('hidden');
        });
    </script>
</body>
</html>
