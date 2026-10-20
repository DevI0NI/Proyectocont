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
$usuario_id = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Usuario';
$rol_id = (int)($_SESSION['rol_id'] ?? 0);

if (in_array($rol_id, [4, 5], true)) {
    header('Location: ../bloques/contenedor.php');
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

$stmtPatios = $pdo->prepare('SELECT id, nombre FROM patios WHERE estado_id = 1 AND sucursal_id = :sucursal ORDER BY nombre ASC');
$stmtPatios->execute(['sucursal' => $sucursal_id]);
$patios = $stmtPatios->fetchAll(PDO::FETCH_ASSOC);

$autoSelect = [
    'id' => 0,
    'nombre' => '',
    'patio_id' => 0,
    'patio_nombre' => '',
    'bloque_id' => 0,
    'bloque_numero' => '',
    'posicion' => ''
];

if (isset($_GET['nombre']) && trim($_GET['nombre']) !== '') {
    $stmtPre = $pdo->prepare('
        SELECT
            c.id,
            c.nombre,
            c.posicion,
            b.id AS bloque_id,
            b.numero AS bloque_numero,
            p.id AS patio_id,
            p.nombre AS patio_nombre
        FROM contenedores c
        JOIN bloques b ON c.bloque_id = b.id
        JOIN patios p ON b.patio_id = p.id
        WHERE c.nombre = :nombre
          AND c.estado_id = 1
          AND b.estado_id = 1
          AND p.estado_id = 1
          AND p.sucursal_id = :sucursal
        LIMIT 1
    ');
    $stmtPre->execute([
        'nombre' => trim($_GET['nombre']),
        'sucursal' => $sucursal_id
    ]);
    $pre = $stmtPre->fetch(PDO::FETCH_ASSOC);
    if ($pre) {
        $autoSelect = [
            'id' => (int)$pre['id'],
            'nombre' => $pre['nombre'],
            'patio_id' => (int)$pre['patio_id'],
            'patio_nombre' => $pre['patio_nombre'],
            'bloque_id' => (int)$pre['bloque_id'],
            'bloque_numero' => (string)$pre['bloque_numero'],
            'posicion' => (string)$pre['posicion']
        ];
    }
}

$menuItems = [];
if (in_array($rol_id, [1, 2, 3], true)) {
    $menuItems[] = ['label' => 'Contenedores', 'href' => '../bloques/bloques.php', 'icon' => '../imagenes/bloques.png', 'active' => false];
}
$menuItems[] = ['label' => 'Seguimiento PYP', 'href' => '../bloques/contenedor.php', 'icon' => '../imagenes/cont.png', 'active' => false];
if (in_array($rol_id, [1, 2, 3], true)) {
    $menuItems[] = ['label' => 'Traslados', 'href' => '../traslados/traslados.php', 'icon' => '../imagenes/traslados.png', 'active' => true];
    $menuItems[] = ['label' => 'Despachos', 'href' => '../despachos/despachos.php', 'icon' => '../imagenes/egresos.png', 'active' => false];
}
if (in_array($rol_id, [1, 2], true)) {
    $menuItems[] = ['label' => 'Reportes', 'href' => '../reportes/reportes.php', 'icon' => '../imagenes/reportes.png', 'active' => false];
    $menuItems[] = ['label' => 'Parametros', 'href' => '../parametros/parametros.php', 'icon' => '../imagenes/parametros.png', 'active' => false];
}
if (in_array($rol_id, [1, 2, 3, 4], true)) {
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Traslados - Proportsa</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/traslados/traslados.css">
    <script>
        (function () {
            const savedTheme = localStorage.getItem('proportsa-theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
</head>
<body>
<div id="toast-container" class="toast-container"></div>

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

        <main class="dashboard-main traslado-main">
            <section class="view-header">
                <div class="view-header-copy">
                    <h1>Traslados de Contenedores</h1>
                    <p>Selecciona un bloque de origen, identifica qué contenedores están libres y define una nueva ubicación dentro de <?php echo htmlspecialchars($nombreSucursalActiva); ?>.</p>
                </div>
            </section>

            <form action="procesar_traslado.php" method="post" id="trasladoForm" class="traslado-form">
                <input type="hidden" id="contenedor_id" name="contenedor_id" required>

                <section class="panel-card traslado-panel">
                    <div class="traslado-layout">
                        <div class="traslado-source">
                            <div class="section-heading">
                                <h2>Origen</h2>
                                <p>Navega por patio y bloque para ver los contenedores activos y saber si están listos para traslado.</p>
                            </div>

                            <div class="traslado-filters">
                                <div class="filter-group">
                                    <label for="origen_patio_id">Patio origen</label>
                                    <div class="select-shell">
                                        <select id="origen_patio_id" class="js-custom-select-source">
                                            <option value="">Seleccione un patio</option>
                                            <?php foreach ($patios as $patio): ?>
                                                <option value="<?php echo (int)$patio['id']; ?>" <?php echo ($autoSelect['patio_id'] === (int)$patio['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($patio['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="filter-group">
                                    <label for="origen_bloque_id">Bloque origen</label>
                                    <div class="select-shell">
                                        <select id="origen_bloque_id" class="js-custom-select-source" disabled>
                                            <option value="">Seleccione un bloque</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="filter-group filter-search">
                                    <label for="filtro_contenedor">Filtrar en el bloque</label>
                                    <input type="text" id="filtro_contenedor" placeholder="Escribe el codigo del contenedor">
                                </div>
                            </div>

                            <div class="source-summary" id="sourceSummary">
                                <span class="source-pill">Sin bloque seleccionado</span>
                                <span class="source-help">Los contenedores bloqueados se marcan cuando tienen otro equipo encima.</span>
                            </div>

                            <div class="container-list" id="contenedoresList">
                                <div class="empty-state">
                                    <div class="empty-state-icon" aria-hidden="true">↔</div>
                                    <h3>Selecciona un patio para comenzar</h3>
                                    <p>Luego elige un bloque para revisar qué contenedores pueden salir y cuáles están obstruidos.</p>
                                </div>
                            </div>
                        </div>

                        <aside class="traslado-side">
                            <section class="side-card selected-card">
                                <div class="section-heading section-heading-compact">
                                    <h2>Contenedor seleccionado</h2>
                                    <p>Elige un registro del bloque para preparar el traslado.</p>
                                </div>

                                <div id="selectedContainerState" class="selected-empty">
                                    <strong>Sin contenedor seleccionado</strong>
                                    <span>Cuando elijas uno, aquí verás su ubicación actual y si está libre para mover.</span>
                                </div>

                                <div id="selectedContainerDetails" class="selected-details" hidden>
                                    <div class="selected-row">
                                        <span>Contenedor</span>
                                        <strong id="selectedName">-</strong>
                                    </div>
                                    <div class="selected-row">
                                        <span>Ubicación actual</span>
                                        <strong id="selectedOrigin">-</strong>
                                    </div>
                                    <div class="selected-row">
                                        <span>Estado</span>
                                        <strong id="selectedTransferStatus">-</strong>
                                    </div>
                                    <p id="selectedTransferHint" class="selected-hint"></p>
                                </div>
                            </section>

                            <section class="side-card destination-card">
                                <div class="section-heading section-heading-compact">
                                    <h2>Nueva ubicación</h2>
                                    <p>Elige un destino y el sistema te sugerirá la mejor posición disponible.</p>
                                </div>

                                <div class="destination-fields">
                                    <div class="filter-group">
                                        <label for="nuevo_patio_id">Patio destino</label>
                                        <div class="select-shell">
                                            <select id="nuevo_patio_id" name="nuevo_patio_id" class="js-custom-select-source" required>
                                                <option value="">Seleccione un patio</option>
                                                <?php foreach ($patios as $patio): ?>
                                                    <option value="<?php echo (int)$patio['id']; ?>">
                                                        <?php echo htmlspecialchars($patio['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="filter-group">
                                        <label for="nuevo_bloque_id">Bloque destino</label>
                                        <div class="select-shell">
                                            <select id="nuevo_bloque_id" name="nuevo_bloque_id" class="js-custom-select-source" required disabled>
                                                <option value="">Seleccione un bloque</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="filter-group">
                                        <label for="nueva_posicion">Posición sugerida</label>
                                        <input type="text" id="nueva_posicion" name="nueva_posicion" placeholder="Posición" readonly required>
                                    </div>
                                </div>

                                <p class="destination-note" id="destinationNote">Selecciona un patio y un bloque destino para calcular la posición disponible.</p>
                            </section>

                            <div class="side-actions">
                                <button type="submit" class="toolbar-button toolbar-button-primary traslado-submit" id="trasladarButton" disabled>
                                    <img src="../imagenes/trasladar.png" alt="" class="toolbar-icon">
                                    <span>Trasladar contenedor</span>
                                </button>
                            </div>
                        </aside>
                    </div>
                </section>
            </form>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const autoSelect = <?php echo json_encode($autoSelect, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

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

    const origenPatioSelect = document.getElementById('origen_patio_id');
    const origenBloqueSelect = document.getElementById('origen_bloque_id');
    const filtroContenedor = document.getElementById('filtro_contenedor');
    const sourceSummary = document.getElementById('sourceSummary');
    const contenedoresList = document.getElementById('contenedoresList');
    const contenedorIdInput = document.getElementById('contenedor_id');
    const nuevoPatioSelect = document.getElementById('nuevo_patio_id');
    const nuevoBloqueSelect = document.getElementById('nuevo_bloque_id');
    const nuevaPosicionInput = document.getElementById('nueva_posicion');
    const destinationNote = document.getElementById('destinationNote');
    const trasladarButton = document.getElementById('trasladarButton');

    const selectedContainerState = document.getElementById('selectedContainerState');
    const selectedContainerDetails = document.getElementById('selectedContainerDetails');
    const selectedName = document.getElementById('selectedName');
    const selectedOrigin = document.getElementById('selectedOrigin');
    const selectedTransferStatus = document.getElementById('selectedTransferStatus');
    const selectedTransferHint = document.getElementById('selectedTransferHint');

    let blockContainers = [];
    let selectedContainer = null;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function syncSidebarMode() {
        if (mobileQuery.matches) {
            document.body.classList.add('sidebar-collapsed');
            document.body.classList.remove('sidebar-open');
            sidebar.setAttribute('aria-hidden', document.body.classList.contains('sidebar-open') ? 'false' : 'true');
        } else {
            document.body.classList.remove('sidebar-collapsed');
            document.body.classList.remove('sidebar-open');
            sidebar.setAttribute('aria-hidden', 'false');
        }
    }

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

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('proportsa-theme', theme);
    }

    function toggleTheme() {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        applyTheme(current === 'dark' ? 'light' : 'dark');
    }

    if (toggle) {
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
    }
    if (sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
    mobileQuery.addEventListener('change', syncSidebarMode);
    syncSidebarMode();

    if (themeToggle) themeToggle.addEventListener('click', toggleTheme);
    if (sidebarThemeToggle) sidebarThemeToggle.addEventListener('click', toggleTheme);

    if (userMenu && userMenuTrigger) {
        userMenuTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            const isOpen = userMenu.classList.toggle('open');
            userMenuTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        document.addEventListener('click', function (event) {
            if (!userMenu.contains(event.target)) {
                userMenu.classList.remove('open');
                userMenuTrigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    if (sucursalDropdown && sucursalTrigger && sucursalMenu && sucursalValue && sucursalInput) {
        const sucursalOptions = sucursalMenu.querySelectorAll('.topbar-sucursal-option');

        sucursalTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
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
            const isOpen = sidebarMobileUserMenu.classList.toggle('open');
            sidebarMobileUserTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            if (sidebarMobileSucursalMenu && sidebarMobileSucursalTrigger) {
                sidebarMobileSucursalMenu.classList.remove('open');
                sidebarMobileSucursalTrigger.setAttribute('aria-expanded', 'false');
            }
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
            const form = sidebarMobileSucursalTrigger.closest('.sidebar-mobile-sucursal-form');
            if (form && !form.contains(event.target)) {
                sidebarMobileSucursalMenu.classList.remove('open');
                sidebarMobileSucursalTrigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function closeAllCustomSelects(except) {
        document.querySelectorAll('.custom-select.open').forEach(function (dropdown) {
            if (dropdown !== except) {
                dropdown.classList.remove('open');
                const trigger = dropdown.querySelector('.custom-select-trigger');
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                }
            }
        });
    }

    function buildCustomSelect(select) {
        const wrapper = select.parentElement;
        if (!wrapper || wrapper.querySelector('.custom-select')) {
            return;
        }

        select.classList.add('is-enhanced');

        const dropdown = document.createElement('div');
        dropdown.className = 'custom-select';

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'custom-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');

        const value = document.createElement('span');
        value.className = 'custom-select-value';

        const caret = document.createElement('span');
        caret.className = 'custom-select-caret';
        caret.setAttribute('aria-hidden', 'true');

        trigger.appendChild(value);
        trigger.appendChild(caret);

        const menu = document.createElement('div');
        menu.className = 'custom-select-menu';
        menu.setAttribute('role', 'listbox');

        dropdown.appendChild(trigger);
        dropdown.appendChild(menu);
        wrapper.appendChild(dropdown);

        function refresh() {
            const options = Array.from(select.options);
            const current = options.find(function (option) { return option.selected; }) || options[0];
            value.textContent = current ? current.textContent.trim() : 'Selecciona una opcion';
            menu.innerHTML = '';

            options.forEach(function (option) {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (option.selected ? ' active' : '');
                item.textContent = option.textContent.trim();
                item.disabled = option.disabled;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
                item.addEventListener('click', function () {
                    if (option.disabled) return;
                    select.value = option.value;
                    options.forEach(function (opt) { opt.selected = false; });
                    option.selected = true;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    refresh();
                    dropdown.classList.remove('open');
                    trigger.setAttribute('aria-expanded', 'false');
                });
                menu.appendChild(item);
            });

            if (select.disabled) {
                trigger.setAttribute('disabled', 'disabled');
                dropdown.classList.add('is-disabled');
            } else {
                trigger.removeAttribute('disabled');
                dropdown.classList.remove('is-disabled');
            }
        }

        trigger.addEventListener('click', function (event) {
            if (select.disabled) return;
            event.stopPropagation();
            const willOpen = !dropdown.classList.contains('open');
            closeAllCustomSelects(dropdown);
            dropdown.classList.toggle('open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });

        select.addEventListener('change', refresh);
        select._refreshCustomSelect = refresh;
        refresh();
    }

    document.querySelectorAll('.js-custom-select-source').forEach(buildCustomSelect);

    document.addEventListener('click', function () {
        closeAllCustomSelects();
    });

    function showToast(message, type) {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = 'toast ' + (type || 'success');
        toast.textContent = message;
        container.appendChild(toast);

        requestAnimationFrame(function () {
            toast.classList.add('show');
        });

        setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () {
                toast.remove();
            }, 280);
        }, 3600);
    }

    function setSelectOptions(select, options, placeholder) {
        select.innerHTML = '';

        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = placeholder;
        select.appendChild(placeholderOption);

        options.forEach(function (optionData) {
            const option = document.createElement('option');
            option.value = optionData.value;
            option.textContent = optionData.label;
            select.appendChild(option);
        });

        select.value = '';
        if (select._refreshCustomSelect) {
            select._refreshCustomSelect();
        }
    }

    function updateSubmitState() {
        const hasContainer = !!contenedorIdInput.value;
        const hasDestination = !!nuevoBloqueSelect.value && !!nuevaPosicionInput.value;
        const selectedMovable = selectedContainer && selectedContainer.puede_trasladar;
        trasladarButton.disabled = !(hasContainer && hasDestination && selectedMovable);
    }

    function resetSelectedContainer(messageTitle, messageText) {
        selectedContainer = null;
        contenedorIdInput.value = '';
        selectedContainerDetails.hidden = true;
        selectedContainerState.hidden = false;
        selectedContainerState.innerHTML = '<strong>' + escapeHtml(messageTitle || 'Sin contenedor seleccionado') + '</strong><span>' + escapeHtml(messageText || 'Cuando elijas uno, aquí verás su ubicación actual y si está libre para mover.') + '</span>';
        updateSubmitState();
    }

    function renderSelectedContainer() {
        if (!selectedContainer) {
            resetSelectedContainer();
            return;
        }

        selectedName.textContent = selectedContainer.nombre;
        selectedOrigin.textContent = selectedContainer.patio_nombre + ' / Bloque ' + selectedContainer.bloque_numero + ' / Pos. ' + selectedContainer.posicion;
        selectedTransferStatus.textContent = selectedContainer.puede_trasladar ? 'Listo para traslado' : 'Bloqueado';
        selectedTransferHint.textContent = selectedContainer.puede_trasladar
            ? 'El contenedor está libre y puede moverse a una nueva ubicación.'
            : selectedContainer.motivo_bloqueo;

        selectedTransferStatus.className = selectedContainer.puede_trasladar ? 'transfer-badge transfer-ok' : 'transfer-badge transfer-blocked';
        selectedTransferHint.className = selectedContainer.puede_trasladar ? 'selected-hint selected-hint-ok' : 'selected-hint selected-hint-blocked';

        selectedContainerState.hidden = true;
        selectedContainerDetails.hidden = false;
        updateSubmitState();
    }

    function renderSourceSummary(meta) {
        if (!meta) {
            sourceSummary.innerHTML = '<span class="source-pill">Sin bloque seleccionado</span><span class="source-help">Los contenedores bloqueados se marcan cuando tienen otro equipo encima.</span>';
            return;
        }

        sourceSummary.innerHTML = ''
            + '<span class="source-pill">' + escapeHtml(meta.patio_nombre) + '</span>'
            + '<span class="source-pill">Bloque ' + escapeHtml(meta.bloque_numero) + '</span>'
            + '<span class="source-pill">' + escapeHtml(String(meta.total)) + ' activos</span>'
            + '<span class="source-pill source-pill-ok">' + escapeHtml(String(meta.trasladables)) + ' listos</span>'
            + '<span class="source-help">Selecciona un contenedor de esta lista para preparar el movimiento.</span>';
    }

    function renderContainers() {
        const filter = (filtroContenedor.value || '').trim().toLowerCase();
        const filtered = blockContainers.filter(function (item) {
            return !filter || item.nombre.toLowerCase().includes(filter);
        });

        if (!filtered.length) {
            contenedoresList.innerHTML = '<div class="empty-state small"><h3>Sin coincidencias</h3><p>No hay contenedores que coincidan con ese filtro dentro del bloque seleccionado.</p></div>';
            return;
        }

        contenedoresList.innerHTML = filtered.map(function (item) {
            const selectedClass = selectedContainer && selectedContainer.id === item.id ? ' is-selected' : '';
            const transferClass = item.puede_trasladar ? 'status-ready' : 'status-locked';
            const actionClass = item.puede_trasladar ? 'action-select' : 'action-review';
            const actionLabel = item.puede_trasladar ? 'Seleccionar' : 'Ver estado';
            const helper = item.puede_trasladar ? 'Libre para traslado' : item.motivo_bloqueo;

            return ''
                + '<article class="container-item' + selectedClass + '" data-id="' + item.id + '">'
                + '  <div class="container-main">'
                + '    <div class="container-title-row">'
                + '      <div class="container-title-group">'
                + '        <strong class="container-name">' + escapeHtml(item.nombre) + '</strong>'
                + '        <span class="container-position">Pos. ' + escapeHtml(item.posicion) + '</span>'
                + '      </div>'
                + '      <span class="transfer-badge ' + transferClass + '">' + (item.puede_trasladar ? 'Listo' : 'Bloqueado') + '</span>'
                + '    </div>'
                + '    <div class="container-badges">'
                + '      <span class="data-badge">' + escapeHtml(item.estatus_unidad || 'Sin estado unidad') + '</span>'
                + '      <span class="data-badge">' + escapeHtml(item.estatus_box || 'Sin status box') + '</span>'
                + '      <span class="data-badge">' + escapeHtml(item.estatus_ipsa || 'Sin estatus IPSA') + '</span>'
                + '    </div>'
                + '    <p class="container-helper">' + escapeHtml(helper) + '</p>'
                + '  </div>'
                + '  <div class="container-actions">'
                + '    <button type="button" class="list-action ' + actionClass + '" data-container-id="' + item.id + '">' + actionLabel + '</button>'
                + '  </div>'
                + '</article>';
        }).join('');

        contenedoresList.querySelectorAll('.list-action').forEach(function (button) {
            button.addEventListener('click', function () {
                const id = parseInt(button.getAttribute('data-container-id'), 10);
                const target = blockContainers.find(function (item) { return item.id === id; });
                if (!target) return;
                selectedContainer = target;
                contenedorIdInput.value = String(target.id);
                renderSelectedContainer();
                renderContainers();
            });
        });
    }

    function loadOriginBlocks(patioId, selectedBlockId) {
        setSelectOptions(origenBloqueSelect, [], 'Seleccione un bloque');
        origenBloqueSelect.disabled = true;
        if (origenBloqueSelect._refreshCustomSelect) origenBloqueSelect._refreshCustomSelect();
        renderSourceSummary(null);
        blockContainers = [];
        contenedoresList.innerHTML = '<div class="empty-state small"><h3>Selecciona un bloque</h3><p>Elige un bloque de origen para ver los contenedores activos y su disponibilidad real para traslado.</p></div>';
        resetSelectedContainer('Sin contenedor seleccionado', 'Cuando elijas uno, aquí verás su ubicación actual y si está libre para mover.');

        if (!patioId) {
            return Promise.resolve();
        }

        return fetch('obtener_bloques_disponibles.php?patio_id=' + encodeURIComponent(patioId))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!Array.isArray(data)) {
                    throw new Error(data.error || 'No se pudieron cargar los bloques de origen.');
                }

                if (!data.length) {
                    setSelectOptions(origenBloqueSelect, [], 'Sin bloques disponibles');
                    origenBloqueSelect.disabled = true;
                    if (origenBloqueSelect._refreshCustomSelect) origenBloqueSelect._refreshCustomSelect();
                    contenedoresList.innerHTML = '<div class="empty-state small"><h3>Sin bloques disponibles</h3><p>Este patio no tiene bloques activos disponibles para traslado.</p></div>';
                    return;
                }

                const options = data.map(function (bloque) {
                    return {
                        value: String(bloque.id),
                        label: 'Bloque ' + bloque.numero + ' · ' + bloque.capacidad_actual + '/' + bloque.capacidad_maxima
                    };
                });

                origenBloqueSelect.disabled = false;
                setSelectOptions(origenBloqueSelect, options, 'Seleccione un bloque');
                if (origenBloqueSelect._refreshCustomSelect) origenBloqueSelect._refreshCustomSelect();

                if (selectedBlockId) {
                    origenBloqueSelect.value = String(selectedBlockId);
                    if (origenBloqueSelect._refreshCustomSelect) origenBloqueSelect._refreshCustomSelect();
                    return loadContainersForBlock(selectedBlockId, autoSelect.id);
                }
            })
            .catch(function (error) {
                console.error(error);
                showToast(error.message || 'Error al cargar bloques de origen.', 'error');
            });
    }

    function loadContainersForBlock(bloqueId, preferredContainerId) {
        blockContainers = [];
        contenedoresList.innerHTML = '<div class="empty-state small"><h3>Cargando contenedores</h3><p>Espera un momento mientras consultamos el inventario del bloque.</p></div>';
        renderSourceSummary(null);
        resetSelectedContainer('Sin contenedor seleccionado', 'Cuando elijas uno, aquí verás su ubicación actual y si está libre para mover.');

        if (!bloqueId) {
            contenedoresList.innerHTML = '<div class="empty-state small"><h3>Selecciona un bloque</h3><p>Elige un bloque de origen para ver los contenedores activos y su disponibilidad real para traslado.</p></div>';
            return;
        }

        fetch('obtener_contenedores_bloque.php?bloque_id=' + encodeURIComponent(bloqueId))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !Array.isArray(data.contenedores)) {
                    throw new Error((data && data.error) || 'No se pudo consultar el bloque.');
                }

                blockContainers = data.contenedores;
                renderSourceSummary(data.meta || null);

                if (!blockContainers.length) {
                    contenedoresList.innerHTML = '<div class="empty-state small"><h3>Bloque sin contenedores</h3><p>No hay contenedores activos registrados en este bloque.</p></div>';
                    return;
                }

                if (preferredContainerId) {
                    const preferred = blockContainers.find(function (item) { return item.id === Number(preferredContainerId); });
                    if (preferred) {
                        selectedContainer = preferred;
                        contenedorIdInput.value = String(preferred.id);
                        renderSelectedContainer();
                    }
                }

                renderContainers();
            })
            .catch(function (error) {
                console.error(error);
                contenedoresList.innerHTML = '<div class="empty-state small"><h3>No fue posible cargar el bloque</h3><p>Intenta de nuevo o selecciona otro bloque.</p></div>';
                showToast(error.message || 'Error al consultar contenedores del bloque.', 'error');
            });
    }

    function loadDestinationBlocks(patioId) {
        setSelectOptions(nuevoBloqueSelect, [], 'Seleccione un bloque');
        nuevoBloqueSelect.disabled = true;
        nuevaPosicionInput.value = '';
        destinationNote.textContent = 'Selecciona un patio y un bloque destino para calcular la posición disponible.';
        updateSubmitState();

        if (!patioId) {
            return;
        }

        fetch('obtener_bloques_disponibles.php?patio_id=' + encodeURIComponent(patioId))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!Array.isArray(data)) {
                    throw new Error(data.error || 'No se pudieron cargar los bloques destino.');
                }

                if (!data.length) {
                    setSelectOptions(nuevoBloqueSelect, [], 'Sin bloques disponibles');
                    nuevoBloqueSelect.disabled = true;
                    if (nuevoBloqueSelect._refreshCustomSelect) nuevoBloqueSelect._refreshCustomSelect();
                    destinationNote.textContent = 'Este patio no tiene bloques activos disponibles para recibir contenedores.';
                    return;
                }

                const options = data.map(function (bloque) {
                    return {
                        value: String(bloque.id),
                        label: 'Bloque ' + bloque.numero + ' · ' + bloque.capacidad_actual + '/' + bloque.capacidad_maxima
                    };
                });

                nuevoBloqueSelect.disabled = false;
                setSelectOptions(nuevoBloqueSelect, options, 'Seleccione un bloque');
                if (nuevoBloqueSelect._refreshCustomSelect) nuevoBloqueSelect._refreshCustomSelect();
            })
            .catch(function (error) {
                console.error(error);
                showToast(error.message || 'Error al cargar bloques destino.', 'error');
            });
    }

    function loadDestinationPosition(bloqueId) {
        nuevaPosicionInput.value = '';
        updateSubmitState();

        if (!bloqueId) {
            destinationNote.textContent = 'Selecciona un patio y un bloque destino para calcular la posición disponible.';
            return;
        }

        destinationNote.textContent = 'Calculando la mejor posición disponible en el bloque seleccionado...';

        fetch('obtener_posiciones_disponibles.php?bloque_id=' + encodeURIComponent(bloqueId))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.error) {
                    destinationNote.textContent = data.error;
                    nuevaPosicionInput.value = '';
                    updateSubmitState();
                    return;
                }

                nuevaPosicionInput.value = data.posicion || '';
                destinationNote.textContent = data.posicion
                    ? 'La posición sugerida se calculó con base en la capacidad activa del bloque destino.'
                    : 'No se encontró una posición libre en este bloque.';
                updateSubmitState();
            })
            .catch(function (error) {
                console.error(error);
                destinationNote.textContent = 'No se pudo calcular la posición disponible.';
                showToast('Error al calcular la posición del destino.', 'error');
            });
    }

    origenPatioSelect.addEventListener('change', function () {
        filtroContenedor.value = '';
        loadOriginBlocks(this.value);
    });

    origenBloqueSelect.addEventListener('change', function () {
        filtroContenedor.value = '';
        loadContainersForBlock(this.value);
    });

    filtroContenedor.addEventListener('input', renderContainers);

    nuevoPatioSelect.addEventListener('change', function () {
        loadDestinationBlocks(this.value);
    });

    nuevoBloqueSelect.addEventListener('change', function () {
        loadDestinationPosition(this.value);
    });

    if (autoSelect.patio_id) {
        loadOriginBlocks(autoSelect.patio_id, autoSelect.bloque_id || '');
    } else if (origenPatioSelect.value) {
        loadOriginBlocks(origenPatioSelect.value);
    }

    <?php if (isset($_SESSION['toast_message'])): ?>
    showToast('<?php echo addslashes($_SESSION['toast_message']); ?>', '<?php echo $_SESSION['toast_type'] ?? 'success'; ?>');
    <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
    <?php endif; ?>
});
</script>
</body>
</html>
