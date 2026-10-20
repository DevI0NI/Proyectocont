<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

if (!in_array((int)($_SESSION['rol_id'] ?? 0), [1, 2], true)) {
    header('Location: ../../index.php');
    exit;
}

if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../../index.php');
    exit;
}

require '../../config/database.php';

$rol_id = (int)($_SESSION['rol_id'] ?? 0);
$sucursal_id = (int)$_SESSION['sucursal_activa'];
$username = $_SESSION['username'] ?? 'Usuario';

$stmtNomSuc = $pdo->prepare('SELECT nombre FROM sucursales WHERE id = :id');
$stmtNomSuc->execute(['id' => $sucursal_id]);
$nombreSucursal = $stmtNomSuc->fetchColumn() ?: 'Desconocida';

$sucursales = [];
if ($rol_id === 1) {
    $stmtSuc = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursales = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
}

$stmtPatios = $pdo->prepare('
    SELECT
        p.id,
        p.nombre,
        COUNT(b.id) AS bloques_activos,
        COALESCE(SUM(b.capacidad_maxima), 0) AS capacidad_total
    FROM patios p
    LEFT JOIN bloques b
        ON b.patio_id = p.id
       AND b.estado_id = 1
    WHERE p.estado_id = 1
      AND p.sucursal_id = :sucursal_id
    GROUP BY p.id, p.nombre
    ORDER BY p.id ASC
');
$stmtPatios->execute(['sucursal_id' => $sucursal_id]);
$patios = $stmtPatios->fetchAll(PDO::FETCH_ASSOC);

$patioSeleccionado = (int)($_GET['patio_id'] ?? 0);
if ($patioSeleccionado <= 0 && $patios !== []) {
    $patioSeleccionado = (int)$patios[0]['id'];
}

$patioActual = null;
foreach ($patios as $patio) {
    if ((int)$patio['id'] === $patioSeleccionado) {
        $patioActual = $patio;
        break;
    }
}

if ($patioActual === null && $patios !== []) {
    $patioActual = $patios[0];
    $patioSeleccionado = (int)$patioActual['id'];
}

$bloques = [];
if ($patioActual !== null) {
    $stmtBloques = $pdo->prepare('
        SELECT
            b.id,
            b.numero,
            b.capacidad_maxima,
            b.capacidad_actual,
            COUNT(c.id) AS contenedores_activos
        FROM bloques b
        LEFT JOIN contenedores c
            ON c.bloque_id = b.id
           AND c.estado_id = 1
        WHERE b.estado_id = 1
          AND b.patio_id = :patio_id
        GROUP BY b.id, b.numero, b.capacidad_maxima, b.capacidad_actual
        ORDER BY b.numero ASC
    ');
    $stmtBloques->execute(['patio_id' => $patioSeleccionado]);
    $bloques = $stmtBloques->fetchAll(PDO::FETCH_ASSOC);
}

$totalPatios = count($patios);
$totalBloques = count($bloques);
$capacidadTotalPatio = array_sum(array_map(static fn($bloque) => (int)$bloque['capacidad_maxima'], $bloques));

$menuItems = [
    ['label' => 'Contenedores', 'href' => '../../bloques/bloques.php', 'icon' => '../../imagenes/bloques.png', 'active' => false],
    ['label' => 'Seguimiento PYP', 'href' => '../../bloques/contenedor.php', 'icon' => '../../imagenes/cont.png', 'active' => false],
    ['label' => 'Traslados', 'href' => '../../traslados/traslados.php', 'icon' => '../../imagenes/traslados.png', 'active' => false],
    ['label' => 'Despachos', 'href' => '../../despachos/despachos.php', 'icon' => '../../imagenes/egresos.png', 'active' => false],
    ['label' => 'Reportes', 'href' => '../../reportes/reportes.php', 'icon' => '../../imagenes/reportes.png', 'active' => false],
    ['label' => 'Parametros', 'href' => '../parametros.php', 'icon' => '../../imagenes/parametros.png', 'active' => true],
];

$toastPayload = null;
if (isset($_SESSION['toast_message'])) {
    $toastPayload = [
        'text' => $_SESSION['toast_message'],
        'type' => $_SESSION['toast_type'] ?? 'success',
    ];
    unset($_SESSION['toast_message'], $_SESSION['toast_type']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Configuracion de Bloques</title>
    <link rel="stylesheet" href="../../styles/index.css">
    <link rel="stylesheet" href="../../styles/parametros/bloques/bloques.css">
    <link rel="stylesheet" href="../../styles/parametros/bloques/configuracion.css">
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
            <img src="../../imagenes/IPSA.png" alt="Logo" class="sidebar-logo">
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
                    <a href="../../logout.php" class="sidebar-mobile-user-logout">Cerrar sesion</a>
                </div>
            </div>

            <?php if ($rol_id === 1): ?>
                <form action="../../cambiar_sucursal.php" method="POST" class="sidebar-mobile-sucursal-form">
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
            <a href="../../index.php" class="nav-item">
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
                        <form action="../../cambiar_sucursal.php" method="POST" class="topbar-sucursal-form">
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
                        <a href="../../logout.php" class="user-menu-item user-menu-logout">Cerrar sesion</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="dashboard-main bloques-main configuracion-main">
            <section class="panel-card bloques-module configuracion-module">
                <div class="module-head">
                    <div class="view-header-copy">
                        <div class="view-header-topline">
                            <h1>Configuracion de Bloques</h1>
                        </div>
                    </div>
                </div>

                <div class="config-body">
                    <section class="config-section">
                        <div class="config-section-head">
                            <div>
                                <h2>Bloques del patio</h2>
                                <p>Selecciona un patio, revisa su estructura y administra los bloques con acciones directas.</p>
                            </div>
                            <div class="config-section-tools config-section-tools-wide">
                                <label class="config-search" for="buscarBloque">
                                    <span>Buscar bloque</span>
                                    <input type="search" id="buscarBloque" placeholder="Ej. Bloque 3">
                                </label>
                                <label class="config-order" for="ordenBloques">
                                    <span>Ordenar</span>
                                    <select id="ordenBloques">
                                        <option value="numero_asc">Bloque 1-9</option>
                                        <option value="numero_desc">Bloque 9-1</option>
                                        <option value="capacidad_desc">Mayor capacidad</option>
                                        <option value="capacidad_asc">Menor capacidad</option>
                                    </select>
                                </label>
                                <?php if ($patioActual !== null): ?>
                                    <button type="button" class="toolbar-button toolbar-button-primary" id="openCreateBlockModal">Nuevo Bloque</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($patioActual === null): ?>
                            <div class="empty-state table-empty">
                                <h3>Sin patios activos</h3>
                                <p>Primero crea un patio en la configuracion principal para luego administrar sus bloques.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-wrap bloques-table-wrap">
                                <table class="bloques-table" id="blocksTable">
                                    <thead>
                                        <tr>
                                            <th>Bloque</th>
                                            <th>Capacidad maxima</th>
                                            <th>Ocupacion actual</th>
                                            <th>Contenedores activos</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody id="blocksTableBody">
                                        <?php if ($bloques): ?>
                                            <?php foreach ($bloques as $bloque): ?>
                                                <tr
                                                    data-id="<?php echo (int)$bloque['id']; ?>"
                                                    data-numero="<?php echo (int)$bloque['numero']; ?>"
                                                    data-name="bloque <?php echo (int)$bloque['numero']; ?>"
                                                    data-capacidad="<?php echo (int)$bloque['capacidad_maxima']; ?>"
                                                >
                                                    <td data-label="Bloque">
                                                        <span class="table-badge">Bloque <?php echo (int)$bloque['numero']; ?></span>
                                                    </td>
                                                    <td data-label="Capacidad maxima">
                                                        <span class="table-primary"><?php echo (int)$bloque['capacidad_maxima']; ?></span>
                                                    </td>
                                                    <td data-label="Ocupacion actual">
                                                        <span class="table-muted"><?php echo (int)$bloque['capacidad_actual']; ?></span>
                                                    </td>
                                                    <td data-label="Contenedores activos">
                                                        <span class="table-muted"><?php echo (int)$bloque['contenedores_activos']; ?></span>
                                                    </td>
                                                    <td data-label="Acciones">
                                                        <div class="table-actions">
                                                            <button
                                                                type="button"
                                                                class="action-pill action-pill-edit open-edit-block-modal"
                                                                data-id="<?php echo (int)$bloque['id']; ?>"
                                                                data-capacidad="<?php echo (int)$bloque['capacidad_maxima']; ?>"
                                                                data-numero="<?php echo (int)$bloque['numero']; ?>"
                                                            >Editar</button>
                                                            <button
                                                                type="button"
                                                                class="action-pill action-pill-delete open-delete-block-modal"
                                                                data-id="<?php echo (int)$bloque['id']; ?>"
                                                                data-numero="<?php echo (int)$bloque['numero']; ?>"
                                                                data-contenedores="<?php echo (int)$bloque['contenedores_activos']; ?>"
                                                            >Eliminar</button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5">
                                                    <div class="empty-state table-empty">
                                                        <h3>Sin bloques en este patio</h3>
                                                        <p>Agrega el primer bloque de <?php echo htmlspecialchars($patioActual['nombre']); ?> desde el boton superior.</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </section>
        </main>
    </div>
</div>

<?php if ($patioActual !== null): ?>
    <div class="modal-backdrop" id="blockModalBackdrop"></div>
    <div class="modal-shell" id="blockModal" role="dialog" aria-modal="true" aria-labelledby="blockModalTitle">
        <div class="modal-card">
            <div class="modal-head">
                <div class="modal-head-copy">
                    <h2 id="blockModalTitle">Nuevo Bloque</h2>
                    <p id="blockModalDescription">Registra una nueva capacidad operativa para este patio.</p>
                </div>
                <button type="button" class="modal-close" id="closeBlockModal" aria-label="Cerrar">&times;</button>
            </div>
            <form method="post" action="guardar_configuracion_bloques.php" id="blockModalForm" class="sucursal-form">
                <input type="hidden" name="action" id="blockModalAction" value="create_block">
                <input type="hidden" name="id" id="blockModalId" value="0">
                <input type="hidden" name="patio_id" value="<?php echo (int)$patioSeleccionado; ?>">
                <div class="form-row">
                    <label for="blockModalCapacidad">Capacidad maxima</label>
                    <div class="field-stack">
                        <input type="number" min="0" name="capacidad_maxima" id="blockModalCapacidad" placeholder="Ej. 25" required>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="toolbar-button toolbar-button-secondary" id="cancelBlockModal">Cancelar</button>
                    <button type="submit" class="toolbar-button toolbar-button-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-backdrop" id="deleteBlockBackdrop"></div>
    <div class="modal-shell modal-shell-compact" id="deleteBlockModal" role="dialog" aria-modal="true" aria-labelledby="deleteBlockTitle">
        <div class="modal-card modal-card-compact">
            <div class="modal-head modal-head-compact">
                <div class="modal-head-copy">
                    <h2 id="deleteBlockTitle">Eliminar bloque</h2>
                    <p id="deleteBlockMessage">Esta accion desactivara el bloque seleccionado.</p>
                </div>
                <button type="button" class="modal-close" id="closeDeleteBlockModal" aria-label="Cerrar">&times;</button>
            </div>
            <form method="post" action="guardar_configuracion_bloques.php" class="confirm-actions">
                <input type="hidden" name="action" value="delete_block">
                <input type="hidden" name="id" id="deleteBlockId" value="0">
                <input type="hidden" name="patio_id" value="<?php echo (int)$patioSeleccionado; ?>">
                <button type="button" class="toolbar-button toolbar-button-secondary" id="cancelDeleteBlockModal">Cancelar</button>
                <button type="submit" class="toolbar-button toolbar-button-danger">Eliminar</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($toastPayload): ?>
<script>
window.toastPayload = <?php echo json_encode($toastPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<?php endif; ?>

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
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const sidebarClose = document.getElementById('sidebarClose');
const toggle = document.getElementById('sidebarToggle');
const mobileQuery = window.matchMedia('(max-width: 980px)');
const blockSearchInput = document.getElementById('buscarBloque');
const blockOrderSelect = document.getElementById('ordenBloques');
const blocksTableBody = document.getElementById('blocksTableBody');

function showToast(message, type = 'success') {
  const toastContainer = document.getElementById('toast-container');
  if (!toastContainer || !message) return;
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.textContent = message;
  toastContainer.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('show'));
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, 4000);
}

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

function filterBlocks(value) {
    if (!blocksTableBody) return;
    const search = value.trim().toLowerCase();
    blocksTableBody.querySelectorAll('tr').forEach((row) => {
        const rowName = row.dataset.name || '';
        row.style.display = search !== '' && !rowName.includes(search) ? 'none' : '';
    });
}

function sortBlocks(order) {
    if (!blocksTableBody) return;

    const rows = Array.from(blocksTableBody.querySelectorAll('tr[data-id]'));
    rows.sort((a, b) => {
        const numeroA = parseInt(a.dataset.numero || '0', 10);
        const numeroB = parseInt(b.dataset.numero || '0', 10);
        const capacidadA = parseInt(a.dataset.capacidad || '0', 10);
        const capacidadB = parseInt(b.dataset.capacidad || '0', 10);

        if (order === 'numero_desc') return numeroB - numeroA;
        if (order === 'capacidad_desc') return capacidadB - capacidadA;
        if (order === 'capacidad_asc') return capacidadA - capacidadB;
        return numeroA - numeroB;
    });

    rows.forEach((row) => blocksTableBody.appendChild(row));
}

if (window.toastPayload && window.toastPayload.text) {
    window.addEventListener('DOMContentLoaded', function () {
        showToast(window.toastPayload.text, window.toastPayload.type || 'success');
    });
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

blockSearchInput?.addEventListener('input', function () {
    filterBlocks(this.value);
});

blockOrderSelect?.addEventListener('change', function () {
    sortBlocks(this.value);
    if (blockSearchInput) {
        filterBlocks(blockSearchInput.value);
    }
});

const blockModal = document.getElementById('blockModal');
const blockModalBackdrop = document.getElementById('blockModalBackdrop');
const closeBlockModal = document.getElementById('closeBlockModal');
const cancelBlockModal = document.getElementById('cancelBlockModal');
const blockModalTitle = document.getElementById('blockModalTitle');
const blockModalDescription = document.getElementById('blockModalDescription');
const blockModalAction = document.getElementById('blockModalAction');
const blockModalId = document.getElementById('blockModalId');
const blockModalCapacidad = document.getElementById('blockModalCapacidad');
const openCreateBlockModal = document.getElementById('openCreateBlockModal');
const deleteBlockModal = document.getElementById('deleteBlockModal');
const deleteBlockBackdrop = document.getElementById('deleteBlockBackdrop');
const closeDeleteBlockModal = document.getElementById('closeDeleteBlockModal');
const cancelDeleteBlockModal = document.getElementById('cancelDeleteBlockModal');
const deleteBlockId = document.getElementById('deleteBlockId');
const deleteBlockMessage = document.getElementById('deleteBlockMessage');

function openBlockModal(mode, payload = {}) {
    blockModalTitle.textContent = mode === 'edit' ? `Editar Bloque ${payload.numero || ''}`.trim() : 'Nuevo Bloque';
    blockModalDescription.textContent = mode === 'edit'
        ? 'Actualiza la capacidad operativa del bloque seleccionado.'
        : 'Registra una nueva capacidad operativa para este patio.';
    blockModalAction.value = mode === 'edit' ? 'update_block' : 'create_block';
    blockModalId.value = payload.id || '0';
    blockModalCapacidad.value = payload.capacidad || '';
    blockModal.classList.add('show');
    blockModalBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
    setTimeout(() => blockModalCapacidad?.focus(), 40);
}

function closeBlockModalFn() {
    blockModal?.classList.remove('show');
    blockModalBackdrop?.classList.remove('show');
    if (!deleteBlockModal?.classList.contains('show')) {
        document.body.classList.remove('modal-open');
    }
}

function openDeleteBlockModalFn(id, numero, contenedores) {
    deleteBlockId.value = id;
    deleteBlockMessage.textContent = contenedores > 0
        ? `El Bloque ${numero} tiene ${contenedores} contenedor(es) activos. Si siguen ocupandolo, no se podra eliminar.`
        : `Vas a desactivar el Bloque ${numero}.`;
    deleteBlockModal.classList.add('show');
    deleteBlockBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
}

function closeDeleteBlockModalFn() {
    deleteBlockModal?.classList.remove('show');
    deleteBlockBackdrop?.classList.remove('show');
    if (!blockModal?.classList.contains('show')) {
        document.body.classList.remove('modal-open');
    }
}

openCreateBlockModal?.addEventListener('click', () => openBlockModal('create'));
closeBlockModal?.addEventListener('click', closeBlockModalFn);
cancelBlockModal?.addEventListener('click', closeBlockModalFn);
blockModalBackdrop?.addEventListener('click', closeBlockModalFn);
closeDeleteBlockModal?.addEventListener('click', closeDeleteBlockModalFn);
cancelDeleteBlockModal?.addEventListener('click', closeDeleteBlockModalFn);
deleteBlockBackdrop?.addEventListener('click', closeDeleteBlockModalFn);

document.querySelectorAll('.open-edit-block-modal').forEach((button) => {
    button.addEventListener('click', () => {
        openBlockModal('edit', {
            id: button.dataset.id,
            numero: button.dataset.numero,
            capacidad: button.dataset.capacidad
        });
    });
});

document.querySelectorAll('.open-delete-block-modal').forEach((button) => {
    button.addEventListener('click', () => {
        openDeleteBlockModalFn(
            button.dataset.id,
            button.dataset.numero,
            parseInt(button.dataset.contenedores || '0', 10)
        );
    });
});
</script>
</body>
</html>
