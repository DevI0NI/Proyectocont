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

$sucursal_id = (int)($_SESSION['sucursal_activa'] ?? 0);
$rol_id = (int)($_SESSION['rol_id'] ?? 0);
$username = $_SESSION['username'] ?? 'Usuario';
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
if (in_array($rol_id, [1, 2, 3], true)) {
    $menuItems[] = ['label' => 'Contenedores', 'href' => '../bloques/bloques.php', 'icon' => '../imagenes/bloques.png', 'active' => false];
}
$menuItems[] = ['label' => 'Seguimiento PYP', 'href' => '../bloques/contenedor.php', 'icon' => '../imagenes/cont.png', 'active' => false];
if (in_array($rol_id, [1, 2, 3], true)) {
    $menuItems[] = ['label' => 'Traslados', 'href' => '../traslados/traslados.php', 'icon' => '../imagenes/traslados.png', 'active' => false];
    $menuItems[] = ['label' => 'Despachos', 'href' => '../despachos/despachos.php', 'icon' => '../imagenes/egresos.png', 'active' => false];
}
if (in_array($rol_id, [1, 2], true)) {
    $menuItems[] = ['label' => 'Reportes', 'href' => '../reportes/reportes.php', 'icon' => '../imagenes/reportes.png', 'active' => false];
    $menuItems[] = ['label' => 'Parametros', 'href' => '../parametros/parametros.php', 'icon' => '../imagenes/parametros.png', 'active' => false];
}

$orden = [
    'numero' => 'OT-20260605-018',
    'contenedor' => 'MMAU0000056',
    'tipo' => 'MAC',
    'modelo' => 'MAC2',
    'patio' => 'Patio 3',
    'bloque' => 'Bloque 1',
    'posicion' => '25',
    'fecha_apertura' => date('d/m/Y'),
    'hora_apertura' => date('H:i') . ' hrs',
    'prioridad' => 'Alta',
    'estado' => 'En proceso',
    'mecanico' => 'Carlos Mejia',
    'supervisor' => 'Ioni',
    'falla' => 'Unidad con alarma de temperatura y estructura con golpe lateral menor. Se requiere inspeccion de box, sellado y verificacion electrica.',
    'trabajo' => 'Cambio de arnes de conexion, ajuste de panel lateral, sellado de union y prueba de arranque en vacio.',
    'resultado' => 'Equipo listo para reinspeccion PYP. Se mantiene pendiente validacion final administrativa antes de liberar estado IPSA.',
];

$materiales = [
    ['codigo' => 'REP-014', 'descripcion' => 'Arnes electrico de conexion', 'cantidad' => 1, 'unidad' => 'pz', 'costo' => 245.00, 'subtotal' => 245.00],
    ['codigo' => 'INS-088', 'descripcion' => 'Sellador industrial', 'cantidad' => 2, 'unidad' => 'tub', 'costo' => 38.50, 'subtotal' => 77.00],
    ['codigo' => 'MAT-031', 'descripcion' => 'Remache estructural', 'cantidad' => 12, 'unidad' => 'und', 'costo' => 1.75, 'subtotal' => 21.00],
];

$manoObra = [
    ['etapa' => 'Diagnostico inicial', 'horas' => 1.50, 'tarifa' => 85.00, 'subtotal' => 127.50],
    ['etapa' => 'Reparacion mecanica', 'horas' => 2.00, 'tarifa' => 85.00, 'subtotal' => 170.00],
    ['etapa' => 'Pruebas y cierre tecnico', 'horas' => 1.00, 'tarifa' => 85.00, 'subtotal' => 85.00],
];

$totalMateriales = array_sum(array_column($materiales, 'subtotal'));
$totalManoObra = array_sum(array_column($manoObra, 'subtotal'));
$totalGeneral = $totalMateriales + $totalManoObra;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Orden de Mantenimiento</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/mantenimiento/ordenes.css">
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

        <main class="dashboard-main mantenimiento-main">
            <section class="view-header">
                <div class="view-header-copy">
                    <h1>Orden de Mantenimiento</h1>
                    <p>Controla el trabajo tecnico del mecanico, registra materiales, horas invertidas y el costo total de la reparacion del contenedor.</p>
                </div>
            </section>

            <div class="mantenimiento-layout">
                <section class="panel-card mantenimiento-panel">
                    <div class="section-heading">
                        <h2>Cabecera de trabajo</h2>
                        <p>Esta vista serviria para abrir, documentar y cerrar una orden tecnica sin mezclarla con el flujo operativo del patio.</p>
                    </div>

                    <div class="mantenimiento-header-grid">
                        <article class="header-chip">
                            <small>No. Orden</small>
                            <strong><?php echo htmlspecialchars($orden['numero']); ?></strong>
                        </article>
                        <article class="header-chip">
                            <small>Estado</small>
                            <strong><?php echo htmlspecialchars($orden['estado']); ?></strong>
                        </article>
                        <article class="header-chip">
                            <small>Prioridad</small>
                            <strong><?php echo htmlspecialchars($orden['prioridad']); ?></strong>
                        </article>
                        <article class="header-chip">
                            <small>Apertura</small>
                            <strong><?php echo htmlspecialchars($orden['fecha_apertura'] . ' | ' . $orden['hora_apertura']); ?></strong>
                        </article>
                    </div>

                    <div class="mantenimiento-form-grid">
                        <div class="form-block">
                            <label>Numero de equipo</label>
                            <input type="text" value="<?php echo htmlspecialchars($orden['contenedor']); ?>" readonly>
                        </div>
                        <div class="form-block">
                            <label>Tipo / Modelo</label>
                            <input type="text" value="<?php echo htmlspecialchars($orden['tipo'] . ' / ' . $orden['modelo']); ?>" readonly>
                        </div>
                        <div class="form-block">
                            <label>Mecanico responsable</label>
                            <input type="text" value="<?php echo htmlspecialchars($orden['mecanico']); ?>">
                        </div>
                        <div class="form-block">
                            <label>Supervisor</label>
                            <input type="text" value="<?php echo htmlspecialchars($orden['supervisor']); ?>">
                        </div>
                        <div class="form-block">
                            <label>Ubicacion</label>
                            <input type="text" value="<?php echo htmlspecialchars($orden['patio'] . ' / ' . $orden['bloque'] . ' / Pos. ' . $orden['posicion']); ?>" readonly>
                        </div>
                        <div class="form-block">
                            <label>Estado de la orden</label>
                            <input type="text" value="<?php echo htmlspecialchars($orden['estado']); ?>">
                        </div>
                        <div class="form-block form-block-span">
                            <label>Falla reportada</label>
                            <textarea rows="4"><?php echo htmlspecialchars($orden['falla']); ?></textarea>
                        </div>
                        <div class="form-block form-block-span">
                            <label>Trabajo realizado</label>
                            <textarea rows="4"><?php echo htmlspecialchars($orden['trabajo']); ?></textarea>
                        </div>
                        <div class="form-block form-block-span">
                            <label>Resultado tecnico / cierre</label>
                            <textarea rows="4"><?php echo htmlspecialchars($orden['resultado']); ?></textarea>
                        </div>
                    </div>
                </section>

                <aside class="mantenimiento-side">
                    <section class="panel-card mantenimiento-panel side-summary">
                        <div class="section-heading section-heading-compact">
                            <h2>Resumen de costos</h2>
                            <p>Vista rapida del impacto economico del trabajo mecanico.</p>
                        </div>

                        <div class="summary-cards">
                            <article class="summary-card">
                                <small>Materiales</small>
                                <strong>Q <?php echo number_format($totalMateriales, 2); ?></strong>
                            </article>
                            <article class="summary-card">
                                <small>Mano de obra</small>
                                <strong>Q <?php echo number_format($totalManoObra, 2); ?></strong>
                            </article>
                            <article class="summary-card highlight">
                                <small>Total general</small>
                                <strong>Q <?php echo number_format($totalGeneral, 2); ?></strong>
                            </article>
                        </div>
                    </section>

                    <section class="panel-card mantenimiento-panel side-summary">
                        <div class="section-heading section-heading-compact">
                            <h2>Estados finales</h2>
                            <p>Referencia visual del contenedor al cerrar la orden.</p>
                        </div>

                        <div class="summary-cards">
                            <article class="summary-card">
                                <small>Unidad</small>
                                <strong>OK</strong>
                            </article>
                            <article class="summary-card">
                                <small>Box</small>
                                <strong>Reparacion minima</strong>
                            </article>
                            <article class="summary-card">
                                <small>Aprobacion</small>
                                <strong>Pendiente PYP</strong>
                            </article>
                        </div>
                    </section>
                </aside>
            </div>

            <section class="panel-card mantenimiento-panel">
                <div class="section-heading">
                    <h2>Materiales utilizados</h2>
                    <p>Detalle visual del consumo de repuestos e insumos usados por el mecanico dentro de la reparacion.</p>
                </div>

                <div class="maintenance-table-wrap">
                    <table class="maintenance-table">
                        <thead>
                            <tr>
                                <th>Codigo</th>
                                <th>Descripcion</th>
                                <th>Cantidad</th>
                                <th>Unidad</th>
                                <th>Costo unitario</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materiales as $material): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($material['codigo']); ?></td>
                                    <td><?php echo htmlspecialchars($material['descripcion']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$material['cantidad']); ?></td>
                                    <td><?php echo htmlspecialchars($material['unidad']); ?></td>
                                    <td>Q <?php echo number_format($material['costo'], 2); ?></td>
                                    <td>Q <?php echo number_format($material['subtotal'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel-card mantenimiento-panel">
                <div class="section-heading">
                    <h2>Mano de obra</h2>
                    <p>Horas dedicadas por etapa, tarifa aplicada y subtotal de servicio tecnico.</p>
                </div>

                <div class="maintenance-table-wrap">
                    <table class="maintenance-table">
                        <thead>
                            <tr>
                                <th>Etapa</th>
                                <th>Horas</th>
                                <th>Tarifa / hora</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manoObra as $fila): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($fila['etapa']); ?></td>
                                    <td><?php echo number_format($fila['horas'], 2); ?></td>
                                    <td>Q <?php echo number_format($fila['tarifa'], 2); ?></td>
                                    <td>Q <?php echo number_format($fila['subtotal'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel-card mantenimiento-panel maintenance-actions">
                <a href="../bloques/contenedor.php" class="toolbar-button toolbar-button-secondary">
                    <img src="../imagenes/regresar.png" alt="" aria-hidden="true" class="toolbar-icon">
                    <span>Volver al seguimiento</span>
                </a>
                <button type="button" class="toolbar-button toolbar-button-secondary">
                    <img src="../imagenes/report.png" alt="" aria-hidden="true" class="toolbar-icon">
                    <span>Ver historial tecnico</span>
                </button>
                <button type="button" class="toolbar-button toolbar-button-primary">
                    <img src="../imagenes/Save.png" alt="" aria-hidden="true" class="toolbar-icon">
                    <span>Guardar orden</span>
                </button>
            </section>
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
