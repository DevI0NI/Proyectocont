<?php
session_start();
header('Location: ../index.php');
exit;

require '../config/database.php';

$rol_id = (int)($_SESSION['rol_id'] ?? 0);
$sucursal_id = (int)$_SESSION['sucursal_activa'];
$username = $_SESSION['username'] ?? 'Usuario';

$sucursales = [];
if ($rol_id === 1) {
    $stmtSuc = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursales = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
}

$stmtNomSuc = $pdo->prepare('SELECT nombre FROM sucursales WHERE id = :id');
$stmtNomSuc->execute(['id' => $sucursal_id]);
$nombreSucursal = $stmtNomSuc->fetchColumn() ?: 'Desconocida';

$menuItems = [
    ['label' => 'Contenedores', 'href' => '../bloques/bloques.php', 'icon' => '../imagenes/bloques.png', 'active' => false],
    ['label' => 'Seguimiento PYP', 'href' => '../bloques/contenedor.php', 'icon' => '../imagenes/cont.png', 'active' => false],
    ['label' => 'Traslados', 'href' => '../traslados/traslados.php', 'icon' => '../imagenes/traslados.png', 'active' => false],
    ['label' => 'Despachos', 'href' => '../despachos/despachos.php', 'icon' => '../imagenes/egresos.png', 'active' => false],
    ['label' => 'Reportes', 'href' => '../reportes/reportes.php', 'icon' => '../imagenes/reportes.png', 'active' => false],
    ['label' => 'Parametros', 'href' => '../parametros/parametros.php', 'icon' => '../imagenes/parametros.png', 'active' => false],
];

$moduleCards = [
    [
        'title' => 'Espacios y Ocupacion',
        'desc' => 'Monitorea disponibilidad por area, ve espacios bloqueados y revisa el estado general del predio para camiones.',
        'href' => 'espacios.php',
        'icon' => '../imagenes/bodegas.png',
        'eyebrow' => 'Operativo',
        'status' => 'Disponible',
        'accent' => 'teal',
    ],
    [
        'title' => 'Nueva Renta',
        'desc' => 'Prepara el flujo de alta para registrar placa, conductor, plan de cobro y asignacion inicial del espacio.',
        'href' => '#',
        'icon' => '../imagenes/mas.png',
        'eyebrow' => 'Ingreso',
        'status' => 'Proximamente',
        'accent' => 'amber',
    ],
    [
        'title' => 'Rentas Activas',
        'desc' => 'Concentra las estadias en curso, el tiempo acumulado y el estado de liquidacion antes de autorizar la salida.',
        'href' => '#',
        'icon' => '../imagenes/Inventario.png',
        'eyebrow' => 'Seguimiento',
        'status' => 'Proximamente',
        'accent' => 'blue',
    ],
    [
        'title' => 'Liquidacion de Salida',
        'desc' => 'Visualiza el cierre operativo del vehiculo, calcula permanencia y prepara la liberacion del espacio ocupado.',
        'href' => '#',
        'icon' => '../imagenes/egresos.png',
        'eyebrow' => 'Cierre',
        'status' => 'Proximamente',
        'accent' => 'rose',
    ],
    [
        'title' => 'Historial de Rentas',
        'desc' => 'Consulta contratos cerrados, salidas liquidadas y movimientos historicos del predio con trazabilidad comercial.',
        'href' => '#',
        'icon' => '../imagenes/report.png',
        'eyebrow' => 'Historico',
        'status' => 'Proximamente',
        'accent' => 'slate',
    ],
    [
        'title' => 'Tarifas y Politicas',
        'desc' => 'Reserva para administrar esquemas por hora o por dia, con control por sede y edicion exclusiva de perfiles maestros.',
        'href' => '#',
        'icon' => '../imagenes/Save.png',
        'eyebrow' => 'Administrativo',
        'status' => ($rol_id === 1 || $rol_id === 2) ? 'Planeado' : 'Solo lectura',
        'accent' => 'mint',
    ],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Renta de Espacios - Proportsa</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/rentas/rentas.css">
    <script>
        (function () {
            const savedTheme = localStorage.getItem('proportsa-theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="../imagenes/IPSA.png" alt="Logo" class="sidebar-logo">
            <div class="sidebar-brand-copy">
                <span class="sidebar-kicker">Sistema Portuario</span>
                <span class="sidebar-name">Proportsa</span>
            </div>
            <button class="sidebar-close" id="sidebarClose" type="button" aria-label="Cerrar menu">&times;</button>
        </div>

        <div class="sidebar-mobile-tools">
            <div class="sidebar-mobile-user-menu" id="sidebarMobileUserMenu">
                <button class="sidebar-mobile-user-button" id="sidebarMobileUserTrigger" type="button" aria-haspopup="true" aria-expanded="false">
                    <span class="user-avatar sidebar-mobile-avatar" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M12 12a4.25 4.25 0 1 0-4.25-4.25A4.25 4.25 0 0 0 12 12Zm0 2c-4.21 0-7.5 2.19-7.5 5a.75.75 0 0 0 .75.75h13.5A.75.75 0 0 0 19.5 19c0-2.81-3.29-5-7.5-5Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <div class="sidebar-mobile-user-copy">
                        <small>Usuario</small>
                        <strong><?php echo htmlspecialchars($username); ?></strong>
                    </div>
                    <span class="sidebar-mobile-user-caret" aria-hidden="true">&#9662;</span>
                </button>
                <div class="sidebar-mobile-user-dropdown" id="sidebarMobileUserDropdown">
                    <a href="../logout.php" class="sidebar-mobile-user-logout">Cerrar sesion</a>
                </div>
            </div>

            <?php if ($rol_id === 1): ?>
                <form action="../cambiar_sucursal.php" method="POST" class="sidebar-mobile-sucursal-form">
                    <input type="hidden" name="sucursal_id" id="sidebar_mobile_sucursal_id" value="<?php echo (int)$sucursal_id; ?>">
                    <button type="button" class="sidebar-mobile-sucursal-trigger" id="sidebarMobileSucursalTrigger" aria-haspopup="listbox" aria-expanded="false">
                        <span class="sidebar-mobile-sucursal-copy">
                            <small>Sucursal</small>
                            <strong id="sidebarMobileSucursalValue"><?php echo htmlspecialchars($nombreSucursal); ?></strong>
                        </span>
                        <span class="sidebar-mobile-sucursal-caret" aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="sidebar-mobile-sucursal-menu" id="sidebarMobileSucursalMenu" role="listbox">
                        <?php foreach ($sucursales as $suc): ?>
                            <button type="button" class="sidebar-mobile-sucursal-option <?php echo ($sucursal_id == $suc['id']) ? 'active' : ''; ?>" data-value="<?php echo (int)$suc['id']; ?>" role="option" aria-selected="<?php echo ($sucursal_id == $suc['id']) ? 'true' : 'false'; ?>">
                                <?php echo htmlspecialchars($suc['nombre']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </form>
            <?php else: ?>
                <div class="sidebar-mobile-sucursal-static">
                    <small>Sucursal</small>
                    <strong><?php echo htmlspecialchars($nombreSucursal); ?></strong>
                </div>
            <?php endif; ?>

            <div class="sidebar-mobile-actions">
                <button class="theme-toggle sidebar-theme-toggle" id="sidebarThemeToggle" type="button" aria-label="Cambiar tema">
                    <span class="theme-icon theme-sun"></span>
                    <span class="theme-icon theme-moon"></span>
                </button>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="../index.php" class="nav-item">
                <span class="nav-icon dashboard-dot"></span>
                <span class="nav-label">Dashboard</span>
            </a>
            <?php foreach ($menuItems as $item): ?>
                <a href="<?php echo htmlspecialchars($item['href']); ?>" class="nav-item<?php echo $item['active'] ? ' active' : ''; ?>">
                    <img src="<?php echo htmlspecialchars($item['icon']); ?>" alt="<?php echo htmlspecialchars($item['label']); ?>" class="nav-image">
                    <span class="nav-label"><?php echo htmlspecialchars($item['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="page-wrap">
        <header class="topbar">
            <div class="topbar-left">
                <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Mostrar u ocultar menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>

            <div class="topbar-right">
                <div class="topbar-meta">
                    <?php if ($rol_id === 1): ?>
                        <form action="../cambiar_sucursal.php" method="POST" class="topbar-sucursal-form">
                            <span class="topbar-sucursal-label">Sucursal</span>
                            <div class="topbar-sucursal-dropdown" id="sucursalDropdown">
                                <input type="hidden" name="sucursal_id" id="sucursal_id" value="<?php echo (int)$sucursal_id; ?>">
                                <button type="button" class="topbar-sucursal-trigger" id="sucursalTrigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="topbar-sucursal-value" id="sucursalValue"><?php echo htmlspecialchars($nombreSucursal); ?></span>
                                    <span class="topbar-sucursal-caret" aria-hidden="true">&#9662;</span>
                                </button>
                                <div class="topbar-sucursal-menu" id="sucursalMenu" role="listbox">
                                    <?php foreach ($sucursales as $suc): ?>
                                        <button type="button" class="topbar-sucursal-option <?php echo ($sucursal_id == $suc['id']) ? 'active' : ''; ?>" data-value="<?php echo (int)$suc['id']; ?>" role="option" aria-selected="<?php echo ($sucursal_id == $suc['id']) ? 'true' : 'false'; ?>">
                                            <?php echo htmlspecialchars($suc['nombre']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="topbar-sucursal-pill">
                            <span class="pill-label">Sucursal</span>
                            <strong><?php echo htmlspecialchars($nombreSucursal); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>

                <button class="theme-toggle" id="themeToggle" type="button" aria-label="Cambiar tema">
                    <span class="theme-icon theme-sun"></span>
                    <span class="theme-icon theme-moon"></span>
                </button>

                <div class="user-menu" id="userMenu">
                    <button class="user-menu-trigger" id="userMenuTrigger" type="button" aria-haspopup="true" aria-expanded="false">
                        <span class="user-avatar" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                <path d="M12 12a4.25 4.25 0 1 0-4.25-4.25A4.25 4.25 0 0 0 12 12Zm0 2c-4.21 0-7.5 2.19-7.5 5a.75.75 0 0 0 .75.75h13.5A.75.75 0 0 0 19.5 19c0-2.81-3.29-5-7.5-5Z" fill="currentColor"/>
                            </svg>
                        </span>
                        <span class="user-menu-name"><?php echo htmlspecialchars($username); ?></span>
                        <span class="user-menu-caret" aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <div class="user-menu-header">
                            <span class="user-avatar user-avatar-large" aria-hidden="true">
                                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                    <path d="M12 12a4.25 4.25 0 1 0-4.25-4.25A4.25 4.25 0 0 0 12 12Zm0 2c-4.21 0-7.5 2.19-7.5 5a.75.75 0 0 0 .75.75h13.5A.75.75 0 0 0 19.5 19c0-2.81-3.29-5-7.5-5Z" fill="currentColor"/>
                                </svg>
                            </span>
                            <div class="user-menu-copy">
                                <strong><?php echo htmlspecialchars($username); ?></strong>
                                <small>Sesion activa</small>
                            </div>
                        </div>
                        <a href="../logout.php" class="user-menu-item user-menu-logout">Cerrar sesion</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="dashboard-main rentas-main">
            <section class="view-hero">
                <div>
                    <h1>Renta de Espacios para Camiones</h1>
                    <p>Centraliza ocupacion, ingreso, seguimiento, liquidacion e historial del predio comercial para transporte pesado.</p>
                </div>
            </section>

            <section class="panel-card rentas-panel">
                <div class="section-heading">
                    <h2>Centro del Modulo</h2>
                    <p>Estas son las vistas que estructuran el flujo comercial del predio. La de ocupacion ya queda enlazada y las demas preparan el resto del recorrido del modulo.</p>
                </div>

                <div class="rentas-grid">
                    <?php foreach ($moduleCards as $card): ?>
                        <?php $isReady = $card['href'] !== '#'; ?>
                        <a
                            href="<?php echo $isReady ? htmlspecialchars($card['href']) : '#'; ?>"
                            class="renta-card<?php echo $isReady ? '' : ' is-disabled'; ?>"
                            <?php echo $isReady ? '' : 'aria-disabled="true" onclick="return false;"'; ?>
                        >
                            <div class="renta-card-head">
                                <span class="renta-card-status"><?php echo htmlspecialchars($card['status']); ?></span>
                            </div>

                            <span class="renta-card-icon">
                                <img src="<?php echo htmlspecialchars($card['icon']); ?>" alt="<?php echo htmlspecialchars($card['title']); ?>">
                            </span>

                            <span class="renta-card-title"><?php echo htmlspecialchars($card['title']); ?></span>
                            <span class="renta-card-text"><?php echo htmlspecialchars($card['desc']); ?></span>

                            <span class="renta-card-link"><?php echo $isReady ? 'Abrir vista' : 'Vista siguiente'; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<script>
    (function () {
        const root = document.documentElement;
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const openBtn = document.getElementById('sidebarToggle');
        const closeBtn = document.getElementById('sidebarClose');
        const themeToggle = document.getElementById('themeToggle');
        const sidebarThemeToggle = document.getElementById('sidebarThemeToggle');
        const userMenu = document.getElementById('userMenu');
        const userTrigger = document.getElementById('userMenuTrigger');
        const userDropdown = document.getElementById('userMenuDropdown');
        const mobileUserTrigger = document.getElementById('sidebarMobileUserTrigger');
        const mobileUserDropdown = document.getElementById('sidebarMobileUserDropdown');
        const sucursalTrigger = document.getElementById('sucursalTrigger');
        const sucursalMenu = document.getElementById('sucursalMenu');
        const sucursalValue = document.getElementById('sucursalValue');
        const sucursalInput = document.getElementById('sucursal_id');
        const mobileSucursalTrigger = document.getElementById('sidebarMobileSucursalTrigger');
        const mobileSucursalMenu = document.getElementById('sidebarMobileSucursalMenu');
        const mobileSucursalValue = document.getElementById('sidebarMobileSucursalValue');
        const mobileSucursalInput = document.getElementById('sidebar_mobile_sucursal_id');

        function setSidebar(open) {
            if (!sidebar || !overlay) return;
            sidebar.classList.toggle('open', open);
            overlay.classList.toggle('visible', open);
            document.body.classList.toggle('sidebar-open', open);
        }

        function setTheme(nextTheme) {
            root.setAttribute('data-theme', nextTheme);
            localStorage.setItem('proportsa-theme', nextTheme);
        }

        function toggleTheme() {
            const current = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            setTheme(current === 'dark' ? 'light' : 'dark');
        }

        function toggleDropdown(trigger, dropdown, container, event) {
            if (!trigger || !dropdown || !container) return;
            event.preventDefault();
            const willOpen = !container.classList.contains('open');
            container.classList.toggle('open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        }

        openBtn?.addEventListener('click', function () { setSidebar(true); });
        closeBtn?.addEventListener('click', function () { setSidebar(false); });
        overlay?.addEventListener('click', function () { setSidebar(false); });
        themeToggle?.addEventListener('click', toggleTheme);
        sidebarThemeToggle?.addEventListener('click', toggleTheme);

        userTrigger?.addEventListener('click', function (event) {
            toggleDropdown(userTrigger, userDropdown, userMenu, event);
        });

        mobileUserTrigger?.addEventListener('click', function (event) {
            toggleDropdown(mobileUserTrigger, mobileUserDropdown, mobileUserTrigger.parentElement, event);
        });

        sucursalTrigger?.addEventListener('click', function (event) {
            const wrapper = sucursalTrigger.closest('.topbar-sucursal-dropdown');
            toggleDropdown(sucursalTrigger, sucursalMenu, wrapper, event);
        });

        mobileSucursalTrigger?.addEventListener('click', function (event) {
            const wrapper = mobileSucursalTrigger.closest('.sidebar-mobile-sucursal-form');
            toggleDropdown(mobileSucursalTrigger, mobileSucursalMenu, wrapper, event);
        });

        document.querySelectorAll('.topbar-sucursal-option').forEach(function (option) {
            option.addEventListener('click', function () {
                if (!sucursalInput || !sucursalValue) return;
                sucursalInput.value = option.dataset.value || '';
                sucursalValue.textContent = option.textContent.trim();
                option.closest('form')?.submit();
            });
        });

        document.querySelectorAll('.sidebar-mobile-sucursal-option').forEach(function (option) {
            option.addEventListener('click', function () {
                if (!mobileSucursalInput || !mobileSucursalValue) return;
                mobileSucursalInput.value = option.dataset.value || '';
                mobileSucursalValue.textContent = option.textContent.trim();
                option.closest('form')?.submit();
            });
        });

        document.addEventListener('click', function (event) {
            if (userMenu && !userMenu.contains(event.target)) {
                userMenu.classList.remove('open');
                userTrigger?.setAttribute('aria-expanded', 'false');
            }

            const mobileUserMenu = mobileUserTrigger?.parentElement || null;
            if (mobileUserMenu && !mobileUserMenu.contains(event.target)) {
                mobileUserMenu.classList.remove('open');
                mobileUserTrigger?.setAttribute('aria-expanded', 'false');
            }

            const sucursalDropdown = sucursalTrigger?.closest('.topbar-sucursal-dropdown') || null;
            if (sucursalDropdown && !sucursalDropdown.contains(event.target)) {
                sucursalDropdown.classList.remove('open');
                sucursalTrigger?.setAttribute('aria-expanded', 'false');
            }

            const mobileSucursalDropdown = mobileSucursalTrigger?.closest('.sidebar-mobile-sucursal-form') || null;
            if (mobileSucursalDropdown && !mobileSucursalDropdown.contains(event.target)) {
                mobileSucursalDropdown.classList.remove('open');
                mobileSucursalTrigger?.setAttribute('aria-expanded', 'false');
            }
        });
    })();
</script>
</body>
</html>
