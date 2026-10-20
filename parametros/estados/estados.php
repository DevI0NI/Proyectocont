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

require '../../config/database.php';

$rol_id = (int)($_SESSION['rol_id'] ?? 0);
$sucursal_id = (int)($_SESSION['sucursal_activa'] ?? ($_SESSION['sucursal_usuario'] ?? 0));
$username = $_SESSION['username'] ?? 'Usuario';

$stmtTipos = $pdo->query("SELECT id, nombre FROM tipos_estatus WHERE estado_id = 1 ORDER BY id ASC");
$tiposDisponibles = $stmtTipos->fetchAll(PDO::FETCH_ASSOC);
$tiposPorNombre = [];
foreach ($tiposDisponibles as $tipoItem) {
    $tiposPorNombre[$tipoItem['nombre']] = (int)$tipoItem['id'];
}

$nombreTipo = (isset($_GET['tipo']) && $_GET['tipo'] === 'Estructura') ? 'Estructura' : 'Unidad';
if (!isset($tiposPorNombre[$nombreTipo])) {
    $nombreTipo = array_key_first($tiposPorNombre) ?: 'Unidad';
}
$tipoIdActual = (int)($tiposPorNombre[$nombreTipo] ?? 1);
$tipoAlterno = $nombreTipo === 'Unidad' ? 'Estructura' : 'Unidad';

$modalMode = 'create';
$modalOpen = false;
$formError = '';
$formData = [
    'id' => 0,
    'tipo_id' => $tipoIdActual,
    'tipo_nombre' => $nombreTipo,
    'nombre' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $modalMode = $action === 'update_status' ? 'edit' : 'create';
    $modalOpen = true;

    $formData['id'] = (int)($_POST['id'] ?? 0);
    $formData['tipo_id'] = (int)($_POST['tipo_estatus_id'] ?? $tipoIdActual);
    $formData['nombre'] = trim($_POST['nombre'] ?? '');

    $stmtTipoForm = $pdo->prepare('SELECT id, nombre FROM tipos_estatus WHERE id = :id AND estado_id = 1');
    $stmtTipoForm->execute(['id' => $formData['tipo_id']]);
    $tipoForm = $stmtTipoForm->fetch(PDO::FETCH_ASSOC);
    if ($tipoForm) {
        $formData['tipo_id'] = (int)$tipoForm['id'];
        $formData['tipo_nombre'] = $tipoForm['nombre'];
    } else {
        $formData['tipo_id'] = $tipoIdActual;
        $formData['tipo_nombre'] = $nombreTipo;
    }

    try {
        if ($formData['nombre'] === '') {
            throw new Exception('El nombre del estatus no puede ir vacio.');
        }

        if ($action === 'create_status') {
            $stmtCheck = $pdo->prepare('
                SELECT id, estado_id
                FROM estatus_contenedores
                WHERE nombre = :nombre AND tipo_estatus_id = :tipo_id
            ');
            $stmtCheck->execute([
                'nombre' => $formData['nombre'],
                'tipo_id' => $formData['tipo_id'],
            ]);
            $existente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existente) {
                if ((int)$existente['estado_id'] === 1) {
                    throw new Exception('Este estatus ya existe para la categoria seleccionada.');
                }

                $stmtReactivate = $pdo->prepare('
                    UPDATE estatus_contenedores
                    SET estado_id = 1
                    WHERE id = :id
                ');
                $stmtReactivate->execute([
                    'id' => (int)$existente['id'],
                ]);

                $_SESSION['toast'] = ['type' => 'success', 'text' => 'Estatus reactivado correctamente.'];
                header('Location: estados.php?tipo=' . urlencode($formData['tipo_nombre']));
                exit;
            }

            $stmtInsert = $pdo->prepare('
                INSERT INTO estatus_contenedores (nombre, tipo_estatus_id, estado_id)
                VALUES (:nombre, :tipo_id, 1)
            ');
            $stmtInsert->execute([
                'nombre' => $formData['nombre'],
                'tipo_id' => $formData['tipo_id'],
            ]);

            $_SESSION['toast'] = ['type' => 'success', 'text' => 'Estatus creado correctamente.'];
            header('Location: estados.php?tipo=' . urlencode($formData['tipo_nombre']));
            exit;
        }

        if ($action === 'update_status') {
            if ($formData['id'] <= 0) {
                throw new Exception('No se encontro el estatus que intentas modificar.');
            }

            $stmtExisting = $pdo->prepare('SELECT id FROM estatus_contenedores WHERE id = :id AND estado_id = 1');
            $stmtExisting->execute(['id' => $formData['id']]);
            if (!$stmtExisting->fetchColumn()) {
                throw new Exception('El estatus no existe o esta inactivo.');
            }

            $stmtCheck = $pdo->prepare('
                SELECT COUNT(*)
                FROM estatus_contenedores
                WHERE nombre = :nombre AND tipo_estatus_id = :tipo_id AND id != :id
            ');
            $stmtCheck->execute([
                'nombre' => $formData['nombre'],
                'tipo_id' => $formData['tipo_id'],
                'id' => $formData['id'],
            ]);

            if ((int)$stmtCheck->fetchColumn() > 0) {
                throw new Exception('Este nombre ya existe en el historial para esta categoria.');
            }

            $stmtUpdate = $pdo->prepare('
                UPDATE estatus_contenedores
                SET nombre = :nombre, tipo_estatus_id = :tipo_id
                WHERE id = :id
            ');
            $stmtUpdate->execute([
                'nombre' => $formData['nombre'],
                'tipo_id' => $formData['tipo_id'],
                'id' => $formData['id'],
            ]);

            $_SESSION['toast'] = ['type' => 'success', 'text' => 'Estatus actualizado correctamente.'];
            header('Location: estados.php?tipo=' . urlencode($formData['tipo_nombre']));
            exit;
        }
    } catch (Exception $e) {
        $formError = $e->getMessage();
    }
}

$estadosPorPagina = 10;
$paginaActual = max(1, (int)($_GET['pagina'] ?? 1));
$inicio = ($paginaActual - 1) * $estadosPorPagina;

$sucursales = [];
$nombreSucursalActiva = 'Desconocida';

if ($rol_id === 1) {
    $stmtSuc = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursales = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
}

if ($sucursal_id > 0) {
    $stmtNomSucursal = $pdo->prepare('SELECT nombre FROM sucursales WHERE id = :id');
    $stmtNomSucursal->execute(['id' => $sucursal_id]);
    $nombreSucursalActiva = $stmtNomSucursal->fetchColumn() ?: 'Desconocida';
}

$sqlTotal = 'SELECT COUNT(*) FROM estatus_contenedores WHERE tipo_estatus_id = :tipo_id AND estado_id = 1';
$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute(['tipo_id' => $tipoIdActual]);
$totalEstados = (int)$stmtTotal->fetchColumn();
$totalPaginas = max(1, (int)ceil($totalEstados / $estadosPorPagina));

$sql = '
    SELECT id, nombre
    FROM estatus_contenedores
    WHERE tipo_estatus_id = :tipo_id AND estado_id = 1
    ORDER BY nombre ASC
    LIMIT :inicio, :estadosPorPagina
';
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':tipo_id', $tipoIdActual, PDO::PARAM_INT);
$stmt->bindValue(':inicio', $inicio, PDO::PARAM_INT);
$stmt->bindValue(':estadosPorPagina', $estadosPorPagina, PDO::PARAM_INT);
$stmt->execute();
$estados = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Estatus de Contenedores - Proportsa</title>
    <link rel="stylesheet" href="../../styles/index.css">
    <link rel="stylesheet" href="../../styles/parametros/bloques/bloques.css">
    <link rel="stylesheet" href="../../styles/parametros/estados/estados.css">
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
                        <a href="../../logout.php" class="user-menu-item user-menu-logout">Cerrar sesion</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="dashboard-main bloques-main estados-main">
            <section class="panel-card bloques-module estados-module">
                <div class="module-head">
                    <div class="estados-head">
                        <div class="view-header-copy">
                            <h1>Estatus de Contenedores - <?php echo htmlspecialchars($nombreTipo); ?></h1>
                            <p>Administra los estados operativos disponibles para la categoria seleccionada.</p>
                        </div>
                        <div class="estados-toolbar">
                            <a href="?tipo=<?php echo urlencode($tipoAlterno); ?>" class="toolbar-button toolbar-button-secondary switch-button">
                                <img src="../../imagenes/cambiar.png" alt="" aria-hidden="true" class="toolbar-icon">
                                <span>Cambiar a <?php echo htmlspecialchars($tipoAlterno); ?></span>
                            </a>
                            <button type="button" class="toolbar-button toolbar-button-primary" id="openCreateModal">
                                <img src="../../imagenes/nuevo.png" alt="" aria-hidden="true" class="toolbar-icon">
                                <span>Nuevo</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="bloques-table-panel estados-table-panel">
                    <div class="table-wrap bloques-table-wrap">
                        <table class="bloques-table estados-table data-table">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($estados) > 0): ?>
                                    <?php foreach ($estados as $estado): ?>
                                        <tr>
                                            <td data-label="Nombre">
                                                <div class="table-primary"><?php echo htmlspecialchars($estado['nombre']); ?></div>
                                            </td>
                                            <td data-label="Acciones">
                                                <div class="table-actions">
                                                    <button
                                                        type="button"
                                                        class="action-pill action-pill-edit open-edit-modal"
                                                        data-id="<?php echo (int)$estado['id']; ?>"
                                                        data-nombre="<?php echo htmlspecialchars($estado['nombre'], ENT_QUOTES); ?>"
                                                        data-tipo-id="<?php echo (int)$tipoIdActual; ?>"
                                                        data-tipo-nombre="<?php echo htmlspecialchars($nombreTipo, ENT_QUOTES); ?>"
                                                    >Modificar</button>
                                                    <button
                                                        type="button"
                                                        class="action-pill action-pill-delete open-delete-modal"
                                                        data-href="eliminar_estatus.php?id=<?php echo (int)$estado['id']; ?>"
                                                        data-nombre="<?php echo htmlspecialchars($estado['nombre'], ENT_QUOTES); ?>"
                                                    >Eliminar</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2">
                                            <div class="empty-state table-empty">
                                                <h3>Sin estatus registrados</h3>
                                                <p>Crea un estatus nuevo para empezar a organizar esta categoria.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="estados-footer">
                        <?php if ($totalPaginas > 1): ?>
                            <div class="pagination">
                                <?php if ($paginaActual > 1): ?>
                                    <a href="?tipo=<?php echo urlencode($nombreTipo); ?>&pagina=<?php echo $paginaActual - 1; ?>" class="pagination-button">Anterior</a>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                    <a href="?tipo=<?php echo urlencode($nombreTipo); ?>&pagina=<?php echo $i; ?>" class="pagination-button <?php echo ($i === $paginaActual) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>

                                <?php if ($paginaActual < $totalPaginas): ?>
                                    <a href="?tipo=<?php echo urlencode($nombreTipo); ?>&pagina=<?php echo $paginaActual + 1; ?>" class="pagination-button">Siguiente</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>

<div class="modal-backdrop <?php echo $modalOpen ? 'show' : ''; ?>" id="estadoModalBackdrop"></div>
<div class="modal-shell <?php echo $modalOpen ? 'show' : ''; ?>" id="estadoModal" role="dialog" aria-modal="true" aria-labelledby="estadoModalTitle">
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-head-copy">
                <h2 id="estadoModalTitle"><?php echo $modalMode === 'edit' ? 'Modificar estatus' : 'Nuevo estatus'; ?></h2>
                <p id="estadoModalCopy"><?php echo $modalMode === 'edit' ? 'Actualiza el nombre del estatus dentro de su categoria.' : 'Crea un estatus nuevo sin salir del listado.'; ?></p>
            </div>
            <button type="button" class="modal-close" id="closeEstadoModal" aria-label="Cerrar">&times;</button>
        </div>

        <?php if ($formError): ?>
            <div class="form-alert form-alert-error"><?php echo htmlspecialchars($formError); ?></div>
        <?php endif; ?>

        <form id="estadoModalForm" action="estados.php?tipo=<?php echo urlencode($nombreTipo); ?><?php echo $paginaActual > 1 ? '&pagina=' . $paginaActual : ''; ?>" method="post" class="estado-form" autocomplete="off">
            <input type="hidden" name="action" id="modalAction" value="<?php echo $modalMode === 'edit' ? 'update_status' : 'create_status'; ?>">
            <input type="hidden" name="id" id="modalEstadoId" value="<?php echo (int)$formData['id']; ?>">
            <input type="hidden" name="tipo_estatus_id" id="modalTipoId" value="<?php echo (int)$formData['tipo_id']; ?>">

            <div class="form-row">
                <label>Categoria</label>
                <div class="readonly-pill" id="modalTipoNombre"><?php echo htmlspecialchars($formData['tipo_nombre']); ?></div>
            </div>

            <div class="form-row">
                <label for="modalNombre">Nombre</label>
                <div class="field-stack">
                    <input type="text" id="modalNombre" name="nombre" required autocomplete="off" value="<?php echo htmlspecialchars($formData['nombre']); ?>">
                    <div class="field-message-slot">
                        <span class="field-message field-error" id="nombre-error">Ese estatus ya existe.</span>
                        <span class="field-message field-valid" id="nombre-valid">Nombre disponible.</span>
                        <span class="field-message field-valid" id="nombre-inactive">Existe en el historial. Se reactivara al guardar.</span>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="toolbar-button toolbar-button-secondary" id="cancelEstadoModal">
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
                <h2 id="deleteModalTitle">Eliminar estatus</h2>
                <p id="deleteModalMessage">¿Seguro que quieres eliminar este estatus?</p>
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
const toastPayload = <?php echo json_encode($toastPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const currentTipoId = <?php echo (int)$tipoIdActual; ?>;
const currentTipoNombre = <?php echo json_encode($nombreTipo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

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
const mobileQuery = window.matchMedia('(max-width: 980px)');

const estadoModal = document.getElementById('estadoModal');
const estadoModalBackdrop = document.getElementById('estadoModalBackdrop');
const openCreateModal = document.getElementById('openCreateModal');
const closeEstadoModal = document.getElementById('closeEstadoModal');
const cancelEstadoModal = document.getElementById('cancelEstadoModal');
const modalTitle = document.getElementById('estadoModalTitle');
const modalCopy = document.getElementById('estadoModalCopy');
const modalAction = document.getElementById('modalAction');
const modalEstadoId = document.getElementById('modalEstadoId');
const modalTipoId = document.getElementById('modalTipoId');
const modalTipoNombre = document.getElementById('modalTipoNombre');
const modalNombre = document.getElementById('modalNombre');
const modalSubmitLabel = document.getElementById('modalSubmitLabel');
const nombreError = document.getElementById('nombre-error');
const nombreValid = document.getElementById('nombre-valid');
const nombreInactive = document.getElementById('nombre-inactive');

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

function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('proportsa-theme', theme);
}

function toggleTheme() {
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

function resetValidation() {
    modalNombre.classList.remove('error', 'valid');
    nombreError.classList.remove('show');
    nombreValid.classList.remove('show', 'fade-out');
    nombreInactive.classList.remove('show');
}

function openEstadoModal(mode, payload = {}) {
    modalTitle.textContent = mode === 'edit' ? 'Modificar estatus' : 'Nuevo estatus';
    modalCopy.textContent = mode === 'edit'
        ? 'Actualiza el nombre del estatus dentro de su categoria.'
        : 'Crea un estatus nuevo sin salir del listado.';
    modalAction.value = mode === 'edit' ? 'update_status' : 'create_status';
    modalEstadoId.value = payload.id || '';
    modalTipoId.value = payload.tipoId || currentTipoId;
    modalTipoNombre.textContent = payload.tipoNombre || currentTipoNombre;
    modalNombre.value = payload.nombre || '';
    modalSubmitLabel.textContent = mode === 'edit' ? 'Guardar cambios' : 'Guardar';
    resetValidation();
    estadoModal.classList.add('show');
    estadoModalBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
    setTimeout(() => modalNombre.focus(), 40);
}

function hideEstadoModal() {
    estadoModal.classList.remove('show');
    estadoModalBackdrop.classList.remove('show');
    if (!deleteModal.classList.contains('show')) {
        document.body.classList.remove('modal-open');
    }
}

function openDeleteModal(href, nombre) {
    deleteModalMessage.textContent = `¿Seguro que quieres eliminar el estatus ${nombre}?`;
    confirmDeleteLink.href = href;
    deleteModal.classList.add('show');
    deleteModalBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
}

function hideDeleteModal() {
    deleteModal.classList.remove('show');
    deleteModalBackdrop.classList.remove('show');
    confirmDeleteLink.href = '#';
    if (!estadoModal.classList.contains('show')) {
        document.body.classList.remove('modal-open');
    }
}

function validateNombre() {
    const nombre = modalNombre.value.trim();
    const tipoNombre = modalTipoNombre.textContent.trim();
    const id = modalEstadoId.value || 0;

    resetValidation();
    if (!nombre || !tipoNombre) return;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'verificar_nombre_estatus.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        if (xhr.status !== 200) return;
        if (xhr.responseText === 'exists') {
            modalNombre.classList.add('error');
            nombreError.classList.add('show');
            return;
        }
        if (xhr.responseText === 'inactive') {
            modalNombre.classList.add('valid');
            nombreInactive.classList.add('show');
            return;
        }
        if (xhr.responseText === 'available') {
            modalNombre.classList.add('valid');
            nombreValid.classList.add('show');
            nombreValid.classList.remove('fade-out');
            setTimeout(() => nombreValid.classList.add('fade-out'), 1800);
        }
    };
    xhr.send(
        'nombre=' + encodeURIComponent(nombre) +
        '&tipo=' + encodeURIComponent(tipoNombre) +
        '&id=' + encodeURIComponent(id)
    );
}

function bindActions() {
    document.querySelectorAll('.open-edit-modal').forEach((button) => {
        button.addEventListener('click', function () {
            openEstadoModal('edit', {
                id: button.dataset.id,
                nombre: button.dataset.nombre,
                tipoId: button.dataset.tipoId,
                tipoNombre: button.dataset.tipoNombre
            });
        });
    });

    document.querySelectorAll('.open-delete-modal').forEach((button) => {
        button.addEventListener('click', function () {
            openDeleteModal(button.dataset.href, button.dataset.nombre);
        });
    });
}

if (toastPayload && toastPayload.text) {
    window.addEventListener('DOMContentLoaded', function () {
        showToast(toastPayload.text, toastPayload.type || 'success');
    });
}

themeToggle?.addEventListener('click', toggleTheme);
sidebarThemeToggle?.addEventListener('click', toggleTheme);

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
        if (document.body.classList.contains('sidebar-open')) {
            setSidebarOpen(false);
        } else {
            setSidebarOpen(true);
        }
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

openCreateModal?.addEventListener('click', function () {
    openEstadoModal('create', {
        tipoId: currentTipoId,
        tipoNombre: currentTipoNombre
    });
});

closeEstadoModal?.addEventListener('click', hideEstadoModal);
cancelEstadoModal?.addEventListener('click', hideEstadoModal);
estadoModalBackdrop?.addEventListener('click', hideEstadoModal);
closeDeleteModal?.addEventListener('click', hideDeleteModal);
cancelDeleteModal?.addEventListener('click', hideDeleteModal);
deleteModalBackdrop?.addEventListener('click', hideDeleteModal);
modalNombre?.addEventListener('input', validateNombre);

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        if (deleteModal.classList.contains('show')) {
            hideDeleteModal();
        } else if (estadoModal.classList.contains('show')) {
            hideEstadoModal();
        }
    }
});

bindActions();
</script>
</body>
</html>
