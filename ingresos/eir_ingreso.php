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

$rol_id = (int)($_SESSION['rol_id'] ?? 0);
$username = $_SESSION['username'] ?? 'Usuario';
$sucursal_id = (int)$_SESSION['sucursal_activa'];
$sucursales = [];
$nombreSucursalActiva = 'Sin asignar';

date_default_timezone_set('America/Guatemala');

if ($rol_id === 1) {
    $stmtSuc = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursales = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
}

$stmtNomSucursal = $pdo->prepare('SELECT nombre FROM sucursales WHERE id = :id');
$stmtNomSucursal->execute(['id' => $sucursal_id]);
$nombreSucursalActiva = $stmtNomSucursal->fetchColumn() ?: 'Desconocida';

$menuItems = [];
if (in_array($rol_id, [1, 2, 3])) {
    $menuItems[] = ['label' => 'Contenedores', 'href' => '../bloques/bloques.php', 'icon' => '../imagenes/bloques.png', 'active' => false];
}
$menuItems[] = ['label' => 'Seguimiento PYP', 'href' => '../bloques/contenedor.php', 'icon' => '../imagenes/cont.png', 'active' => false];
if (in_array($rol_id, [1, 2, 3])) {
    $menuItems[] = ['label' => 'Traslados', 'href' => '../traslados/traslados.php', 'icon' => '../imagenes/traslados.png', 'active' => false];
    $menuItems[] = ['label' => 'Despachos', 'href' => '../despachos/despachos.php', 'icon' => '../imagenes/egresos.png', 'active' => false];
}
if (in_array($rol_id, [1, 2])) {
    $menuItems[] = ['label' => 'Reportes', 'href' => '../reportes/reportes.php', 'icon' => '../imagenes/reportes.png', 'active' => false];
    $menuItems[] = ['label' => 'Parametros', 'href' => '../parametros/parametros.php', 'icon' => '../imagenes/parametros.png', 'active' => false];
}

$datosMock = [
    'fecha_operacion' => date('Y-m-d'),
    'hora_operacion' => date('H:i'),
    'numero_eir' => 'EIR-IN-' . date('Ymd') . '-014',
    'numero_equipo' => 'MMAU0000056',
    'tipo_unidad' => 'MAC',
    'modelo' => 'MAC2',
    'estado_unidad' => 'OK',
    'estado_box' => 'DMG',
    'naviera' => 'Mediterranean Shipping Company',
    'cliente' => 'Agencia Atlantico',
    'transportista' => 'Transportes del Norte, S.A.',
    'conductor' => 'Luis Fernando Barrios',
    'licencia' => 'C-3489201',
    'dpi' => '2651 44012 0101',
    'placa_cabezal' => 'C-812BDR',
    'placa_chasis' => 'TC-4419',
    'marchamo' => 'MSCU554812',
    'booking' => 'BOOK-78419',
    'referencia' => 'Entrega por gate norte / ingreso comercial',
    'patio' => 'Patio 3',
    'bloque' => 'Bloque 1',
    'posicion' => '23',
    'observaciones' => 'Constancia visual para recepcion de ingreso. Aqui se consolidan datos del transportista, naviera y responsable de entrega sin modificar el flujo base de registro.',
    'recibido_por' => $username,
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Constancia de Ingreso</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/ingresos.css/ingresos.css">
    <link rel="stylesheet" href="../styles/ingresos.css/eir_ingreso.css">
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

        <main class="dashboard-main ingreso-main">
            <section class="view-header">
                <div class="view-header-copy">
                    <h1>Constancia de Ingreso</h1>
                    <p>Completa los datos de transportista, naviera y recepcion para preparar la constancia operativa del equipo.</p>
                </div>
            </section>

            <section class="eir-helper-strip">
                <div class="eir-helper-copy">
                    <strong>Flujo sugerido</strong>
                    <span>Primero registras el contenedor en ingreso y despues completas esta constancia documental para emitir el ticket de recepcion.</span>
                </div>
                <a href="ticket_ingreso.php" class="toolbar-button toolbar-button-secondary">
                    <img src="../imagenes/report.png" alt="" aria-hidden="true" class="toolbar-icon">
                    <span>Ver Ticket</span>
                </a>
            </section>

            <div class="eir-layout">
                <section class="panel-card ingreso-form-panel">
                    <form class="ingreso-form eir-form" action="#" method="post">
                        <div class="form-group">
                            <label for="eir_fecha_operacion">Fecha de Operacion</label>
                            <div class="field-wrap">
                                <div class="date-shell">
                                    <input type="date" id="eir_fecha_operacion" name="eir_fecha_operacion" value="<?php echo htmlspecialchars($datosMock['fecha_operacion']); ?>">
                                    <span class="date-shell-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" focusable="false">
                                            <path d="M7 2.75a.75.75 0 0 1 .75.75V5h8.5V3.5a.75.75 0 0 1 1.5 0V5h.75A2.5 2.5 0 0 1 21 7.5v11A2.5 2.5 0 0 1 18.5 21h-13A2.5 2.5 0 0 1 3 18.5v-11A2.5 2.5 0 0 1 5.5 5h.75V3.5A.75.75 0 0 1 7 2.75ZM4.5 9v9.5c0 .552.448 1 1 1h13a1 1 0 0 0 1-1V9h-15Z" fill="currentColor"/>
                                        </svg>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_hora_operacion">Hora de Recepcion</label>
                            <div class="field-wrap">
                                <input type="time" id="eir_hora_operacion" name="eir_hora_operacion" value="<?php echo htmlspecialchars($datosMock['hora_operacion']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_numero">Numero de Constancia</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_numero" name="eir_numero" value="<?php echo htmlspecialchars($datosMock['numero_eir']); ?>" readonly>
                                <small class="field-note">Consecutivo referencial para la constancia de ingreso.</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_equipo">Numero de Equipo</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_equipo" name="eir_equipo" value="<?php echo htmlspecialchars($datosMock['numero_equipo']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_naviera">Naviera</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_naviera" name="eir_naviera" value="<?php echo htmlspecialchars($datosMock['naviera']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_cliente">Cliente / Agencia</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_cliente" name="eir_cliente" value="<?php echo htmlspecialchars($datosMock['cliente']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_transportista">Empresa Transportista</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_transportista" name="eir_transportista" value="<?php echo htmlspecialchars($datosMock['transportista']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_conductor">Conductor</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_conductor" name="eir_conductor" value="<?php echo htmlspecialchars($datosMock['conductor']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_licencia">Licencia</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_licencia" name="eir_licencia" value="<?php echo htmlspecialchars($datosMock['licencia']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_dpi">DPI</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_dpi" name="eir_dpi" value="<?php echo htmlspecialchars($datosMock['dpi']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_placa_cabezal">Placa Cabezal</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_placa_cabezal" name="eir_placa_cabezal" value="<?php echo htmlspecialchars($datosMock['placa_cabezal']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_placa_chasis">Placa Chasis</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_placa_chasis" name="eir_placa_chasis" value="<?php echo htmlspecialchars($datosMock['placa_chasis']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_marchamo">Marchamo</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_marchamo" name="eir_marchamo" value="<?php echo htmlspecialchars($datosMock['marchamo']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="eir_booking">Booking / Referencia</label>
                            <div class="field-wrap">
                                <input type="text" id="eir_booking" name="eir_booking" value="<?php echo htmlspecialchars($datosMock['booking']); ?>">
                            </div>
                        </div>

                        <div class="form-group form-group-textarea">
                            <label for="eir_referencia">Detalle Operativo</label>
                            <div class="field-wrap">
                                <textarea id="eir_referencia" name="eir_referencia"><?php echo htmlspecialchars($datosMock['referencia']); ?></textarea>
                            </div>
                        </div>

                        <div class="form-group form-group-textarea">
                            <label for="eir_observaciones">Observaciones</label>
                            <div class="field-wrap">
                                <textarea id="eir_observaciones" name="eir_observaciones"><?php echo htmlspecialchars($datosMock['observaciones']); ?></textarea>
                            </div>
                        </div>

                        <div class="buttons">
                            <a href="ingresos.php" class="toolbar-button toolbar-button-secondary ingreso-back-button">
                                <img src="../imagenes/regresar.png" alt="" aria-hidden="true" class="toolbar-icon">
                                <span>Volver a Ingreso</span>
                            </a>
                            <a href="ticket_ingreso.php" class="toolbar-button toolbar-button-secondary ingreso-doc-button">
                                <img src="../imagenes/report.png" alt="" aria-hidden="true" class="toolbar-icon">
                                <span>Vista Previa</span>
                            </a>
                            <button type="button" class="guardar-button">
                                <img src="../imagenes/Save.png" alt="Guardar">
                                <span>Guardar Borrador</span>
                            </button>
                        </div>
                    </form>
                </section>

                <aside class="panel-card eir-summary-card">
                    <div class="eir-summary-header">
                        <h2>Equipo Vinculado</h2>
                        <span class="eir-chip">Ingreso</span>
                    </div>
                    <div class="eir-summary-grid">
                        <article class="eir-summary-item">
                            <small>Numero de equipo</small>
                            <strong><?php echo htmlspecialchars($datosMock['numero_equipo']); ?></strong>
                        </article>
                        <article class="eir-summary-item">
                            <small>Tipo / Modelo</small>
                            <strong><?php echo htmlspecialchars($datosMock['tipo_unidad'] . ' / ' . $datosMock['modelo']); ?></strong>
                        </article>
                        <article class="eir-summary-item">
                            <small>Estado unidad</small>
                            <strong><?php echo htmlspecialchars($datosMock['estado_unidad']); ?></strong>
                        </article>
                        <article class="eir-summary-item">
                            <small>Estado box</small>
                            <strong><?php echo htmlspecialchars($datosMock['estado_box']); ?></strong>
                        </article>
                        <article class="eir-summary-item">
                            <small>Ubicacion sugerida</small>
                            <strong><?php echo htmlspecialchars($datosMock['patio'] . ' / ' . $datosMock['bloque'] . ' / Pos. ' . $datosMock['posicion']); ?></strong>
                        </article>
                        <article class="eir-summary-item">
                            <small>Recibido por</small>
                            <strong><?php echo htmlspecialchars($datosMock['recibido_por']); ?></strong>
                        </article>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</div>

<script>
const sidebar = document.getElementById('sidebar');
const toggle = document.getElementById('sidebarToggle');
const sidebarClose = document.getElementById('sidebarClose');
const sidebarOverlay = document.getElementById('sidebarOverlay');
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
const mobileQuery = window.matchMedia('(max-width: 980px)');

function syncSidebarMode() {
    if (mobileQuery.matches) {
        document.body.classList.add('sidebar-collapsed');
    } else {
        document.body.classList.remove('sidebar-collapsed');
        document.body.classList.remove('sidebar-open');
    }
}

function openSidebar() {
    document.body.classList.remove('sidebar-collapsed');
    document.body.classList.add('sidebar-open');
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
