<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../index.php');
    exit;
}

require '../config/database.php';

$sucursal_id = (int)$_SESSION['sucursal_activa'];
$rol_id = (int)($_SESSION['rol_id'] ?? 0);
$username = $_SESSION['username'] ?? 'Usuario';

if (!in_array($rol_id, [1, 2, 3], true)) {
    header('Location: ../index.php');
    exit;
}

$sucursales = [];
$nombreSucursalActiva = 'Sin asignar';

if ($rol_id === 1) {
    $stmtSuc = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursales = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
}

$stmtNomSucursal = $pdo->prepare('SELECT nombre FROM sucursales WHERE id = :id');
$stmtNomSucursal->execute(['id' => $sucursal_id]);
$nombreSucursalActiva = $stmtNomSucursal->fetchColumn() ?: 'Desconocida';

$menuItems = [];
if (in_array($rol_id, [1, 2, 3], true)) {
    $menuItems[] = ['label' => 'Contenedores', 'href' => '../bloques/bloques.php', 'icon' => '../imagenes/bloques.png', 'active' => false];
}
$menuItems[] = ['label' => 'Seguimiento PYP', 'href' => '../bloques/contenedor.php', 'icon' => '../imagenes/cont.png', 'active' => false];
if (in_array($rol_id, [1, 2, 3], true)) {
    $menuItems[] = ['label' => 'Traslados', 'href' => '../traslados/traslados.php', 'icon' => '../imagenes/traslados.png', 'active' => false];
    $menuItems[] = ['label' => 'Despachos', 'href' => '../despachos/despachos.php', 'icon' => '../imagenes/egresos.png', 'active' => true];
}
if (in_array($rol_id, [1, 2], true)) {
    $menuItems[] = ['label' => 'Reportes', 'href' => '../reportes/reportes.php', 'icon' => '../imagenes/reportes.png', 'active' => false];
    $menuItems[] = ['label' => 'Parametros', 'href' => '../parametros/parametros.php', 'icon' => '../imagenes/parametros.png', 'active' => false];
}

$datosMock = [
    'fecha_operacion' => date('Y-m-d'),
    'hora_operacion' => date('H:i'),
    'numero_eir' => 'EIR-OUT-' . date('Ymd') . '-009',
    'numero_equipo' => 'PRUEBA34554',
    'tipo_unidad' => 'CS',
    'modelo' => 'CS1',
    'estado_unidad' => 'OK',
    'estado_box' => 'OK',
    'ipsa' => 'Disponible',
    'naviera' => 'Maersk Line',
    'cliente' => 'Logistica del Caribe',
    'transportista' => 'Fletes del Pacifico, S.A.',
    'conductor' => 'Jorge Leonel Ramirez',
    'licencia' => 'A-4482100',
    'dpi' => '2610 55120 0101',
    'placa_cabezal' => 'C-553HNK',
    'placa_chasis' => 'TC-9021',
    'booking' => 'OUT-54981',
    'referencia' => 'Retiro por despacho confirmado en gate central. Se documenta empresa transportista y responsable de recepcion final.',
    'patio' => 'Patio 1',
    'bloque' => 'Bloque 1',
    'posicion' => '23',
    'marchamo' => 'MAE993812',
    'autoriza' => $username,
    'observaciones' => 'Vista visual para completar constancia de salida luego del despacho confirmado. Aqui se dejarian datos finales del retiro y cadena de entrega.',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Constancia de Salida</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/despachos/despachos.css">
    <link rel="stylesheet" href="../styles/despachos/eir_salida.css">
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
                            <strong id="sidebarMobileSucursalValue"><?php echo htmlspecialchars($nombreSucursalActiva); ?></strong>
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
                    <strong><?php echo htmlspecialchars($nombreSucursalActiva); ?></strong>
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
                                    <span class="topbar-sucursal-value" id="sucursalValue"><?php echo htmlspecialchars($nombreSucursalActiva); ?></span>
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
                            <strong><?php echo htmlspecialchars($nombreSucursalActiva); ?></strong>
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

        <main class="dashboard-main despachos-main">
            <section class="view-hero">
                <div>
                    <h1>Constancia de Salida</h1>
                    <p>Completa la documentacion final del retiro una vez que el despacho operativo ya fue confirmado en <?php echo htmlspecialchars($nombreSucursalActiva); ?>.</p>
                </div>
            </section>

            <section class="panel-card despacho-helper-strip">
                <div class="despacho-helper-copy">
                    <strong>Uso recomendado</strong>
                    <span>Esta vista representa el paso posterior al despacho: aqui se capturan datos del transportista, conductor y entrega final para preparar el ticket de salida.</span>
                </div>
                <a href="ticket_salida.php" class="toolbar-button toolbar-button-secondary">
                    <span class="toolbar-icon" aria-hidden="true">
                        <img src="../imagenes/report.png" alt="">
                    </span>
                    <span>Ver Ticket</span>
                </a>
            </section>

            <div class="despacho-doc-layout">
                <section class="panel-card despacho-panel">
                    <form class="despacho-doc-form" action="#" method="post">
                        <div class="despacho-doc-grid">
                            <div class="form-block">
                                <label for="eir_fecha_salida">Fecha de salida</label>
                                <input type="date" id="eir_fecha_salida" value="<?php echo htmlspecialchars($datosMock['fecha_operacion']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_hora_salida">Hora de salida</label>
                                <input type="time" id="eir_hora_salida" value="<?php echo htmlspecialchars($datosMock['hora_operacion']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_numero_salida">Numero de constancia</label>
                                <input type="text" id="eir_numero_salida" value="<?php echo htmlspecialchars($datosMock['numero_eir']); ?>" readonly>
                            </div>
                            <div class="form-block">
                                <label for="eir_equipo_salida">Numero de equipo</label>
                                <input type="text" id="eir_equipo_salida" value="<?php echo htmlspecialchars($datosMock['numero_equipo']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_naviera_salida">Naviera</label>
                                <input type="text" id="eir_naviera_salida" value="<?php echo htmlspecialchars($datosMock['naviera']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_cliente_salida">Cliente / Agencia</label>
                                <input type="text" id="eir_cliente_salida" value="<?php echo htmlspecialchars($datosMock['cliente']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_transportista_salida">Empresa transportista</label>
                                <input type="text" id="eir_transportista_salida" value="<?php echo htmlspecialchars($datosMock['transportista']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_conductor_salida">Conductor</label>
                                <input type="text" id="eir_conductor_salida" value="<?php echo htmlspecialchars($datosMock['conductor']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_licencia_salida">Licencia</label>
                                <input type="text" id="eir_licencia_salida" value="<?php echo htmlspecialchars($datosMock['licencia']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_dpi_salida">DPI</label>
                                <input type="text" id="eir_dpi_salida" value="<?php echo htmlspecialchars($datosMock['dpi']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_placa_cabezal_salida">Placa cabezal</label>
                                <input type="text" id="eir_placa_cabezal_salida" value="<?php echo htmlspecialchars($datosMock['placa_cabezal']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_placa_chasis_salida">Placa chasis</label>
                                <input type="text" id="eir_placa_chasis_salida" value="<?php echo htmlspecialchars($datosMock['placa_chasis']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_marchamo_salida">Marchamo</label>
                                <input type="text" id="eir_marchamo_salida" value="<?php echo htmlspecialchars($datosMock['marchamo']); ?>">
                            </div>
                            <div class="form-block">
                                <label for="eir_booking_salida">Booking / Referencia</label>
                                <input type="text" id="eir_booking_salida" value="<?php echo htmlspecialchars($datosMock['booking']); ?>">
                            </div>
                            <div class="form-block form-block-span">
                                <label for="eir_referencia_salida">Detalle operativo</label>
                                <textarea id="eir_referencia_salida" rows="4"><?php echo htmlspecialchars($datosMock['referencia']); ?></textarea>
                            </div>
                            <div class="form-block form-block-span">
                                <label for="eir_observaciones_salida">Observaciones finales</label>
                                <textarea id="eir_observaciones_salida" rows="5"><?php echo htmlspecialchars($datosMock['observaciones']); ?></textarea>
                            </div>
                        </div>

                        <div class="despacho-doc-actions">
                            <a href="despachos.php" class="toolbar-button toolbar-button-secondary">
                                <span class="toolbar-icon" aria-hidden="true">
                                    <img src="../imagenes/regresar.png" alt="">
                                </span>
                                <span>Volver a Despachos</span>
                            </a>
                            <a href="ticket_salida.php" class="toolbar-button toolbar-button-secondary">
                                <span class="toolbar-icon" aria-hidden="true">
                                    <img src="../imagenes/report.png" alt="">
                                </span>
                                <span>Vista Previa</span>
                            </a>
                            <button type="button" class="toolbar-button toolbar-button-danger">
                                <span class="toolbar-icon" aria-hidden="true">
                                    <img src="../imagenes/Save.png" alt="">
                                </span>
                                <span>Guardar Borrador</span>
                            </button>
                        </div>
                    </form>
                </section>

                <aside class="panel-card despacho-doc-summary">
                    <div class="section-heading section-heading-compact">
                        <h2>Resumen del retiro</h2>
                        <p>Referencia visual del equipo, su ubicacion final y la autorizacion de salida.</p>
                    </div>
                    <div class="despacho-doc-summary-grid">
                        <article>
                            <small>Numero de equipo</small>
                            <strong><?php echo htmlspecialchars($datosMock['numero_equipo']); ?></strong>
                        </article>
                        <article>
                            <small>Tipo / Modelo</small>
                            <strong><?php echo htmlspecialchars($datosMock['tipo_unidad'] . ' / ' . $datosMock['modelo']); ?></strong>
                        </article>
                        <article>
                            <small>Ubicacion previa</small>
                            <strong><?php echo htmlspecialchars($datosMock['patio'] . ' / ' . $datosMock['bloque'] . ' / Pos. ' . $datosMock['posicion']); ?></strong>
                        </article>
                        <article>
                            <small>Estatus IPSA</small>
                            <strong><?php echo htmlspecialchars($datosMock['ipsa']); ?></strong>
                        </article>
                        <article>
                            <small>Estado unidad</small>
                            <strong><?php echo htmlspecialchars($datosMock['estado_unidad']); ?></strong>
                        </article>
                        <article>
                            <small>Estado box</small>
                            <strong><?php echo htmlspecialchars($datosMock['estado_box']); ?></strong>
                        </article>
                        <article>
                            <small>Autorizado por</small>
                            <strong><?php echo htmlspecialchars($datosMock['autoriza']); ?></strong>
                        </article>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</div>

<script>
const themeToggle = document.getElementById('themeToggle');
const sidebarThemeToggle = document.getElementById('sidebarThemeToggle');
const userMenu = document.getElementById('userMenu');
const userMenuTrigger = document.getElementById('userMenuTrigger');
const sucursalDropdown = document.getElementById('sucursalDropdown');
const sucursalTrigger = document.getElementById('sucursalTrigger');
const sucursalMenu = document.getElementById('sucursalMenu');
const sucursalValue = document.getElementById('sucursalValue');
const sucursalInput = document.getElementById('sucursal_id');
const sidebarMobileUserMenu = document.getElementById('sidebarMobileUserMenu');
const sidebarMobileUserTrigger = document.getElementById('sidebarMobileUserTrigger');
const sidebarMobileSucursalTrigger = document.getElementById('sidebarMobileSucursalTrigger');
const sidebarMobileSucursalMenu = document.getElementById('sidebarMobileSucursalMenu');
const sidebarMobileSucursalValue = document.getElementById('sidebarMobileSucursalValue');
const sidebarMobileSucursalInput = document.getElementById('sidebar_mobile_sucursal_id');
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const sidebarClose = document.getElementById('sidebarClose');
const toggle = document.getElementById('sidebarToggle');
const mobileQuery = window.matchMedia('(max-width: 960px)');

function openSidebar() {
    document.body.classList.add('sidebar-open');
    document.body.classList.remove('sidebar-collapsed');
}

function closeSidebar() {
    if (mobileQuery.matches) {
        document.body.classList.add('sidebar-collapsed');
        document.body.classList.remove('sidebar-open');
        if (sidebarMobileUserMenu && sidebarMobileUserTrigger) {
            sidebarMobileUserMenu.classList.remove('open');
            sidebarMobileUserTrigger.setAttribute('aria-expanded', 'false');
        }
        if (sidebarMobileSucursalMenu && sidebarMobileSucursalTrigger) {
            sidebarMobileSucursalMenu.classList.remove('open');
            sidebarMobileSucursalTrigger.setAttribute('aria-expanded', 'false');
        }
    } else {
        document.body.classList.toggle('sidebar-collapsed');
    }
}

function syncSidebarMode() {
    if (mobileQuery.matches) {
        document.body.classList.add('sidebar-collapsed');
        document.body.classList.remove('sidebar-open');
        sidebar.setAttribute('aria-hidden', document.body.classList.contains('sidebar-open') ? 'false' : 'true');
    } else {
        document.body.classList.remove('sidebar-open');
        document.body.classList.remove('sidebar-collapsed');
        sidebar.setAttribute('aria-hidden', 'false');
    }
}

toggle.addEventListener('click', function () {
    if (mobileQuery.matches) {
        if (document.body.classList.contains('sidebar-open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    } else {
        document.body.classList.toggle('sidebar-collapsed');
    }
});

if (sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
sidebarOverlay.addEventListener('click', closeSidebar);
mobileQuery.addEventListener('change', syncSidebarMode);
syncSidebarMode();

function syncThemeToggle() {
    const theme = document.documentElement.getAttribute('data-theme') || 'light';
    themeToggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    if (sidebarThemeToggle) {
        sidebarThemeToggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    }
}

function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
    const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', nextTheme);
    localStorage.setItem('proportsa-theme', nextTheme);
    syncThemeToggle();
}

themeToggle.addEventListener('click', toggleTheme);
if (sidebarThemeToggle) sidebarThemeToggle.addEventListener('click', toggleTheme);

userMenuTrigger.addEventListener('click', function (event) {
    event.stopPropagation();
    if (sucursalDropdown && sucursalTrigger) {
        sucursalDropdown.classList.remove('open');
        sucursalTrigger.setAttribute('aria-expanded', 'false');
    }
    const isOpen = userMenu.classList.toggle('open');
    userMenuTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
});

document.addEventListener('click', function (event) {
    if (!userMenu.contains(event.target)) {
        userMenu.classList.remove('open');
        userMenuTrigger.setAttribute('aria-expanded', 'false');
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        userMenu.classList.remove('open');
        userMenuTrigger.setAttribute('aria-expanded', 'false');
        if (sucursalDropdown && sucursalTrigger) {
            sucursalDropdown.classList.remove('open');
            sucursalTrigger.setAttribute('aria-expanded', 'false');
        }
    }
});

if (sucursalDropdown && sucursalTrigger && sucursalMenu && sucursalValue && sucursalInput) {
    const sucursalOptions = sucursalMenu.querySelectorAll('.topbar-sucursal-option');
    sucursalTrigger.addEventListener('click', function (event) {
        event.stopPropagation();
        userMenu.classList.remove('open');
        userMenuTrigger.setAttribute('aria-expanded', 'false');
        const isOpen = sucursalDropdown.classList.toggle('open');
        sucursalTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    sucursalOptions.forEach(function (option) {
        option.addEventListener('click', function () {
            sucursalInput.value = option.getAttribute('data-value');
            sucursalValue.textContent = option.textContent.trim();
            sucursalOptions.forEach(function (item) {
                item.classList.remove('active');
                item.setAttribute('aria-selected', 'false');
            });
            option.classList.add('active');
            option.setAttribute('aria-selected', 'true');
            sucursalDropdown.classList.remove('open');
            sucursalTrigger.setAttribute('aria-expanded', 'false');
            sucursalInput.form.submit();
        });
    });

    document.addEventListener('click', function (event) {
        if (!sucursalDropdown.contains(event.target)) {
            sucursalDropdown.classList.remove('open');
            sucursalTrigger.setAttribute('aria-expanded', 'false');
        }
    });
}

if (sidebarMobileUserMenu && sidebarMobileUserTrigger) {
    sidebarMobileUserTrigger.addEventListener('click', function (event) {
        event.stopPropagation();
        if (sidebarMobileSucursalMenu && sidebarMobileSucursalTrigger) {
            sidebarMobileSucursalMenu.classList.remove('open');
            sidebarMobileSucursalTrigger.setAttribute('aria-expanded', 'false');
        }
        const isOpen = sidebarMobileUserMenu.classList.toggle('open');
        sidebarMobileUserTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
        if (!sidebarMobileUserMenu.contains(event.target)) {
            sidebarMobileUserMenu.classList.remove('open');
            sidebarMobileUserTrigger.setAttribute('aria-expanded', 'false');
        }
    });
}

if (sidebarMobileSucursalTrigger && sidebarMobileSucursalMenu && sidebarMobileSucursalValue && sidebarMobileSucursalInput) {
    const sidebarMobileSucursalOptions = sidebarMobileSucursalMenu.querySelectorAll('.sidebar-mobile-sucursal-option');

    sidebarMobileSucursalTrigger.addEventListener('click', function (event) {
        event.stopPropagation();
        if (sidebarMobileUserMenu && sidebarMobileUserTrigger) {
            sidebarMobileUserMenu.classList.remove('open');
            sidebarMobileUserTrigger.setAttribute('aria-expanded', 'false');
        }
        const isOpen = sidebarMobileSucursalMenu.classList.toggle('open');
        sidebarMobileSucursalTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    sidebarMobileSucursalOptions.forEach(function (option) {
        option.addEventListener('click', function () {
            sidebarMobileSucursalInput.value = option.getAttribute('data-value');
            sidebarMobileSucursalValue.textContent = option.textContent.trim();
            sidebarMobileSucursalOptions.forEach(function (item) {
                item.classList.remove('active');
                item.setAttribute('aria-selected', 'false');
            });
            option.classList.add('active');
            option.setAttribute('aria-selected', 'true');
            sidebarMobileSucursalMenu.classList.remove('open');
            sidebarMobileSucursalTrigger.setAttribute('aria-expanded', 'false');
            sidebarMobileSucursalInput.form.submit();
        });
    });

    document.addEventListener('click', function (event) {
        if (!sidebarMobileSucursalTrigger.closest('.sidebar-mobile-sucursal-form').contains(event.target)) {
            sidebarMobileSucursalMenu.classList.remove('open');
            sidebarMobileSucursalTrigger.setAttribute('aria-expanded', 'false');
        }
    });
}

syncThemeToggle();
</script>
</body>
</html>
