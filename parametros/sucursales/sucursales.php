<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

if ((int)($_SESSION['rol_id'] ?? 0) !== 1) {
    $_SESSION['toast_message'] = 'Acceso denegado: Solo el Super Administrador puede gestionar sucursales.';
    $_SESSION['toast_type'] = 'error';
    header('Location: ../../index.php');
    exit;
}

require '../../config/database.php';

$sucursal_id = (int)($_SESSION['sucursal_activa'] ?? 0);
$username = $_SESSION['username'] ?? 'Usuario';
$rol_id = (int)($_SESSION['rol_id'] ?? 0);

$stmtSuc = $pdo->query('SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC');
$sucursalesHeader = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);

$nombreSucursalActiva = 'Desconocida';
if ($sucursal_id > 0) {
    $stmtNomSucursal = $pdo->prepare('SELECT nombre FROM sucursales WHERE id = :id');
    $stmtNomSucursal->execute(['id' => $sucursal_id]);
    $nombreSucursalActiva = $stmtNomSucursal->fetchColumn() ?: 'Desconocida';
}

$modalMode = 'create';
$modalOpen = false;
$formError = '';
$formData = [
    'id' => 0,
    'nombre' => '',
    'ubicacion' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $nombre = trim($_POST['nombre'] ?? '');
    $ubicacion = trim($_POST['ubicacion'] ?? '');
    $formData['nombre'] = $nombre;
    $formData['ubicacion'] = $ubicacion;

    try {
        if ($nombre === '' || $ubicacion === '') {
            throw new Exception('Completa el nombre y la ubicacion de la sucursal.');
        }

        if ($action === 'create_sucursal') {
            $modalMode = 'create';
            $modalOpen = true;

            $stmtCheck = $pdo->prepare('SELECT id, estado_id FROM sucursales WHERE nombre = :nombre ORDER BY id DESC LIMIT 1');
            $stmtCheck->execute(['nombre' => $nombre]);
            $existente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existente) {
                if ((int)$existente['estado_id'] === 1) {
                    throw new Exception('Ya existe una sucursal activa con este nombre.');
                }

                $stmtReactivate = $pdo->prepare('UPDATE sucursales SET nombre = :nombre, ubicacion = :ubicacion, estado_id = 1 WHERE id = :id');
                $stmtReactivate->execute([
                    'nombre' => $nombre,
                    'ubicacion' => $ubicacion,
                    'id' => (int)$existente['id'],
                ]);

                $_SESSION['toast'] = ['type' => 'success', 'text' => 'Sucursal reactivada correctamente.'];
                header('Location: sucursales.php');
                exit;
            }

            $stmtIns = $pdo->prepare('INSERT INTO sucursales (nombre, ubicacion, estado_id) VALUES (:nombre, :ubicacion, 1)');
            $stmtIns->execute([
                'nombre' => $nombre,
                'ubicacion' => $ubicacion,
            ]);

            $_SESSION['toast'] = ['type' => 'success', 'text' => 'Sucursal creada correctamente.'];
            header('Location: sucursales.php');
            exit;
        }

        if ($action === 'update_sucursal') {
            $modalMode = 'edit';
            $modalOpen = true;
            $formData['id'] = (int)($_POST['id'] ?? 0);

            if ($formData['id'] <= 0) {
                throw new Exception('No se encontro la sucursal que intentas editar.');
            }

            $stmtActual = $pdo->prepare('SELECT id FROM sucursales WHERE id = :id AND estado_id = 1');
            $stmtActual->execute(['id' => $formData['id']]);
            if (!$stmtActual->fetchColumn()) {
                throw new Exception('La sucursal que intentas editar no existe o esta inactiva.');
            }

            $stmtCheck = $pdo->prepare('SELECT id FROM sucursales WHERE nombre = :nombre AND id != :id AND estado_id = 1');
            $stmtCheck->execute([
                'nombre' => $nombre,
                'id' => $formData['id'],
            ]);
            if ($stmtCheck->fetchColumn()) {
                throw new Exception('Este nombre ya existe en el historial del sistema. Elige otro.');
            }

            $stmtUpdate = $pdo->prepare('UPDATE sucursales SET nombre = :nombre, ubicacion = :ubicacion WHERE id = :id');
            $stmtUpdate->execute([
                'nombre' => $nombre,
                'ubicacion' => $ubicacion,
                'id' => $formData['id'],
            ]);

            $_SESSION['toast'] = ['type' => 'success', 'text' => 'Sucursal actualizada correctamente.'];
            header('Location: sucursales.php');
            exit;
        }
    } catch (Exception $e) {
        $formError = $e->getMessage();
    }
}

$itemsPorPagina = 10;
$paginaActual = max(1, (int)($_GET['pagina'] ?? 1));
$inicio = ($paginaActual - 1) * $itemsPorPagina;

$sqlTotal = 'SELECT COUNT(*) FROM sucursales WHERE estado_id = 1';
$stmtTotal = $pdo->query($sqlTotal);
$totalItems = (int)$stmtTotal->fetchColumn();
$totalPaginas = max(1, (int)ceil($totalItems / $itemsPorPagina));

$sql = '
    SELECT id, nombre, ubicacion
    FROM sucursales
    WHERE estado_id = 1
    ORDER BY nombre ASC
    LIMIT :inicio, :items
';
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':inicio', $inicio, PDO::PARAM_INT);
$stmt->bindValue(':items', $itemsPorPagina, PDO::PARAM_INT);
$stmt->execute();
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);

$menuItems = [
    ['label' => 'Contenedores', 'href' => '../../bloques/bloques.php', 'icon' => '../../imagenes/bloques.png', 'active' => false],
    ['label' => 'Seguimiento PYP', 'href' => '../../bloques/contenedor.php', 'icon' => '../../imagenes/cont.png', 'active' => false],
    ['label' => 'Traslados', 'href' => '../../traslados/traslados.php', 'icon' => '../../imagenes/traslados.png', 'active' => false],
    ['label' => 'Despachos', 'href' => '../../despachos/despachos.php', 'icon' => '../../imagenes/egresos.png', 'active' => false],
    ['label' => 'Reportes', 'href' => '../../reportes/reportes.php', 'icon' => '../../imagenes/reportes.png', 'active' => false],
    ['label' => 'Parametros', 'href' => '../parametros.php', 'icon' => '../../imagenes/parametros.png', 'active' => true],
];

$toastPayload = null;
if (isset($_SESSION['toast'])) {
    $toastPayload = $_SESSION['toast'];
    unset($_SESSION['toast']);
} elseif (isset($_SESSION['toast_message'])) {
    $toastPayload = [
        'type' => $_SESSION['toast_type'] ?? 'error',
        'text' => $_SESSION['toast_message'],
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
    <title>Sucursales - Proportsa</title>
    <link rel="stylesheet" href="../../styles/index.css">
    <link rel="stylesheet" href="../../styles/parametros/bloques/bloques.css">
    <link rel="stylesheet" href="../../styles/parametros/sucursales/sucursales.css">
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

            <form action="../../cambiar_sucursal.php" method="POST" class="sidebar-mobile-sucursal-form">
                <input type="hidden" name="sucursal_id" id="sidebar_mobile_sucursal_id" value="<?php echo (int)$sucursal_id; ?>">
                <button type="button" class="sidebar-mobile-sucursal-trigger" id="sidebarMobileSucursalTrigger" aria-haspopup="listbox" aria-expanded="false">
                    <span class="sidebar-mobile-sucursal-copy">
                        <small>Sucursal</small>
                        <strong id="sidebarMobileSucursalValue"><?php echo htmlspecialchars($nombreSucursalActiva); ?></strong>
                    </span>
                    <span class="sidebar-mobile-sucursal-caret" aria-hidden="true">&#9662;</span>
                </button>
                <div class="sidebar-mobile-sucursal-menu" id="sidebarMobileSucursalMenu" role="listbox">
                    <?php foreach ($sucursalesHeader as $suc): ?>
                        <button type="button" class="sidebar-mobile-sucursal-option <?php echo ($sucursal_id == $suc['id']) ? 'active' : ''; ?>" data-value="<?php echo (int)$suc['id']; ?>" role="option" aria-selected="<?php echo ($sucursal_id == $suc['id']) ? 'true' : 'false'; ?>">
                            <?php echo htmlspecialchars($suc['nombre']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </form>

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
                    <form action="../../cambiar_sucursal.php" method="POST" class="topbar-sucursal-form">
                        <span class="topbar-sucursal-label">Sucursal</span>
                        <div class="topbar-sucursal-dropdown" id="sucursalDropdown">
                            <input type="hidden" name="sucursal_id" id="sucursal_id" value="<?php echo (int)$sucursal_id; ?>">
                            <button type="button" class="topbar-sucursal-trigger" id="sucursalTrigger" aria-haspopup="listbox" aria-expanded="false">
                                <span class="topbar-sucursal-value" id="sucursalValue"><?php echo htmlspecialchars($nombreSucursalActiva); ?></span>
                                <span class="topbar-sucursal-caret" aria-hidden="true">&#9662;</span>
                            </button>
                            <div class="topbar-sucursal-menu" id="sucursalMenu" role="listbox">
                                <?php foreach ($sucursalesHeader as $suc): ?>
                                    <button type="button" class="topbar-sucursal-option <?php echo ($sucursal_id == $suc['id']) ? 'active' : ''; ?>" data-value="<?php echo (int)$suc['id']; ?>" role="option" aria-selected="<?php echo ($sucursal_id == $suc['id']) ? 'true' : 'false'; ?>">
                                        <?php echo htmlspecialchars($suc['nombre']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </form>
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

        <main class="dashboard-main bloques-main sucursales-main">
            <section class="panel-card bloques-module sucursales-module">
                <div class="module-head">
                    <div class="sucursales-head">
                        <div class="view-header-copy">
                            <h1>Sucursales</h1>
                            <p>Administra sedes activas, ubicaciones operativas y el contexto principal del sistema.</p>
                        </div>
                        <button type="button" class="toolbar-button toolbar-button-primary" id="openCreateModal">
                            <img src="../../imagenes/nuevo.png" alt="" aria-hidden="true" class="toolbar-icon">
                            <span>Nueva</span>
                        </button>
                    </div>
                </div>

                <div class="bloques-table-panel sucursales-table-panel">
                    <div class="table-wrap bloques-table-wrap">
                    <table class="bloques-table data-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Ubicacion</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($sucursales): ?>
                                <?php foreach ($sucursales as $sucursal): ?>
                                    <tr>
                                        <td data-label="Nombre">
                                            <div class="table-primary"><?php echo htmlspecialchars($sucursal['nombre']); ?></div>
                                        </td>
                                        <td data-label="Ubicacion">
                                            <span class="table-muted"><?php echo htmlspecialchars($sucursal['ubicacion']); ?></span>
                                        </td>
                                        <td data-label="Acciones" class="table-actions">
                                            <button
                                                type="button"
                                                class="action-pill action-pill-edit open-edit-modal"
                                                data-id="<?php echo (int)$sucursal['id']; ?>"
                                                data-nombre="<?php echo htmlspecialchars($sucursal['nombre'], ENT_QUOTES); ?>"
                                                data-ubicacion="<?php echo htmlspecialchars($sucursal['ubicacion'], ENT_QUOTES); ?>"
                                            >Editar</button>
                                            <a href="eliminar_sucursal.php?id=<?php echo (int)$sucursal['id']; ?>" class="action-pill action-pill-delete" onclick="return confirm('¿Seguro que desea eliminar esta sucursal?')">Eliminar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3">
                                        <div class="empty-state table-empty">
                                            <h3>Sin sucursales registradas</h3>
                                            <p>Crea una sucursal nueva para empezar a organizar la operacion del sistema.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>

                    <?php if ($totalPaginas > 1): ?>
                        <div class="pagination">
                            <?php if ($paginaActual > 1): ?>
                                <a href="?pagina=<?php echo $paginaActual - 1; ?>" class="pagination-button">Anterior</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                <a href="?pagina=<?php echo $i; ?>" class="pagination-button <?php echo ($i === $paginaActual) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($paginaActual < $totalPaginas): ?>
                                <a href="?pagina=<?php echo $paginaActual + 1; ?>" class="pagination-button">Siguiente</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<div class="modal-backdrop <?php echo $modalOpen ? 'show' : ''; ?>" id="sucursalModalBackdrop"></div>
<div class="modal-shell <?php echo $modalOpen ? 'show' : ''; ?>" id="sucursalModal" role="dialog" aria-modal="true" aria-labelledby="sucursalModalTitle">
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-head-copy">
                <h2 id="sucursalModalTitle"><?php echo $modalMode === 'edit' ? 'Editar sucursal' : 'Nueva sucursal'; ?></h2>
                <p><?php echo $modalMode === 'edit' ? 'Actualiza los datos principales de la sede seleccionada.' : 'Crea una sede operativa nueva sin salir del listado.'; ?></p>
            </div>
            <button type="button" class="modal-close" id="closeSucursalModal" aria-label="Cerrar">&times;</button>
        </div>

        <?php if ($formError): ?>
            <div class="form-alert form-alert-error"><?php echo htmlspecialchars($formError); ?></div>
        <?php endif; ?>

        <form id="sucursalModalForm" action="sucursales.php<?php echo $paginaActual > 1 ? '?pagina=' . $paginaActual : ''; ?>" method="post" class="sucursal-form">
            <input type="hidden" name="action" id="modalAction" value="<?php echo $modalMode === 'edit' ? 'update_sucursal' : 'create_sucursal'; ?>">
            <input type="hidden" name="id" id="modalSucursalId" value="<?php echo (int)$formData['id']; ?>">

            <div class="form-row">
                <label for="modalNombre">Nombre de la sucursal</label>
                <div class="field-stack">
                    <input type="text" id="modalNombre" name="nombre" required autocomplete="off" value="<?php echo htmlspecialchars($formData['nombre']); ?>">
                    <div class="field-message-slot">
                        <span class="field-message field-error" id="nombre-error">Este nombre ya esta registrado.</span>
                        <span class="field-message field-valid" id="nombre-valid">Nombre disponible.</span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <label for="modalUbicacion">Ubicacion / direccion</label>
                <textarea id="modalUbicacion" name="ubicacion" required rows="4"><?php echo htmlspecialchars($formData['ubicacion']); ?></textarea>
            </div>

            <div class="form-actions">
                <button type="button" class="toolbar-button toolbar-button-secondary" id="cancelSucursalModal">
                    <span>Cancelar</span>
                </button>
                <button type="submit" class="toolbar-button toolbar-button-primary">
                    <img src="../../imagenes/Save.png" alt="" aria-hidden="true" class="toolbar-icon">
                    <span id="modalSubmitLabel"><?php echo $modalMode === 'edit' ? 'Guardar cambios' : 'Guardar'; ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="deleteModalBackdrop"></div>
<div class="modal-shell modal-shell-compact" id="deleteModal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="modal-card modal-card-compact">
        <div class="modal-head modal-head-compact">
            <div class="modal-head-copy">
                <h2 id="deleteModalTitle">Eliminar sucursal</h2>
                <p id="deleteModalMessage">¿Seguro que quieres eliminar esta sucursal?</p>
            </div>
            <button type="button" class="modal-close" id="closeDeleteModal" aria-label="Cerrar">&times;</button>
        </div>
        <div class="confirm-actions">
            <button type="button" class="toolbar-button toolbar-button-secondary" id="cancelDeleteModal">
                <span>Cancelar</span>
            </button>
            <a href="#" class="toolbar-button toolbar-button-danger" id="confirmDeleteLink">
                <span>Eliminar</span>
            </a>
        </div>
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
const toastPayload = <?php echo json_encode($toastPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

const sucursalModal = document.getElementById('sucursalModal');
const sucursalModalBackdrop = document.getElementById('sucursalModalBackdrop');
const openCreateModal = document.getElementById('openCreateModal');
const closeSucursalModal = document.getElementById('closeSucursalModal');
const cancelSucursalModal = document.getElementById('cancelSucursalModal');
const modalTitle = document.getElementById('sucursalModalTitle');
const modalAction = document.getElementById('modalAction');
const modalSucursalId = document.getElementById('modalSucursalId');
const modalNombre = document.getElementById('modalNombre');
const modalUbicacion = document.getElementById('modalUbicacion');
const modalSubmitLabel = document.getElementById('modalSubmitLabel');
const editButtons = document.querySelectorAll('.open-edit-modal');
const deleteButtons = document.querySelectorAll('.action-pill-delete[href*="eliminar_sucursal.php"]');
const nombreInput = document.getElementById('modalNombre');
const errorSpan = document.getElementById('nombre-error');
const validSpan = document.getElementById('nombre-valid');
const deleteModal = document.getElementById('deleteModal');
const deleteModalBackdrop = document.getElementById('deleteModalBackdrop');
const closeDeleteModal = document.getElementById('closeDeleteModal');
const cancelDeleteModal = document.getElementById('cancelDeleteModal');
const confirmDeleteLink = document.getElementById('confirmDeleteLink');
const deleteModalMessage = document.getElementById('deleteModalMessage');

function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container || !message) return;
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 280);
    }, 3600);
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

function syncThemeToggle() {
    const theme = document.documentElement.getAttribute('data-theme') || 'light';
    themeToggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    if (sidebarThemeToggle) sidebarThemeToggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
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
        hideModal();
        hideDeleteModal();
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

function resetValidationState() {
    nombreInput.classList.remove('error', 'valid');
    errorSpan.classList.remove('show');
    validSpan.classList.remove('show', 'fade-out');
}

function openModal(mode, payload = {}) {
    modalTitle.textContent = mode === 'edit' ? 'Editar sucursal' : 'Nueva sucursal';
    modalAction.value = mode === 'edit' ? 'update_sucursal' : 'create_sucursal';
    modalSubmitLabel.textContent = mode === 'edit' ? 'Guardar cambios' : 'Guardar';
    modalSucursalId.value = payload.id || '';
    modalNombre.value = payload.nombre || '';
    modalUbicacion.value = payload.ubicacion || '';
    resetValidationState();
    sucursalModal.classList.add('show');
    sucursalModalBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
    setTimeout(() => modalNombre.focus(), 40);
}

function hideModal() {
    sucursalModal.classList.remove('show');
    sucursalModalBackdrop.classList.remove('show');
    if (!deleteModal.classList.contains('show')) {
        document.body.classList.remove('modal-open');
    }
}

function openDeleteModal(href, nombre) {
    deleteModalMessage.textContent = `¿Seguro que quieres eliminar la sucursal ${nombre}?`;
    confirmDeleteLink.href = href;
    deleteModal.classList.add('show');
    deleteModalBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
}

function hideDeleteModal() {
    deleteModal.classList.remove('show');
    deleteModalBackdrop.classList.remove('show');
    confirmDeleteLink.href = '#';
    if (!sucursalModal.classList.contains('show')) {
        document.body.classList.remove('modal-open');
    }
}

if (openCreateModal) {
    openCreateModal.addEventListener('click', function () {
        openModal('create');
    });
}

editButtons.forEach(function (button) {
    button.addEventListener('click', function () {
        openModal('edit', {
            id: button.dataset.id,
            nombre: button.dataset.nombre,
            ubicacion: button.dataset.ubicacion
        });
    });
});

deleteButtons.forEach(function (button) {
    button.removeAttribute('onclick');
    button.addEventListener('click', function (event) {
        event.preventDefault();
        const nombre = button.closest('tr')?.querySelector('.table-primary')?.textContent?.trim() || 'seleccionada';
        openDeleteModal(button.getAttribute('href'), nombre);
    });
});

if (closeSucursalModal) closeSucursalModal.addEventListener('click', hideModal);
if (cancelSucursalModal) cancelSucursalModal.addEventListener('click', hideModal);
if (sucursalModalBackdrop) sucursalModalBackdrop.addEventListener('click', hideModal);
if (closeDeleteModal) closeDeleteModal.addEventListener('click', hideDeleteModal);
if (cancelDeleteModal) cancelDeleteModal.addEventListener('click', hideDeleteModal);
if (deleteModalBackdrop) deleteModalBackdrop.addEventListener('click', hideDeleteModal);

nombreInput.addEventListener('input', function () {
    const nombre = this.value.trim();
    const currentId = modalAction.value === 'update_sucursal' ? (modalSucursalId.value || '0') : '0';
    this.classList.remove('error', 'valid');
    errorSpan.classList.remove('show');
    validSpan.classList.remove('show', 'fade-out');
    if (!nombre) return;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'verificar_sucursal.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        if (xhr.status === 200) {
            if (xhr.responseText === 'exists') {
                nombreInput.classList.add('error');
                errorSpan.classList.add('show');
            } else if (xhr.responseText === 'available') {
                nombreInput.classList.add('valid');
                validSpan.classList.add('show');
                setTimeout(() => validSpan.classList.add('fade-out'), 1800);
                setTimeout(() => validSpan.classList.remove('show', 'fade-out'), 2800);
            }
        }
    };
    xhr.send('nombre=' + encodeURIComponent(nombre) + '&id=' + encodeURIComponent(currentId));
});

if (toastPayload && toastPayload.text) {
    document.addEventListener('DOMContentLoaded', function () {
        showToast(toastPayload.text, toastPayload.type || 'success');
    });
}

<?php if ($modalOpen): ?>
document.addEventListener('DOMContentLoaded', function () {
    openModal('<?php echo $modalMode; ?>', {
        id: '<?php echo (int)$formData['id']; ?>',
        nombre: <?php echo json_encode($formData['nombre'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        ubicacion: <?php echo json_encode($formData['ubicacion'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    });
});
<?php endif; ?>
</script>
</body>
</html>
