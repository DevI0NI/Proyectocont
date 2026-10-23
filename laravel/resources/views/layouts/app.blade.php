<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <script>
        (function () {
            const theme = localStorage.getItem('proportsa-theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            const collapsed = localStorage.getItem('proportsa-sidebar-collapsed') === 'true';
            document.documentElement.classList.toggle('sidebar-prefers-collapsed', collapsed);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if(request()->routeIs('branches.index', 'users.index', 'unit-types.index', 'statuses.index', 'yard-layout.index', 'yard-layout.blocks') || (request()->routeIs('catalogs.index') && in_array(request()->route('catalog'), ['navieras', 'auditoras', 'iso'], true)))
        <script>
            if (performance.getEntriesByType('navigation')[0]?.type === 'reload' && location.search) {
                location.replace(@json(route(request()->route()->getName(), request()->routeIs('yard-layout.blocks') ? ['patio' => request()->route('patio')->id] : (request()->routeIs('catalogs.index') ? ['catalog' => request()->route('catalog')] : []), false)));
            }
        </script>
    @endif
    @livewireStyles
</head>
<body class="dashboard-body @if(request()->routeIs('rentals.*')) parking-body @endif">
    @php
        $user = auth()->user();
        $roleId = (int) $user->rol_id;
        $canOperate = in_array($roleId, [1, 2, 3], true);
        $canManage = in_array($roleId, [1, 2], true);
        $parameterLinks = [
            ['label' => 'Sucursales', 'route' => 'branches.index', 'active' => ['branches.*'], 'visible' => $roleId === 1],
            ['label' => 'Usuarios', 'route' => 'users.index', 'active' => ['users.*'], 'visible' => $canManage],
            ['label' => 'Tipos de unidad', 'route' => 'unit-types.index', 'active' => ['unit-types.*'], 'visible' => $canManage],
            ['label' => 'Estatus de contenedores', 'route' => 'statuses.index', 'active' => ['statuses.*'], 'visible' => $canManage],
            ['label' => 'Patios y bloques', 'route' => 'yard-layout.index', 'active' => ['yard-layout.*'], 'visible' => $canManage],
        ];
        $catalogLinks = [];
        foreach (\App\Services\OperationalCatalog::DEFINITIONS as $key => $definition) {
            if ($key === 'iso') {
                continue;
            }
            $catalogLinks[] = ['label' => $definition['title'], 'route' => 'catalogs.index', 'params' => ['catalog' => $key], 'catalog' => $key, 'visible' => $canManage];
        }

        $navItems = [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => ['dashboard'], 'icon' => null, 'visible' => !in_array($roleId, [4, 5], true)],
            ['label' => 'Dashboard', 'route' => 'external-audits.dashboard', 'active' => ['external-audits.dashboard'], 'icon' => null, 'visible' => $roleId === 5 && $user->external_access_level === 'supervisor'],
            ['label' => 'Mantenimiento', 'route' => 'maintenance.orders', 'active' => ['maintenance.*'], 'icon' => null, 'vectorIcon' => 'wrench', 'visible' => $roleId === 4],
            ['label' => 'Contenedores', 'route' => 'containers.index', 'active' => ['containers.*'], 'icon' => 'bloques.png', 'visible' => $canOperate],
            ['label' => 'Contenedores', 'route' => 'pyp-audits.index', 'active' => ['pyp-audits.*'], 'icon' => 'cont.png', 'visible' => $roleId === 5],
            ['label' => 'Traslados', 'route' => 'transfers.create', 'active' => ['transfers.*'], 'icon' => 'traslados.png', 'visible' => $canOperate],
            ['label' => 'Despachos', 'route' => 'dispatches.create', 'active' => ['dispatches.*'], 'icon' => 'egresos.png', 'visible' => $canOperate],
            ['label' => 'Reportes', 'route' => 'reports.index', 'active' => ['reports.*'], 'icon' => 'reportes.png', 'visible' => $canManage],
            ['label' => 'Notificaciones', 'route' => 'notifications.index', 'active' => ['notifications.*'], 'icon' => null, 'vectorIcon' => 'bell', 'visible' => true],
            ['label' => 'Parametros', 'route' => 'parameters.index', 'active' => ['parameters.*', 'branches.*', 'users.*', 'unit-types.*', 'statuses.*', 'yard-layout.*'], 'icon' => 'parametros.png', 'visible' => $canManage, 'group' => 'parameters', 'children' => $parameterLinks],
            ['label' => 'Catalogos', 'route' => 'catalogs.overview', 'active' => ['catalogs.*'], 'icon' => null, 'vectorIcon' => 'book-open', 'visible' => $canManage, 'group' => 'catalogs', 'children' => $catalogLinks],
            ['label' => 'Parqueo', 'route' => 'rentals.index', 'active' => ['rentals.*'], 'icon' => 'traslados.png', 'visible' => $roleId === 1],
        ];
        if (in_array($roleId, [1, 2, 3], true)) {
            foreach ($parameterLinks as &$link) {
                $permission = \App\Services\AccessPermissions::forRoute($link['route']);
                $link['visible'] = $permission ? $user->hasPermission($permission) : $roleId === 1;
            }
            unset($link);
            foreach ($catalogLinks as &$link) { $link['visible'] = $user->hasPermission($link['catalog'].'.view'); }
            unset($link);
            foreach ($navItems as &$item) {
                if (($item['group'] ?? '') === 'parameters') { $item['children'] = $parameterLinks; $item['visible'] = collect($parameterLinks)->contains('visible', true); }
                elseif (($item['group'] ?? '') === 'catalogs') { $item['children'] = $catalogLinks; $item['visible'] = collect($catalogLinks)->contains('visible', true); }
                elseif ($permission = \App\Services\AccessPermissions::forRoute($item['route'])) { $item['visible'] = $user->hasPermission($permission); }
            }
            unset($item);
        }
        if ($roleId !== 5) {
            foreach ($navItems as &$item) {
                if (in_array($item['route'], ['pyp-audits.index', 'external-audits.dashboard'], true)) { $item['visible'] = false; }
            }
            unset($item);
        }
    @endphp

    <div class="app-shell">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <img src="{{ asset('imagenes/IPSA.png') }}" alt="IPSA" class="sidebar-logo">
                <div class="sidebar-brand-copy">
                    <span class="sidebar-kicker">Sistema Portuario</span>
                    <span class="sidebar-name">Proportsa</span>
                </div>
                <button class="sidebar-close" id="sidebarClose" type="button" aria-label="Cerrar menu">x</button>
            </div>

            <nav class="sidebar-nav" aria-label="Principal">
                @foreach ($navItems as $item)
                    @continue(! $item['visible'])
                    @php
                        $itemActive = request()->routeIs(...$item['active']);
                    @endphp
                    @if(isset($item['children']))
                        <div class="nav-group" data-sidebar-group="{{ $item['group'] }}" data-current="{{ $itemActive ? 'true' : 'false' }}">
                        <button type="button" class="nav-item nav-group-trigger @if($itemActive) active @endif" aria-label="{{ $item['label'] }}" aria-expanded="{{ $itemActive ? 'true' : 'false' }}" aria-controls="sidebar-{{ $item['group'] }}">
                    @else
                        <a href="{{ route($item['route']) }}" class="nav-item @if($itemActive) active @endif">
                    @endif
                        @if (isset($item['vectorIcon']))
                            <span class="catalog-sidebar-icon" data-catalog-icon="{{ $item['vectorIcon'] }}" aria-hidden="true"></span>
                        @elseif ($item['icon'])
                            <img src="{{ asset('imagenes/'.$item['icon']) }}" alt="" class="nav-image">
                        @else
                            <span class="nav-icon dashboard-dot"></span>
                        @endif
                        <span class="nav-label">{{ $item['label'] }}</span>
                    @if(isset($item['children']))
                            <span class="nav-group-caret" data-catalog-icon="chevron-down" aria-hidden="true"></span>
                        </button>
                        <div class="nav-submenu" id="sidebar-{{ $item['group'] }}" @if(!$itemActive) hidden @endif>
                            <strong class="nav-submenu-title">{{ $item['label'] }}</strong>
                            @foreach($item['children'] as $child)
                                @continue(!$child['visible'])
                                @php
                                    $childActive = isset($child['catalog']) ? request()->routeIs('catalogs.*') && request()->route('catalog') === $child['catalog'] : request()->routeIs(...$child['active']);
                                @endphp
                                <a href="{{ route($child['route'], $child['params'] ?? []) }}" class="nav-subitem @if($childActive) active @endif" @if($childActive) aria-current="page" @endif>{{ $child['label'] }}</a>
                            @endforeach
                        </div>
                        </div>
                    @else
                        </a>
                    @endif
                @endforeach
            </nav>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <div class="page-wrap">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Mostrar u ocultar menu">
                        <span class="sidebar-toggle-line"></span>
                        <span class="sidebar-toggle-line"></span>
                        <span class="sidebar-toggle-line"></span>
                    </button>
                </div>

                <div class="topbar-right">
                    @if (request()->routeIs('rentals.*'))
                        <div class="topbar-sucursal-pill"><span>Operacion</span><strong>Parqueo</strong></div>
                    @elseif (in_array($roleId, [1, 5], true) && isset($sucursales))
                        <livewire:shell.branch-switcher />
                    @else
                        <div class="topbar-sucursal-pill">
                            <span>Sucursal</span>
                            <strong>{{ $sucursal->nombre ?? 'Sin sucursal' }}</strong>
                        </div>
                    @endif

                    <livewire:shell.notification-bell />
                    <div class="user-menu" id="userMenu">
                        <button class="user-menu-trigger" id="userMenuTrigger" type="button" aria-haspopup="true" aria-expanded="false">
                            <span class="user-avatar" aria-hidden="true">
                                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                    <path d="M12 12a4.25 4.25 0 1 0-4.25-4.25A4.25 4.25 0 0 0 12 12Zm0 2c-4.21 0-7.5 2.19-7.5 5a.75.75 0 0 0 .75.75h13.5A.75.75 0 0 0 19.5 19c0-2.81-3.29-5-7.5-5Z" fill="currentColor"/>
                                </svg>
                            </span>
                            <span class="user-menu-name">{{ $user->username }}</span>
                            <svg class="dropdown-caret" viewBox="0 0 20 20" aria-hidden="true">
                                <path d="M5.75 7.5 10 11.75 14.25 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>

                        <div class="user-menu-dropdown" id="userMenuDropdown">
                            <div class="user-menu-header">
                                <span class="user-avatar user-avatar-large" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                        <path d="M12 12a4.25 4.25 0 1 0-4.25-4.25A4.25 4.25 0 0 0 12 12Zm0 2c-4.21 0-7.5 2.19-7.5 5a.75.75 0 0 0 .75.75h13.5A.75.75 0 0 0 19.5 19c0-2.81-3.29-5-7.5-5Z" fill="currentColor"/>
                                    </svg>
                                </span>
                                <div>
                                    <strong>{{ $user->username }}</strong>
                                    <small>Sesion activa</small>
                                </div>
                            </div>

                            <button class="user-theme-toggle" id="themeToggle" type="button" aria-label="Cambiar tema" aria-pressed="false">
                                <span class="user-theme-icon" aria-hidden="true">
                                    <svg class="theme-sun" viewBox="0 0 24 24">
                                        <path d="M12 4.5V2.75M12 21.25V19.5M19.5 12h1.75M2.75 12H4.5M17.3 6.7l1.24-1.24M5.46 18.54 6.7 17.3M17.3 17.3l1.24 1.24M5.46 5.46 6.7 6.7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                        <circle cx="12" cy="12" r="3.75" fill="currentColor"/>
                                    </svg>
                                    <svg class="theme-moon" viewBox="0 0 24 24">
                                        <path d="M20.25 14.2A7.6 7.6 0 0 1 9.8 3.75a8.2 8.2 0 1 0 10.45 10.45Z" fill="currentColor"/>
                                    </svg>
                                </span>
                                <span class="user-theme-copy">
                                    <strong>Modo oscuro</strong>
                                    <small id="themeLabel">Desactivado</small>
                                </span>
                                <span class="user-theme-switch" aria-hidden="true">
                                    <span></span>
                                </span>
                            </button>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="logout-button danger">Cerrar sesion</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main class="main-panel @if(request()->routeIs('rentals.*')) parking-main @endif @if(request()->routeIs('branches.*', 'users.*', 'unit-types.*', 'statuses.*', 'yard-layout.*', 'catalogs.*')) administration-main @endif">
                @if (session('status'))
                    <div class="toast-notification" id="statusToast" role="status" aria-live="polite">
                        <div class="toast-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <path d="M5 12.4 9.2 16.6 19 6.8" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="toast-copy">
                            <strong>Listo</strong>
                            <span>{{ session('status') }}</span>
                        </div>
                        <div class="toast-progress" aria-hidden="true">
                            <svg viewBox="0 0 36 36">
                                <circle cx="18" cy="18" r="15.5" pathLength="100"></circle>
                            </svg>
                        </div>
                    </div>
                @endif

                @if(request()->routeIs('rentals.*') && $errors->any())
                    <div class="form-error page-error" role="alert">{{ $errors->first() }}</div>
                @endif
                @if(request()->routeIs('rentals.*'))
                    @php
                        $parkingMobileDevice = preg_match('/Android|iPhone|iPad|iPod|Mobile|Tablet/i', request()->userAgent() ?? '') === 1;
                    @endphp
                    <section class="parking-pc-required @if($parkingMobileDevice) parking-device-blocked @endif" role="status" aria-labelledby="parkingPcTitle">
                        <span data-parking-icon="monitor" aria-hidden="true"></span>
                        <h1 id="parkingPcTitle">Necesitas una computadora</h1>
                        <p>El modulo de parqueo se utiliza desde una PC en caseta. Para acceder, abre el sistema en una computadora.</p>
                        <a class="button secondary" href="{{ route('dashboard') }}">Volver al inicio</a>
                    </section>
                    @unless($parkingMobileDevice)
                        <div class="parking-desktop-content">@yield('content')</div>
                    @endunless
                @else
                    @yield('content')
                @endif
            </main>
        </div>
    </div>

    @livewireScripts
    <script>
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarClose = document.getElementById('sidebarClose');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const themeToggle = document.getElementById('themeToggle');
        let statusToast = document.getElementById('statusToast');
        const userMenu = document.getElementById('userMenu');
        const userMenuTrigger = document.getElementById('userMenuTrigger');
        const userMenuDropdown = document.getElementById('userMenuDropdown');
        const themeLabel = document.getElementById('themeLabel');
        const branchDropdown = document.getElementById('branchDropdown');
        const branchTrigger = document.getElementById('branchTrigger');
        const branchMenu = document.getElementById('branchMenu');
        const mobileQuery = window.matchMedia('(max-width: 980px)');
        const sidebarStorageKey = 'proportsa-sidebar-collapsed';

        function setSidebarCollapsed(collapsed, persist = true) {
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            document.documentElement.classList.toggle('sidebar-prefers-collapsed', collapsed);

            if (persist) {
                localStorage.setItem(sidebarStorageKey, collapsed ? 'true' : 'false');
            }
        }

        function syncSidebar() {
            document.body.classList.remove('sidebar-open');

            if (mobileQuery.matches) {
                setSidebarCollapsed(true, false);
                return;
            }

            setSidebarCollapsed(localStorage.getItem(sidebarStorageKey) === 'true', false);
        }

        function closeSidebar() {
            if (mobileQuery.matches) {
                setSidebarCollapsed(true, false);
                document.body.classList.remove('sidebar-open');
            } else {
                const collapsed = !document.body.classList.contains('sidebar-collapsed');
                setSidebarCollapsed(collapsed);
            }
        }

        sidebarToggle?.addEventListener('click', function () {
            if (mobileQuery.matches) {
                document.body.classList.toggle('sidebar-open');
                document.body.classList.toggle('sidebar-collapsed', !document.body.classList.contains('sidebar-open'));
                return;
            }

            const collapsed = !document.body.classList.contains('sidebar-collapsed');
            setSidebarCollapsed(collapsed);
        });

        sidebarClose?.addEventListener('click', closeSidebar);
        sidebarOverlay?.addEventListener('click', closeSidebar);
        mobileQuery.addEventListener('change', syncSidebar);
        syncSidebar();

        function closeHeaderMenus() {
            userMenu?.classList.remove('open');
            userMenuTrigger?.setAttribute('aria-expanded', 'false');
            document.querySelectorAll('#branchDropdown.open').forEach(function (dropdown) {
                dropdown.classList.remove('open');
            });
            document.querySelectorAll('#branchTrigger[aria-expanded="true"]').forEach(function (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            });
        }

        function syncThemeButton() {
            const theme = document.documentElement.getAttribute('data-theme') || 'light';
            themeToggle?.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
            if (themeLabel) {
                themeLabel.textContent = theme === 'dark' ? 'Activado' : 'Desactivado';
            }
        }

        themeToggle?.addEventListener('click', function (event) {
            event.stopPropagation();
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            const next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('proportsa-theme', next);
            syncThemeButton();
        });

        userMenuTrigger?.addEventListener('click', function (event) {
            event.stopPropagation();
            branchDropdown?.classList.remove('open');
            branchTrigger?.setAttribute('aria-expanded', 'false');
            const isOpen = userMenu.classList.toggle('open');
            userMenuTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        userMenuDropdown?.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        document.addEventListener('click', function (event) {
            const trigger = event.target.closest('#branchTrigger');

            if (trigger) {
                event.stopPropagation();
                event.stopImmediatePropagation();
                userMenu?.classList.remove('open');
                userMenuTrigger?.setAttribute('aria-expanded', 'false');
                const dropdown = trigger.closest('#branchDropdown');
                const isOpen = dropdown.classList.toggle('open');
                trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                return;
            }

            const option = event.target.closest('.topbar-dropdown-option[data-value]');

            if (! option || ! option.closest('#branchMenu')) {
                return;
            }

            const dropdown = option.closest('#branchDropdown');
            const branchValue = dropdown?.querySelector('#branchValue');
            branchValue && (branchValue.textContent = option.textContent.trim());

            option.closest('#branchMenu').querySelectorAll('.topbar-dropdown-option').forEach(function (item) {
                item.classList.remove('selected');
                item.setAttribute('aria-selected', 'false');
            });
            option.classList.add('selected');
            option.setAttribute('aria-selected', 'true');
            closeHeaderMenus();
        });

        document.addEventListener('click', closeHeaderMenus);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeHeaderMenus();
                if (mobileQuery.matches) {
                    closeSidebar();
                }
            }
        });
        syncThemeButton();

        if (statusToast) {
            window.setTimeout(function () {
                statusToast.classList.add('toast-leaving');
            }, 2700);

            window.setTimeout(function () {
                statusToast.remove();
            }, 3000);
        }
    </script>
</body>
</html>
