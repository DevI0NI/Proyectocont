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
if (in_array($rol_id, [1, 2, 3, 4], true)) {
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Despachos - Proportsa</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/despachos/despachos.css">
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

        <main class="dashboard-main despachos-main">
            <section class="view-hero">
                <div>
                    <h1>Despacho de Contenedores</h1>
                    <p>Selecciona un contenedor disponible, revisa su estado operativo y confirma la salida con tus observaciones.</p>
                </div>
            </section>

            <form action="procesar_despacho.php" method="post" id="formDespacho" class="despacho-form">
                <input type="hidden" id="contenedor_id" name="contenedor_id" required>

                <div class="despacho-layout">
                    <section class="panel-card despacho-panel despacho-panel-main">
                        <div class="section-heading">
                            <h2>Seleccionar Contenedor</h2>
                            <p>Busca por codigo y valida de inmediato si el equipo puede salir del bloque actual.</p>
                        </div>

                        <div class="search-block">
                            <label for="buscar-contenedor">Codigo del contenedor</label>
                            <div class="search-field-wrap">
                                <input type="text" id="buscar-contenedor" placeholder="Buscar por codigo..." autocomplete="off">
                                <div id="resultados-contenedores" class="resultados-container" hidden></div>
                            </div>
                        </div>

                        <div id="empty-state" class="empty-state">
                            <div class="empty-state-icon" aria-hidden="true">
                                <img src="../imagenes/egresos.png" alt="">
                            </div>
                            <h3>Busca un contenedor para comenzar</h3>
                            <p>Cuando elijas uno, aqui veras su ubicacion actual, su estado IPSA, el resultado PYP y si esta realmente apto para despacho.</p>
                        </div>
                    </section>

                    <aside class="despacho-side">
                        <section id="info-contenedor" class="panel-card selection-card" hidden>
                            <div class="section-heading section-heading-compact">
                                <h2>Resumen del contenedor</h2>
                                <p>Verifica la ubicacion, aprobaciones y bloqueo fisico antes de despachar.</p>
                            </div>

                            <div class="summary-list">
                                <div class="summary-item">
                                    <span class="summary-label">Ubicacion actual</span>
                                    <strong id="info-ubicacion">-</strong>
                                </div>
                                <div class="summary-grid">
                                    <div class="summary-chip-block">
                                        <span class="summary-label">Estatus IPSA</span>
                                        <span id="info-ipsa" class="status-pill">-</span>
                                    </div>
                                    <div class="summary-chip-block">
                                        <span class="summary-label">Estatus PYP</span>
                                        <span id="info-pyp" class="status-pill">-</span>
                                    </div>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Estado de salida</span>
                                    <div id="info-salida" class="salida-status">-</div>
                                </div>
                            </div>
                        </section>

                        <section class="panel-card despacho-panel">
                            <div class="section-heading section-heading-compact">
                                <h2>Datos de salida</h2>
                                <p>Documenta la observacion final y confirma el despacho cuando el contenedor este listo.</p>
                            </div>

                            <div class="form-block">
                                <label for="observaciones">Observaciones</label>
                                <textarea id="observaciones" name="observaciones" rows="5" maxlength="150" required placeholder="Ingresa informacion extra del despacho..."></textarea>
                                <span id="charCount" class="char-counter">0 / 150</span>
                            </div>

                            <button type="submit" class="toolbar-button toolbar-button-danger despacho-submit" id="btn-despachar" disabled>
                                <span class="toolbar-icon" aria-hidden="true">
                                    <img src="../imagenes/egresos.png" alt="">
                                </span>
                                <span>Despachar contenedor</span>
                            </button>

                            <div class="despacho-doc-flow">
                                <div class="despacho-doc-copy">
                                    <strong>Constancia posterior</strong>
                                    <span>Despues de confirmar el despacho, aqui continuaria el paso documental para completar la constancia y emitir el ticket de salida.</span>
                                </div>
                                <a href="eir_salida.php" class="toolbar-button toolbar-button-secondary despacho-doc-link">
                                    <span class="toolbar-icon" aria-hidden="true">
                                        <img src="../imagenes/report.png" alt="">
                                    </span>
                                    <span>Completar constancia</span>
                                </a>
                            </div>
                        </section>
                    </aside>
                </div>
            </form>
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
        const form = sidebarMobileSucursalTrigger.closest('.sidebar-mobile-sucursal-form');
        if (form && !form.contains(event.target)) {
            sidebarMobileSucursalMenu.classList.remove('open');
            sidebarMobileSucursalTrigger.setAttribute('aria-expanded', 'false');
        }
    });
}

syncThemeToggle();

const buscarInput = document.getElementById('buscar-contenedor');
const contenedorIdInput = document.getElementById('contenedor_id');
const resultados = document.getElementById('resultados-contenedores');
const infoCard = document.getElementById('info-contenedor');
const emptyState = document.getElementById('empty-state');
const btnDespachar = document.getElementById('btn-despachar');
const spanUbic = document.getElementById('info-ubicacion');
const spanIpsa = document.getElementById('info-ipsa');
const spanPyp = document.getElementById('info-pyp');
const spanSalida = document.getElementById('info-salida');
const obsInput = document.getElementById('observaciones');
const charCount = document.getElementById('charCount');
let searchTimeout = null;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};
        return map[char] || char;
    });
}

function renderStatusPill(text, kind) {
    return '<span class="status-pill ' + kind + '">' + escapeHtml(text) + '</span>';
}

function resetSelection() {
    contenedorIdInput.value = '';
    infoCard.hidden = true;
    emptyState.hidden = false;
    btnDespachar.disabled = true;
    btnDespachar.classList.add('is-disabled');
    spanUbic.textContent = '-';
    spanIpsa.textContent = '-';
    spanPyp.textContent = '-';
    spanSalida.innerHTML = '-';
}

function seleccionarContenedor(c) {
    buscarInput.value = c.nombre;
    contenedorIdInput.value = c.id;
    resultados.hidden = true;
    resultados.innerHTML = '';
    infoCard.hidden = false;
    emptyState.hidden = true;

    spanUbic.textContent = c.patio + ' / Bloque ' + c.bloque + ' / Pos. ' + c.posicion;
    spanIpsa.outerHTML = renderStatusPill(c.estatus_ipsa || 'Sin estatus', (c.estatus_ipsa_id == 1 ? 'status-ok' : 'status-bad'));
    spanPyp.outerHTML = renderStatusPill(c.estatus_pyp || 'Sin auditoria', c.estatus_pyp ? 'status-ok' : 'status-warn');

    const ipsaNode = document.getElementById('info-ipsa');
    const pypNode = document.getElementById('info-pyp');

    let puedeSalir = true;
    const mensajes = [];

    if (String(c.estatus_ipsa_id) !== '1') {
        puedeSalir = false;
        mensajes.push('Estatus IPSA no disponible');
    }

    if (!c.estatus_pyp) {
        puedeSalir = false;
        mensajes.push('Falta auditoria PYP');
    }

    if (c.bloqueado_fisicamente) {
        puedeSalir = false;
        mensajes.push('Bloqueado por otros contenedores');
    }

    if (puedeSalir) {
        spanSalida.innerHTML = renderStatusPill('Listo para despacho', 'status-ok');
        btnDespachar.disabled = false;
        btnDespachar.classList.remove('is-disabled');
    } else {
        spanSalida.innerHTML = renderStatusPill(mensajes.join(' · '), 'status-bad');
        btnDespachar.disabled = true;
        btnDespachar.classList.add('is-disabled');
    }
}

function renderResultados(items) {
    if (!items.length) {
        resultados.innerHTML = '<div class="resultado-empty">No se encontraron contenedores activos con ese codigo.</div>';
        resultados.hidden = false;
        return;
    }

    resultados.innerHTML = items.map(function (c) {
        const status = (String(c.estatus_ipsa_id) === '1' && c.estatus_pyp && !c.bloqueado_fisicamente)
            ? '<span class="resultado-badge ok">Listo</span>'
            : '<span class="resultado-badge warn">Revisar</span>';

        return `
            <button type="button" class="resultado-item" data-id="${escapeHtml(c.id)}">
                <div class="resultado-main">
                    <strong>${escapeHtml(c.nombre)}</strong>
                    <small>${escapeHtml(c.patio)} / Bloque ${escapeHtml(c.bloque)} / Pos. ${escapeHtml(c.posicion)}</small>
                </div>
                ${status}
            </button>
        `;
    }).join('');

    resultados.hidden = false;

    resultados.querySelectorAll('.resultado-item').forEach(function (item, index) {
        item.addEventListener('click', function () {
            seleccionarContenedor(items[index]);
        });
    });
}

function buscarContenedor(query) {
    if (!query || query.trim().length < 2) {
        resultados.hidden = true;
        resultados.innerHTML = '';
        return;
    }

    fetch('obtener_contenedores_despacho.php?query=' + encodeURIComponent(query.trim()))
        .then(function (response) { return response.json(); })
        .then(function (data) { renderResultados(Array.isArray(data) ? data : []); })
        .catch(function () {
            resultados.innerHTML = '<div class="resultado-empty">No se pudo consultar el inventario en este momento.</div>';
            resultados.hidden = false;
        });
}

buscarInput.addEventListener('input', function () {
    clearTimeout(searchTimeout);
    resetSelection();
    searchTimeout = setTimeout(function () {
        buscarContenedor(buscarInput.value);
    }, 180);
});

document.addEventListener('click', function (event) {
    if (!event.target.closest('.search-field-wrap')) {
        resultados.hidden = true;
    }
});

obsInput.addEventListener('input', function () {
    const currentLength = this.value.length;
    const maxLength = Number(this.getAttribute('maxlength')) || 150;
    charCount.textContent = currentLength + ' / ' + maxLength;
    charCount.classList.toggle('text-danger', currentLength >= maxLength);
});

function showToast(msg, type) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(function () { toast.classList.add('show'); }, 20);
    setTimeout(function () {
        toast.classList.remove('show');
        setTimeout(function () { toast.remove(); }, 280);
    }, 4200);
}

<?php if (isset($_SESSION['toast_message'])): ?>
showToast("<?php echo addslashes($_SESSION['toast_message']); ?>", "<?php echo $_SESSION['toast_type'] ?? 'success'; ?>");
<?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
<?php endif; ?>

resetSelection();
charCount.textContent = '0 / 150';
</script>
</body>
</html>
