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

$ticket = [
    'numero' => 'EIR-IN-' . date('Ymd') . '-014',
    'fecha' => date('d/m/Y'),
    'hora' => date('H:i') . ' hrs',
    'equipo' => 'MMAU0000056',
    'tipo' => 'MAC',
    'modelo' => 'MAC2',
    'naviera' => 'Mediterranean Shipping Company',
    'cliente' => 'Agencia Atlantico',
    'transportista' => 'Transportes del Norte, S.A.',
    'conductor' => 'Luis Fernando Barrios',
    'licencia' => 'C-3489201',
    'placa_cabezal' => 'C-812BDR',
    'placa_chasis' => 'TC-4419',
    'marchamo' => 'MSCU554812',
    'patio' => 'Patio 3',
    'bloque' => 'Bloque 1',
    'posicion' => '23',
    'estado_unidad' => 'OK',
    'estado_box' => 'DMG',
    'ipsa' => 'Disponible',
    'referencia' => 'Ingreso comercial por gate norte. Equipo recibido para operacion regular y ubicacion inmediata en patio.',
    'usuario' => $username,
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Ticket de Ingreso</title>
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
                    <h1>Ticket de Ingreso</h1>
                    <p>Vista previa del comprobante operativo que resumiria la recepcion del equipo en <?php echo htmlspecialchars($nombreSucursalActiva); ?>.</p>
                </div>
            </section>

            <div class="ticket-toolbar panel-card">
                <a href="eir_ingreso.php" class="toolbar-button toolbar-button-secondary ingreso-back-button">
                    <img src="../imagenes/regresar.png" alt="" aria-hidden="true" class="toolbar-icon">
                    <span>Volver a Constancia</span>
                </a>
                <button type="button" class="toolbar-button toolbar-button-secondary">
                    <img src="../imagenes/report.png" alt="" aria-hidden="true" class="toolbar-icon">
                    <span>Imprimir Ticket</span>
                </button>
            </div>

            <section class="panel-card ticket-preview-card">
                <article class="eir-ticket-sheet">
                    <header class="eir-ticket-header">
                        <div class="eir-ticket-brand">
                            <img src="../imagenes/IPSA.png" alt="IPSA" class="eir-ticket-logo">
                            <div class="eir-ticket-brand-copy">
                                <small>Sistema Portuario Proportsa</small>
                                <h2>Constancia de Ingreso / EIR</h2>
                                <p>Sucursal: <?php echo htmlspecialchars($nombreSucursalActiva); ?></p>
                            </div>
                        </div>
                        <div class="eir-ticket-code">
                            <span>No. Documento</span>
                            <strong><?php echo htmlspecialchars($ticket['numero']); ?></strong>
                            <small><?php echo htmlspecialchars($ticket['fecha'] . ' | ' . $ticket['hora']); ?></small>
                        </div>
                    </header>

                    <section class="eir-ticket-section">
                        <div class="eir-ticket-title-row">
                            <h3>Datos del equipo</h3>
                            <span class="eir-ticket-badge">Ingreso</span>
                        </div>
                        <div class="eir-ticket-grid">
                            <div><small>Numero de equipo</small><strong><?php echo htmlspecialchars($ticket['equipo']); ?></strong></div>
                            <div><small>Tipo / Modelo</small><strong><?php echo htmlspecialchars($ticket['tipo'] . ' / ' . $ticket['modelo']); ?></strong></div>
                            <div><small>Estado unidad</small><strong><?php echo htmlspecialchars($ticket['estado_unidad']); ?></strong></div>
                            <div><small>Estado box</small><strong><?php echo htmlspecialchars($ticket['estado_box']); ?></strong></div>
                            <div><small>Estatus IPSA</small><strong><?php echo htmlspecialchars($ticket['ipsa']); ?></strong></div>
                            <div><small>Ubicacion</small><strong><?php echo htmlspecialchars($ticket['patio'] . ' / ' . $ticket['bloque'] . ' / Pos. ' . $ticket['posicion']); ?></strong></div>
                        </div>
                    </section>

                    <section class="eir-ticket-section">
                        <h3>Datos del transporte</h3>
                        <div class="eir-ticket-grid">
                            <div><small>Naviera</small><strong><?php echo htmlspecialchars($ticket['naviera']); ?></strong></div>
                            <div><small>Cliente / Agencia</small><strong><?php echo htmlspecialchars($ticket['cliente']); ?></strong></div>
                            <div><small>Transportista</small><strong><?php echo htmlspecialchars($ticket['transportista']); ?></strong></div>
                            <div><small>Conductor</small><strong><?php echo htmlspecialchars($ticket['conductor']); ?></strong></div>
                            <div><small>Licencia</small><strong><?php echo htmlspecialchars($ticket['licencia']); ?></strong></div>
                            <div><small>Placa cabezal</small><strong><?php echo htmlspecialchars($ticket['placa_cabezal']); ?></strong></div>
                            <div><small>Placa chasis</small><strong><?php echo htmlspecialchars($ticket['placa_chasis']); ?></strong></div>
                            <div><small>Marchamo</small><strong><?php echo htmlspecialchars($ticket['marchamo']); ?></strong></div>
                        </div>
                    </section>

                    <section class="eir-ticket-section">
                        <h3>Referencia operativa</h3>
                        <p class="eir-ticket-notes"><?php echo htmlspecialchars($ticket['referencia']); ?></p>
                    </section>

                    <section class="eir-ticket-section eir-ticket-signatures">
                        <div class="eir-signature-box">
                            <span>Recibido por sistema</span>
                            <strong><?php echo htmlspecialchars($ticket['usuario']); ?></strong>
                        </div>
                        <div class="eir-signature-box">
                            <span>Firma conductor</span>
                            <strong>________________________</strong>
                        </div>
                        <div class="eir-signature-box">
                            <span>Firma receptor</span>
                            <strong>________________________</strong>
                        </div>
                    </section>
                </article>
            </section>
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
