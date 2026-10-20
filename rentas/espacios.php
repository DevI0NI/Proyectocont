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

$espacios = [
    ['codigo' => 'A-01', 'estado' => 'ocupado', 'cliente' => 'Transportes del Norte', 'placa' => 'C-784BDF'],
    ['codigo' => 'A-02', 'estado' => 'ocupado', 'cliente' => 'Fletes Caribe', 'placa' => 'P-554KLM'],
    ['codigo' => 'A-03', 'estado' => 'reservado', 'cliente' => 'Logistica Maya', 'placa' => 'Pendiente'],
    ['codigo' => 'A-04', 'estado' => 'disponible', 'cliente' => 'Disponible', 'placa' => '--'],
    ['codigo' => 'B-01', 'estado' => 'ocupado', 'cliente' => 'Carga Express', 'placa' => 'C-201QWE'],
    ['codigo' => 'B-02', 'estado' => 'mantenimiento', 'cliente' => 'Bloqueado', 'placa' => '--'],
    ['codigo' => 'B-03', 'estado' => 'disponible', 'cliente' => 'Disponible', 'placa' => '--'],
    ['codigo' => 'B-04', 'estado' => 'ocupado', 'cliente' => 'Atlántico Cargo', 'placa' => 'M-918ZXC'],
];

$rentasActivas = [
    ['contrato' => 'RE-0018', 'cliente' => 'Transportes del Norte', 'placa' => 'C-784BDF', 'espacio' => 'A-01', 'plan' => 'Mensual', 'desde' => '12/05/2026', 'hasta' => '12/06/2026', 'estado' => 'Activa'],
    ['contrato' => 'RE-0019', 'cliente' => 'Fletes Caribe', 'placa' => 'P-554KLM', 'espacio' => 'A-02', 'plan' => 'Semanal', 'desde' => '18/05/2026', 'hasta' => '25/05/2026', 'estado' => 'Activa'],
    ['contrato' => 'RE-0020', 'cliente' => 'Carga Express', 'placa' => 'C-201QWE', 'espacio' => 'B-01', 'plan' => 'Diaria', 'desde' => '20/05/2026', 'hasta' => '21/05/2026', 'estado' => 'Por vencer'],
    ['contrato' => 'RE-0021', 'cliente' => 'Atlántico Cargo', 'placa' => 'M-918ZXC', 'espacio' => 'B-04', 'plan' => 'Mensual', 'desde' => '03/05/2026', 'hasta' => '03/06/2026', 'estado' => 'Activa'],
];

$solicitudes = [
    ['titulo' => 'Solicitud pendiente', 'detalle' => 'Logistica Maya solicito 2 espacios para cabezales a partir del 22/05/2026.', 'estado' => 'Pendiente'],
    ['titulo' => 'Vencimiento cercano', 'detalle' => 'El contrato RE-0020 vence manana y requiere renovacion o liberacion del espacio.', 'estado' => 'Atencion'],
    ['titulo' => 'Observacion operativa', 'detalle' => 'El espacio B-02 permanece bloqueado por mantenimiento de piso y senalizacion.', 'estado' => 'Bloqueado'],
];

$menuItems = [
    ['label' => 'Contenedores', 'href' => '../bloques/bloques.php', 'icon' => '../imagenes/bloques.png', 'active' => false],
    ['label' => 'Seguimiento PYP', 'href' => '../bloques/contenedor.php', 'icon' => '../imagenes/cont.png', 'active' => false],
    ['label' => 'Traslados', 'href' => '../traslados/traslados.php', 'icon' => '../imagenes/traslados.png', 'active' => false],
    ['label' => 'Despachos', 'href' => '../despachos/despachos.php', 'icon' => '../imagenes/egresos.png', 'active' => false],
    ['label' => 'Reportes', 'href' => '../reportes/reportes.php', 'icon' => '../imagenes/reportes.png', 'active' => false],
    ['label' => 'Parametros', 'href' => '../parametros/parametros.php', 'icon' => '../imagenes/parametros.png', 'active' => false],
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
    <link rel="stylesheet" href="../styles/rentas/espacios.css">
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
            <section class="panel-card rentas-hero">
                <div class="hero-copy">
                    <span class="hero-kicker">Modulo Nuevo</span>
                    <h1>Renta de Espacios para Camiones</h1>
                    <p>Administra contratos de permanencia, controla disponibilidad operativa por patio y visualiza vencimientos sin mezclar este flujo con el inventario de contenedores.</p>
                </div>
                <div class="hero-aside">
                    <div class="hero-pill">Sucursal activa: <strong><?php echo htmlspecialchars($nombreSucursal); ?></strong></div>
                    <div class="hero-stats">
                        <article class="hero-stat">
                            <span>Espacios totales</span>
                            <strong>32</strong>
                        </article>
                        <article class="hero-stat">
                            <span>Ocupados</span>
                            <strong>18</strong>
                        </article>
                        <article class="hero-stat">
                            <span>Disponibles</span>
                            <strong>11</strong>
                        </article>
                        <article class="hero-stat">
                            <span>Bloqueados</span>
                            <strong>3</strong>
                        </article>
                    </div>
                </div>
            </section>

            <section class="rentas-grid">
                <section class="panel-card rent-form-card">
                    <div class="section-head">
                        <div>
                            <h2>Nueva Renta</h2>
                            <p>Vista inicial para registrar contratos de espacio, datos del camion y condiciones comerciales.</p>
                        </div>
                        <span class="section-tag">Solo vista</span>
                    </div>

                    <form class="rent-form" action="#" method="post" onsubmit="return false;">
                        <div class="form-block">
                            <span class="block-title">Cliente y unidad</span>
                            <div class="form-grid">
                                <label class="field">
                                    <span>Cliente / Empresa</span>
                                    <input type="text" placeholder="Ej. Transportes del Norte">
                                </label>
                                <label class="field">
                                    <span>Encargado</span>
                                    <input type="text" placeholder="Nombre del responsable">
                                </label>
                                <label class="field">
                                    <span>Placa del camion</span>
                                    <input type="text" placeholder="Ej. C-784BDF">
                                </label>
                                <label class="field">
                                    <span>Tipo de unidad</span>
                                    <select>
                                        <option>Cabezal</option>
                                        <option>Camion rigido</option>
                                        <option>Plataforma</option>
                                        <option>Furgon</option>
                                    </select>
                                </label>
                            </div>
                        </div>

                        <div class="form-block">
                            <span class="block-title">Asignacion operativa</span>
                            <div class="form-grid">
                                <label class="field">
                                    <span>Patio</span>
                                    <select>
                                        <option>Patio A</option>
                                        <option>Patio B</option>
                                        <option>Patio C</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Espacio</span>
                                    <select>
                                        <option>A-04</option>
                                        <option>B-03</option>
                                        <option>C-01</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Fecha de ingreso</span>
                                    <input type="date" value="2026-05-20">
                                </label>
                                <label class="field">
                                    <span>Fecha de salida estimada</span>
                                    <input type="date" value="2026-06-20">
                                </label>
                            </div>
                        </div>

                        <div class="form-block">
                            <span class="block-title">Condiciones del contrato</span>
                            <div class="form-grid">
                                <label class="field">
                                    <span>Plan de renta</span>
                                    <select>
                                        <option>Diaria</option>
                                        <option>Semanal</option>
                                        <option selected>Mensual</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Tarifa</span>
                                    <input type="text" placeholder="Q 0.00">
                                </label>
                                <label class="field field-full">
                                    <span>Observaciones</span>
                                    <textarea rows="4" placeholder="Notas de acceso, restricciones del patio o detalles del acuerdo."></textarea>
                                </label>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="toolbar-button toolbar-button-secondary">Guardar borrador</button>
                            <button type="button" class="toolbar-button toolbar-button-primary">Registrar renta</button>
                        </div>
                    </form>
                </section>

                <section class="panel-card occupancy-card">
                    <div class="section-head">
                        <div>
                            <h2>Mapa de Ocupacion</h2>
                            <p>Referencia visual de espacios para camiones dentro del patio de renta.</p>
                        </div>
                        <div class="legend">
                            <span class="legend-item"><i class="legend-dot disponible"></i>Disponible</span>
                            <span class="legend-item"><i class="legend-dot ocupado"></i>Ocupado</span>
                            <span class="legend-item"><i class="legend-dot reservado"></i>Reservado</span>
                            <span class="legend-item"><i class="legend-dot mantenimiento"></i>Bloqueado</span>
                        </div>
                    </div>

                    <div class="space-grid">
                        <?php foreach ($espacios as $espacio): ?>
                            <article class="space-card space-<?php echo $espacio['estado']; ?>">
                                <div class="space-top">
                                    <strong><?php echo htmlspecialchars($espacio['codigo']); ?></strong>
                                    <span class="space-state"><?php echo htmlspecialchars(ucfirst($espacio['estado'])); ?></span>
                                </div>
                                <p><?php echo htmlspecialchars($espacio['cliente']); ?></p>
                                <small><?php echo htmlspecialchars($espacio['placa']); ?></small>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </section>

            <section class="rentas-bottom">
                <section class="panel-card rents-table-card">
                    <div class="section-head">
                        <div>
                            <h2>Rentas Activas</h2>
                            <p>Tabla base para futuros filtros, renovaciones y cierre de contratos.</p>
                        </div>
                        <div class="head-actions">
                            <button type="button" class="toolbar-button toolbar-button-secondary">Filtrar vencimientos</button>
                            <button type="button" class="toolbar-button toolbar-button-secondary">Exportar</button>
                        </div>
                    </div>

                    <div class="table-wrap rents-table-wrap">
                        <table class="bloques-table rents-table">
                            <thead>
                                <tr>
                                    <th>Contrato</th>
                                    <th>Cliente</th>
                                    <th>Placa</th>
                                    <th>Espacio</th>
                                    <th>Plan</th>
                                    <th>Desde</th>
                                    <th>Hasta</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rentasActivas as $renta): ?>
                                    <tr>
                                        <td><span class="table-badge"><?php echo htmlspecialchars($renta['contrato']); ?></span></td>
                                        <td><span class="table-primary"><?php echo htmlspecialchars($renta['cliente']); ?></span></td>
                                        <td><span class="table-muted"><?php echo htmlspecialchars($renta['placa']); ?></span></td>
                                        <td><span class="table-muted"><?php echo htmlspecialchars($renta['espacio']); ?></span></td>
                                        <td><span class="table-muted"><?php echo htmlspecialchars($renta['plan']); ?></span></td>
                                        <td><span class="table-muted"><?php echo htmlspecialchars($renta['desde']); ?></span></td>
                                        <td><span class="table-muted"><?php echo htmlspecialchars($renta['hasta']); ?></span></td>
                                        <td><span class="state-pill <?php echo strtolower(str_replace(' ', '-', $renta['estado'])); ?>"><?php echo htmlspecialchars($renta['estado']); ?></span></td>
                                        <td>
                                            <div class="table-actions">
                                                <button type="button" class="action-pill action-pill-info">Detalle</button>
                                                <button type="button" class="action-pill action-pill-edit">Editar</button>
                                                <button type="button" class="action-pill action-pill-delete">Finalizar</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <aside class="panel-card side-notes-card">
                    <div class="section-head">
                        <div>
                            <h2>Seguimiento</h2>
                            <p>Panel auxiliar pensado para alertas comerciales y operativas.</p>
                        </div>
                    </div>

                    <div class="note-list">
                        <?php foreach ($solicitudes as $item): ?>
                            <article class="note-item">
                                <div class="note-top">
                                    <strong><?php echo htmlspecialchars($item['titulo']); ?></strong>
                                    <span class="mini-state <?php echo strtolower($item['estado']); ?>"><?php echo htmlspecialchars($item['estado']); ?></span>
                                </div>
                                <p><?php echo htmlspecialchars($item['detalle']); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </aside>
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
const sucursalValue = document.getElementById('sucursalValue');
const sucursalInput = document.getElementById('sucursal_id');
const sidebarMobileUserMenu = document.getElementById('sidebarMobileUserMenu');
const sidebarMobileUserTrigger = document.getElementById('sidebarMobileUserTrigger');
const sidebarMobileSucursalTrigger = document.getElementById('sidebarMobileSucursalTrigger');
const sidebarMobileSucursalMenu = document.getElementById('sidebarMobileSucursalMenu');
const sidebarMobileSucursalValue = document.getElementById('sidebarMobileSucursalValue');
const sidebarMobileSucursalInput = document.getElementById('sidebar_mobile_sucursal_id');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const sidebarClose = document.getElementById('sidebarClose');
const toggle = document.getElementById('sidebarToggle');
const mobileQuery = window.matchMedia('(max-width: 980px)');

function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('proportsa-theme', theme);
}

function toggleThemeMode() {
    const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
    applyTheme(currentTheme === 'dark' ? 'light' : 'dark');
}

function closeDesktopMenus() {
    if (userMenu) userMenu.classList.remove('open');
    if (userMenuTrigger) userMenuTrigger.setAttribute('aria-expanded', 'false');
    if (sucursalDropdown) sucursalDropdown.classList.remove('open');
    if (sucursalTrigger) sucursalTrigger.setAttribute('aria-expanded', 'false');
}

function closeSidebarMenus() {
    if (sidebarMobileUserMenu) sidebarMobileUserMenu.classList.remove('open');
    if (sidebarMobileUserTrigger) sidebarMobileUserTrigger.setAttribute('aria-expanded', 'false');
    if (sidebarMobileSucursalMenu) sidebarMobileSucursalMenu.classList.remove('open');
    if (sidebarMobileSucursalTrigger) sidebarMobileSucursalTrigger.setAttribute('aria-expanded', 'false');
}

function syncSidebarState() {
    if (mobileQuery.matches) {
        document.body.classList.add('sidebar-collapsed');
        document.body.classList.remove('sidebar-open');
    } else if (!document.body.classList.contains('sidebar-open')) {
        document.body.classList.remove('sidebar-collapsed');
    }
}

function setSidebarOpen(open) {
    if (mobileQuery.matches) {
        document.body.classList.toggle('sidebar-open', open);
        document.body.classList.toggle('sidebar-collapsed', !open);
    } else {
        document.body.classList.toggle('sidebar-collapsed', !open);
    }
}

function handleSidebarModeChange(event) {
    if (event.matches) {
        document.body.classList.add('sidebar-collapsed');
        document.body.classList.remove('sidebar-open');
    } else {
        document.body.classList.remove('sidebar-open');
        document.body.classList.remove('sidebar-collapsed');
    }
}

function updateSucursalSelection(value, label, optionsSelector) {
    if (sucursalInput) sucursalInput.value = value;
    if (sidebarMobileSucursalInput) sidebarMobileSucursalInput.value = value;
    if (sucursalValue) sucursalValue.textContent = label;
    if (sidebarMobileSucursalValue) sidebarMobileSucursalValue.textContent = label;
    document.querySelectorAll(optionsSelector).forEach((option) => {
        const isActive = option.dataset.value === String(value);
        option.classList.toggle('active', isActive);
        option.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
}

function submitSucursalForm(source) {
  const form = source.closest('form');
  if (form) form.submit();
}

themeToggle?.addEventListener('click', toggleThemeMode);
sidebarThemeToggle?.addEventListener('click', toggleThemeMode);

userMenuTrigger?.addEventListener('click', function (event) {
    event.stopPropagation();
    const open = !userMenu.classList.contains('open');
    closeDesktopMenus();
    if (open) {
        userMenu.classList.add('open');
        userMenuTrigger.setAttribute('aria-expanded', 'true');
    }
});

sucursalTrigger?.addEventListener('click', function (event) {
    event.stopPropagation();
    const open = !sucursalDropdown.classList.contains('open');
    closeDesktopMenus();
    if (open) {
        sucursalDropdown.classList.add('open');
        sucursalTrigger.setAttribute('aria-expanded', 'true');
    }
});

sidebarMobileUserTrigger?.addEventListener('click', function (event) {
    event.stopPropagation();
    const open = !sidebarMobileUserMenu.classList.contains('open');
    closeSidebarMenus();
    if (open) {
        sidebarMobileUserMenu.classList.add('open');
        sidebarMobileUserTrigger.setAttribute('aria-expanded', 'true');
    }
});

sidebarMobileSucursalTrigger?.addEventListener('click', function (event) {
    event.stopPropagation();
    const open = !sidebarMobileSucursalMenu.classList.contains('open');
    closeSidebarMenus();
    if (open) {
        sidebarMobileSucursalMenu.classList.add('open');
        sidebarMobileSucursalTrigger.setAttribute('aria-expanded', 'true');
    }
});

document.querySelectorAll('.topbar-sucursal-option').forEach((option) => {
    option.addEventListener('click', function () {
        updateSucursalSelection(option.dataset.value, option.textContent.trim(), '.topbar-sucursal-option, .sidebar-mobile-sucursal-option');
        submitSucursalForm(option);
    });
});

document.querySelectorAll('.sidebar-mobile-sucursal-option').forEach((option) => {
    option.addEventListener('click', function () {
        updateSucursalSelection(option.dataset.value, option.textContent.trim(), '.topbar-sucursal-option, .sidebar-mobile-sucursal-option');
        submitSucursalForm(option);
    });
});

document.addEventListener('click', function (event) {
    if (userMenu && !userMenu.contains(event.target)) {
        userMenu.classList.remove('open');
        userMenuTrigger?.setAttribute('aria-expanded', 'false');
    }
    if (sucursalDropdown && !sucursalDropdown.contains(event.target)) {
        sucursalDropdown.classList.remove('open');
        sucursalTrigger?.setAttribute('aria-expanded', 'false');
    }
    if (sidebarMobileUserMenu && !sidebarMobileUserMenu.contains(event.target)) {
        sidebarMobileUserMenu.classList.remove('open');
        sidebarMobileUserTrigger?.setAttribute('aria-expanded', 'false');
    }
    if (sidebarMobileSucursalTrigger && sidebarMobileSucursalMenu && !sidebarMobileSucursalTrigger.parentElement.contains(event.target)) {
        sidebarMobileSucursalMenu.classList.remove('open');
        sidebarMobileSucursalTrigger?.setAttribute('aria-expanded', 'false');
    }
});

toggle?.addEventListener('click', function () {
    if (mobileQuery.matches) {
        setSidebarOpen(!document.body.classList.contains('sidebar-open'));
    } else {
        document.body.classList.toggle('sidebar-collapsed');
    }
});

sidebarClose?.addEventListener('click', function () {
    setSidebarOpen(false);
});

sidebarOverlay?.addEventListener('click', function () {
    setSidebarOpen(false);
});

if (typeof mobileQuery.addEventListener === 'function') {
    mobileQuery.addEventListener('change', handleSidebarModeChange);
} else if (typeof mobileQuery.addListener === 'function') {
    mobileQuery.addListener(handleSidebarModeChange);
}
syncSidebarState();
</script>
</body>
</html>
