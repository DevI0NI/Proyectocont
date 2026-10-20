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

$modalMode = 'create';
$modalOpen = false;
$formError = '';
$formData = [
    'id' => 0,
    'nombre' => '',
    'descripcion' => '',
    'modelos' => [
        ['id' => 0, 'nombre' => '']
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $modalMode = $action === 'update_type' ? 'edit' : 'create';
    $modalOpen = true;

    $formData['id'] = (int)($_POST['id'] ?? 0);
    $formData['nombre'] = trim($_POST['nombre'] ?? '');
    $formData['descripcion'] = trim($_POST['descripcion'] ?? '');

    $postedModelIds = $_POST['modelos_ids'] ?? [];
    $postedModelNames = $_POST['modelos_nombres'] ?? [];
    $formData['modelos'] = [];

    foreach ($postedModelNames as $index => $modeloNombre) {
        $modeloNombre = trim((string)$modeloNombre);
        $modeloId = isset($postedModelIds[$index]) ? (int)$postedModelIds[$index] : 0;
        if ($modeloNombre === '') {
            continue;
        }
        $formData['modelos'][] = ['id' => $modeloId, 'nombre' => $modeloNombre];
    }

    if (empty($formData['modelos'])) {
        $formData['modelos'][] = ['id' => 0, 'nombre' => ''];
    }

    try {
        if ($formData['nombre'] === '') {
            throw new Exception('El nombre del tipo de unidad no puede ir vacio.');
        }

        if ($action === 'create_type') {
            $stmtCheck = $pdo->prepare('SELECT id, estado_id FROM tipos_unidad WHERE nombre = :nombre');
            $stmtCheck->execute(['nombre' => $formData['nombre']]);
            $existente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existente) {
                if ((int)$existente['estado_id'] === 1) {
                    throw new Exception('Ese tipo de unidad ya existe.');
                }
                throw new Exception('Ese tipo de unidad existe pero esta deshabilitado. Contacta al administrador si deseas reactivarlo.');
            }

            $pdo->beginTransaction();

            $stmtInsert = $pdo->prepare('INSERT INTO tipos_unidad (nombre, descripcion, estado_id) VALUES (:nombre, :descripcion, 1)');
            $stmtInsert->execute([
                'nombre' => $formData['nombre'],
                'descripcion' => $formData['descripcion'],
            ]);

            $tipoUnidadId = (int)$pdo->lastInsertId();

            $stmtModelo = $pdo->prepare('INSERT INTO modelos (nombre, tipo_unidad_id, estado_id) VALUES (:nombre, :tipo_unidad_id, 1)');
            foreach ($formData['modelos'] as $modelo) {
                if (trim($modelo['nombre']) === '') continue;
                $stmtModelo->execute([
                    'nombre' => trim($modelo['nombre']),
                    'tipo_unidad_id' => $tipoUnidadId,
                ]);
            }

            $pdo->commit();
            $_SESSION['toast'] = ['type' => 'success', 'text' => 'Tipo de unidad creado correctamente.'];
            header('Location: tipos_unidad.php');
            exit;
        }

        if ($action === 'update_type') {
            if ($formData['id'] <= 0) {
                throw new Exception('No se encontro el tipo de unidad que intentas modificar.');
            }

            $stmtTipo = $pdo->prepare('SELECT id FROM tipos_unidad WHERE id = :id AND estado_id = 1');
            $stmtTipo->execute(['id' => $formData['id']]);
            if (!$stmtTipo->fetchColumn()) {
                throw new Exception('El tipo de unidad no existe o esta inactivo.');
            }

            $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM tipos_unidad WHERE nombre = :nombre AND id != :id');
            $stmtCheck->execute([
                'nombre' => $formData['nombre'],
                'id' => $formData['id'],
            ]);
            if ((int)$stmtCheck->fetchColumn() > 0) {
                throw new Exception('El nombre ya esta en uso por otro registro.');
            }

            $stmtModelosDb = $pdo->prepare('SELECT id FROM modelos WHERE tipo_unidad_id = :id AND estado_id = 1');
            $stmtModelosDb->execute(['id' => $formData['id']]);
            $idsEnBD = array_map('intval', $stmtModelosDb->fetchAll(PDO::FETCH_COLUMN));

            $pdo->beginTransaction();

            $stmtUpdate = $pdo->prepare('UPDATE tipos_unidad SET nombre = :nombre, descripcion = :descripcion WHERE id = :id');
            $stmtUpdate->execute([
                'nombre' => $formData['nombre'],
                'descripcion' => $formData['descripcion'],
                'id' => $formData['id'],
            ]);

            $idsRecibidos = [];
            $stmtUpdMod = $pdo->prepare('UPDATE modelos SET nombre = :nombre WHERE id = :id');
            $stmtInsMod = $pdo->prepare('INSERT INTO modelos (nombre, tipo_unidad_id, estado_id) VALUES (:nombre, :tipo_unidad_id, 1)');

            foreach ($formData['modelos'] as $modelo) {
                $modeloNombre = trim($modelo['nombre']);
                $modeloId = (int)$modelo['id'];
                if ($modeloNombre === '') continue;

                if ($modeloId > 0) {
                    $stmtUpdMod->execute([
                        'nombre' => $modeloNombre,
                        'id' => $modeloId,
                    ]);
                    $idsRecibidos[] = $modeloId;
                } else {
                    $stmtInsMod->execute([
                        'nombre' => $modeloNombre,
                        'tipo_unidad_id' => $formData['id'],
                    ]);
                }
            }

            $idsParaBorrar = array_diff($idsEnBD, $idsRecibidos);
            if (!empty($idsParaBorrar)) {
                $stmtSoftDelete = $pdo->prepare('UPDATE modelos SET estado_id = 2 WHERE id = :id');
                foreach ($idsParaBorrar as $delId) {
                    $stmtSoftDelete->execute(['id' => $delId]);
                }
            }

            $pdo->commit();
            $_SESSION['toast'] = ['type' => 'success', 'text' => 'Cambios guardados correctamente.'];
            header('Location: tipos_unidad.php');
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $formError = $e->getMessage();
    }
}

$tiposPorPagina = 10;
$paginaActual = max(1, (int)($_GET['pagina'] ?? 1));
$inicio = ($paginaActual - 1) * $tiposPorPagina;

$rol_id = (int)($_SESSION['rol_id'] ?? 0);
$sucursal_id = (int)($_SESSION['sucursal_activa'] ?? ($_SESSION['sucursal_usuario'] ?? 0));
$username = $_SESSION['username'] ?? 'Usuario';

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

$sqlTotal = 'SELECT COUNT(*) FROM tipos_unidad WHERE estado_id = 1';
$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute();
$totalTipos = (int)$stmtTotal->fetchColumn();
$totalPaginas = max(1, (int)ceil($totalTipos / $tiposPorPagina));

$sql = '
    SELECT tu.id, tu.nombre, tu.descripcion, COUNT(m.id) AS cantidad_modelos,
           GROUP_CONCAT(m.nombre ORDER BY m.nombre SEPARATOR "||") AS modelos_lista
    FROM tipos_unidad tu
    LEFT JOIN modelos m ON tu.id = m.tipo_unidad_id AND m.estado_id = 1
    WHERE tu.estado_id = 1
    GROUP BY tu.id, tu.nombre, tu.descripcion
    ORDER BY tu.nombre ASC
    LIMIT :inicio, :tiposPorPagina
';
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':inicio', $inicio, PDO::PARAM_INT);
$stmt->bindValue(':tiposPorPagina', $tiposPorPagina, PDO::PARAM_INT);
$stmt->execute();
$tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Tipos de Unidad - Proportsa</title>
    <link rel="stylesheet" href="../../styles/index.css">
    <link rel="stylesheet" href="../../styles/parametros/bloques/bloques.css">
    <link rel="stylesheet" href="../../styles/parametros/tiposunidad/tiposunidad.css">
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

        <main class="dashboard-main bloques-main tipos-main">
            <section class="panel-card bloques-module tipos-module">
                <div class="module-head">
                    <div class="tipos-head">
                        <div class="view-header-copy">
                            <h1>Tipos de unidad</h1>
                            <p>Administra los tipos base que organizan los modelos disponibles en el sistema.</p>
                        </div>
                        <button type="button" class="toolbar-button toolbar-button-primary" id="openCreateModal">
                            <img src="../../imagenes/nuevo.png" alt="" aria-hidden="true" class="toolbar-icon">
                            <span>Nuevo</span>
                        </button>
                    </div>
                </div>

                <div class="bloques-table-panel tipos-table-panel">
                    <div class="table-wrap bloques-table-wrap">
                        <table class="bloques-table tipos-table data-table">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Descripcion</th>
                                    <th>Modelos</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($tipos) > 0): ?>
                                    <?php foreach ($tipos as $tipo): ?>
                                        <?php
                                        $modelosLista = trim((string)($tipo['modelos_lista'] ?? ''));
                                        $modelosActivos = $modelosLista !== '' ? array_values(array_filter(array_map('trim', explode('||', $modelosLista)))) : [];
                                        ?>
                                        <tr>
                                            <td data-label="Nombre">
                                                <div class="table-primary"><?php echo htmlspecialchars($tipo['nombre']); ?></div>
                                            </td>
                                            <td data-label="Descripcion">
                                                <span class="table-muted tipos-description"><?php echo htmlspecialchars(trim((string)($tipo['descripcion'] ?? '')) ?: 'Sin descripcion'); ?></span>
                                            </td>
                                            <td data-label="Modelos">
                                                <span class="table-muted"><?php echo (int)$tipo['cantidad_modelos']; ?></span>
                                            </td>
                                            <td data-label="Acciones">
                                                <div class="table-actions">
                                                    <button
                                                        type="button"
                                                        class="action-pill action-pill-edit open-info-modal"
                                                        data-id="<?php echo (int)$tipo['id']; ?>"
                                                        data-nombre="<?php echo htmlspecialchars($tipo['nombre'], ENT_QUOTES); ?>"
                                                        data-descripcion="<?php echo htmlspecialchars((string)($tipo['descripcion'] ?? ''), ENT_QUOTES); ?>"
                                                        data-modelos="<?php echo htmlspecialchars(json_encode($modelosActivos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>"
                                                    >Informacion</button>
                                                    <button
                                                        type="button"
                                                        class="action-pill action-pill-edit open-edit-modal"
                                                        data-id="<?php echo (int)$tipo['id']; ?>"
                                                        data-nombre="<?php echo htmlspecialchars($tipo['nombre'], ENT_QUOTES); ?>"
                                                        data-descripcion="<?php echo htmlspecialchars((string)($tipo['descripcion'] ?? ''), ENT_QUOTES); ?>"
                                                        data-modelos="<?php echo htmlspecialchars(json_encode($modelosActivos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>"
                                                    >Modificar</button>
                                                    <button
                                                        type="button"
                                                        class="action-pill action-pill-delete open-delete-modal"
                                                        data-href="eliminar_tipo_unidad.php?id=<?php echo (int)$tipo['id']; ?>"
                                                        data-nombre="<?php echo htmlspecialchars($tipo['nombre'], ENT_QUOTES); ?>"
                                                    >Eliminar</button>
                                                    <a href="infounidad.php?id=<?php echo (int)$tipo['id']; ?>" class="action-pill action-pill-edit">Informacion</a>
                                                    <a href="eliminar_tipo_unidad.php?id=<?php echo (int)$tipo['id']; ?>" class="action-pill action-pill-delete" onclick="return confirm('¿Esta seguro de eliminar este tipo de unidad?')">Eliminar</a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4">
                                            <div class="empty-state table-empty">
                                                <h3>Sin tipos registrados</h3>
                                                <p>Crea un tipo nuevo para empezar a organizar los modelos disponibles.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="tipos-footer">
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
                </div>
            </section>
        </main>
    </div>
</div>

<div class="modal-backdrop <?php echo $modalOpen ? 'show' : ''; ?>" id="tipoModalBackdrop"></div>
<div class="modal-shell <?php echo $modalOpen ? 'show' : ''; ?>" id="tipoModal" role="dialog" aria-modal="true" aria-labelledby="tipoModalTitle">
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-head-copy">
                <h2 id="tipoModalTitle"><?php echo $modalMode === 'edit' ? 'Modificar tipo de unidad' : 'Nuevo tipo de unidad'; ?></h2>
                <p id="tipoModalCopy"><?php echo $modalMode === 'edit' ? 'Actualiza la informacion base y los modelos del tipo seleccionado.' : 'Crea un tipo nuevo sin salir del listado.'; ?></p>
            </div>
            <button type="button" class="modal-close" id="closeTipoModal" aria-label="Cerrar">&times;</button>
        </div>

        <?php if ($formError): ?>
            <div class="form-alert form-alert-error"><?php echo htmlspecialchars($formError); ?></div>
        <?php endif; ?>

        <form id="tipoModalForm" action="tipos_unidad.php<?php echo $paginaActual > 1 ? '?pagina=' . $paginaActual : ''; ?>" method="post" class="tipo-form" autocomplete="off">
            <input type="hidden" name="action" id="modalAction" value="<?php echo $modalMode === 'edit' ? 'update_type' : 'create_type'; ?>">
            <input type="hidden" name="id" id="modalTipoId" value="<?php echo (int)$formData['id']; ?>">

            <div class="form-row">
                <label for="modalNombre">Nombre</label>
                <div class="field-stack">
                    <input type="text" id="modalNombre" name="nombre" required autocomplete="off" value="<?php echo htmlspecialchars($formData['nombre']); ?>">
                    <div class="field-message-slot">
                        <span class="field-message field-error" id="nombre-error">Ese tipo de unidad ya existe.</span>
                        <span class="field-message field-valid" id="nombre-valid">Nombre disponible.</span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <label for="modalDescripcion">Descripcion</label>
                <textarea id="modalDescripcion" name="descripcion" rows="4"><?php echo htmlspecialchars($formData['descripcion']); ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-row-inline">
                    <label>Modelos</label>
                    <div class="modelos-controls">
                        <button type="button" class="mini-action" id="addModeloBtn">Agregar</button>
                        <button type="button" class="mini-action mini-action-danger" id="removeModeloBtn">Quitar</button>
                    </div>
                </div>
                <div class="modelos-stack" id="modelosContainer">
                    <?php foreach ($formData['modelos'] as $modelo): ?>
                        <div class="modelo-row">
                            <input type="hidden" name="modelos_ids[]" value="<?php echo (int)$modelo['id']; ?>">
                            <input type="text" name="modelos_nombres[]" value="<?php echo htmlspecialchars($modelo['nombre']); ?>" placeholder="Nombre del modelo">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="toolbar-button toolbar-button-secondary" id="cancelTipoModal">
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

<div class="modal-backdrop" id="infoModalBackdrop"></div>
<div class="modal-shell" id="infoModal" role="dialog" aria-modal="true" aria-labelledby="infoModalTitle">
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-head-copy">
                <h2 id="infoModalTitle">Informacion del tipo</h2>
                <p>Consulta el detalle y los modelos asociados sin salir del listado.</p>
            </div>
            <button type="button" class="modal-close" id="closeInfoModal" aria-label="Cerrar">&times;</button>
        </div>
        <div class="tipo-info">
            <div class="info-block info-block-name">
                <span class="info-label">Nombre</span>
                <p class="info-value info-value-strong" id="infoNombre"></p>
            </div>
            <div class="info-block info-block-description">
                <span class="info-label">Descripcion</span>
                <p class="info-value" id="infoDescripcion"></p>
            </div>
            <div class="info-block info-block-modelos">
                <span class="info-label">Modelos</span>
                <div class="info-modelos" id="infoModelos"></div>
            </div>
        </div>
        <div class="form-actions">
            <button type="button" class="toolbar-button toolbar-button-secondary" id="closeInfoAction">
                <span>Cerrar</span>
            </button>
            <button type="button" class="toolbar-button toolbar-button-primary" id="infoEditAction">
                <span>Modificar</span>
            </button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="deleteModalBackdrop"></div>
<div class="modal-shell modal-shell-compact" id="deleteModal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="modal-card modal-card-compact">
        <div class="modal-head modal-head-compact">
            <div class="modal-head-copy">
                <h2 id="deleteModalTitle">Eliminar tipo de unidad</h2>
                <p id="deleteModalMessage">¿Seguro que quieres eliminar este tipo de unidad?</p>
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
const tipoModal = document.getElementById('tipoModal');
const tipoModalBackdrop = document.getElementById('tipoModalBackdrop');
const openCreateModal = document.getElementById('openCreateModal');
const closeTipoModal = document.getElementById('closeTipoModal');
const cancelTipoModal = document.getElementById('cancelTipoModal');
const modalTitle = document.getElementById('tipoModalTitle');
const modalCopy = document.getElementById('tipoModalCopy');
const modalAction = document.getElementById('modalAction');
const modalTipoId = document.getElementById('modalTipoId');
const modalNombre = document.getElementById('modalNombre');
const modalDescripcion = document.getElementById('modalDescripcion');
const modalSubmitLabel = document.getElementById('modalSubmitLabel');
const nombreError = document.getElementById('nombre-error');
const nombreValid = document.getElementById('nombre-valid');
const modelosContainer = document.getElementById('modelosContainer');
const addModeloBtn = document.getElementById('addModeloBtn');
const removeModeloBtn = document.getElementById('removeModeloBtn');
const infoModal = document.getElementById('infoModal');
const infoModalBackdrop = document.getElementById('infoModalBackdrop');
const closeInfoModal = document.getElementById('closeInfoModal');
const closeInfoAction = document.getElementById('closeInfoAction');
const infoEditAction = document.getElementById('infoEditAction');
const infoNombre = document.getElementById('infoNombre');
const infoDescripcion = document.getElementById('infoDescripcion');
const infoModelos = document.getElementById('infoModelos');
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

function parseModelos(rawValue) {
    try {
        const parsed = JSON.parse(rawValue || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
        return [];
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function resetNombreValidation() {
    modalNombre.classList.remove('error', 'valid');
    nombreError.classList.remove('show');
    nombreValid.classList.remove('show', 'fade-out');
}

function renderModelInputs(modelos = [{ id: 0, nombre: '' }]) {
    modelosContainer.innerHTML = '';
    const safeModelos = modelos.length ? modelos : [{ id: 0, nombre: '' }];
    safeModelos.forEach(function (modelo) {
        const row = document.createElement('div');
        row.className = 'modelo-row';
        row.innerHTML = `
            <input type="hidden" name="modelos_ids[]" value="${Number(modelo.id || 0)}">
            <input type="text" name="modelos_nombres[]" value="${escapeHtml(modelo.nombre || '')}" placeholder="Nombre del modelo">
        `;
        modelosContainer.appendChild(row);
    });
}

function normalizePayloadModelos(modelos = []) {
    return (modelos || []).map(function (nombre) {
        return { id: 0, nombre: nombre };
    });
}

function openTipoModal(mode, payload = {}) {
    modalTitle.textContent = mode === 'edit' ? 'Modificar tipo de unidad' : 'Nuevo tipo de unidad';
    modalCopy.textContent = mode === 'edit'
        ? 'Actualiza la informacion base y los modelos del tipo seleccionado.'
        : 'Crea un tipo nuevo sin salir del listado.';
    modalAction.value = mode === 'edit' ? 'update_type' : 'create_type';
    modalTipoId.value = payload.id || '';
    modalNombre.value = payload.nombre || '';
    modalDescripcion.value = payload.descripcion || '';
    modalSubmitLabel.textContent = mode === 'edit' ? 'Guardar cambios' : 'Guardar';
    renderModelInputs(payload.modelos || [{ id: 0, nombre: '' }]);
    resetNombreValidation();
    tipoModal.classList.add('show');
    tipoModalBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
    setTimeout(() => modalNombre.focus(), 40);
}

function hideTipoModal() {
    tipoModal.classList.remove('show');
    tipoModalBackdrop.classList.remove('show');
    if (!infoModal.classList.contains('show') && !deleteModal.classList.contains('show')) {
        document.body.classList.remove('modal-open');
    }
}

function openInfoModal(payload) {
    infoNombre.textContent = payload.nombre || '';
    infoDescripcion.textContent = payload.descripcion || 'Sin descripcion';
    infoModelos.innerHTML = '';
    const modelos = payload.modelos || [];
    if (!modelos.length) {
        infoModelos.innerHTML = '<span class="empty-inline">Sin modelos registrados</span>';
    } else {
        modelos.forEach(function (modelo) {
            const pill = document.createElement('span');
            pill.className = 'info-modelo-pill';
            pill.textContent = modelo;
            infoModelos.appendChild(pill);
        });
    }
    infoEditAction.dataset.id = payload.id || '';
    infoEditAction.dataset.nombre = payload.nombre || '';
    infoEditAction.dataset.descripcion = payload.descripcion || '';
    infoEditAction.dataset.modelos = JSON.stringify(modelos);
    infoModal.classList.add('show');
    infoModalBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
}

function hideInfoModal() {
    infoModal.classList.remove('show');
    infoModalBackdrop.classList.remove('show');
    if (!tipoModal.classList.contains('show') && !deleteModal.classList.contains('show')) {
        document.body.classList.remove('modal-open');
    }
}

function openDeleteModal(href, nombre) {
    deleteModalMessage.textContent = `¿Seguro que quieres eliminar el tipo ${nombre}?`;
    confirmDeleteLink.href = href;
    deleteModal.classList.add('show');
    deleteModalBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
}

function hideDeleteModal() {
    deleteModal.classList.remove('show');
    deleteModalBackdrop.classList.remove('show');
    confirmDeleteLink.href = '#';
    if (!tipoModal.classList.contains('show') && !infoModal.classList.contains('show')) {
        document.body.classList.remove('modal-open');
    }
}

function bindTipoActions() {
    document.querySelectorAll('.table-actions > a').forEach(function (legacyLink) {
        legacyLink.style.display = 'none';
    });

    document.querySelectorAll('.open-info-modal').forEach(function (button) {
        button.addEventListener('click', function () {
            openInfoModal({
                id: button.dataset.id,
                nombre: button.dataset.nombre,
                descripcion: button.dataset.descripcion,
                modelos: parseModelos(button.dataset.modelos)
            });
        });
    });

    document.querySelectorAll('.open-edit-modal').forEach(function (button) {
        button.addEventListener('click', function () {
            openTipoModal('edit', {
                id: button.dataset.id,
                nombre: button.dataset.nombre,
                descripcion: button.dataset.descripcion,
                modelos: normalizePayloadModelos(parseModelos(button.dataset.modelos))
            });
        });
    });

    document.querySelectorAll('.open-delete-modal').forEach(function (button) {
        button.addEventListener('click', function () {
            openDeleteModal(button.dataset.href, button.dataset.nombre);
        });
    });
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
        hideTipoModal();
        hideInfoModal();
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
bindTipoActions();

if (openCreateModal) {
    openCreateModal.addEventListener('click', function () {
        openTipoModal('create');
    });
}

if (closeTipoModal) closeTipoModal.addEventListener('click', hideTipoModal);
if (cancelTipoModal) cancelTipoModal.addEventListener('click', hideTipoModal);
if (tipoModalBackdrop) tipoModalBackdrop.addEventListener('click', hideTipoModal);
if (closeInfoModal) closeInfoModal.addEventListener('click', hideInfoModal);
if (closeInfoAction) closeInfoAction.addEventListener('click', hideInfoModal);
if (infoModalBackdrop) infoModalBackdrop.addEventListener('click', hideInfoModal);
if (closeDeleteModal) closeDeleteModal.addEventListener('click', hideDeleteModal);
if (cancelDeleteModal) cancelDeleteModal.addEventListener('click', hideDeleteModal);
if (deleteModalBackdrop) deleteModalBackdrop.addEventListener('click', hideDeleteModal);

if (infoEditAction) {
    infoEditAction.addEventListener('click', function () {
        hideInfoModal();
        openTipoModal('edit', {
            id: infoEditAction.dataset.id,
            nombre: infoEditAction.dataset.nombre,
            descripcion: infoEditAction.dataset.descripcion,
            modelos: normalizePayloadModelos(parseModelos(infoEditAction.dataset.modelos))
        });
    });
}

if (addModeloBtn) {
    addModeloBtn.addEventListener('click', function () {
        renderModelInputs([
            ...Array.from(modelosContainer.querySelectorAll('.modelo-row')).map(function (row) {
                return {
                    id: row.querySelector('input[type="hidden"]').value,
                    nombre: row.querySelector('input[type="text"]').value
                };
            }),
            { id: 0, nombre: '' }
        ]);
    });
}

if (removeModeloBtn) {
    removeModeloBtn.addEventListener('click', function () {
        const rows = modelosContainer.querySelectorAll('.modelo-row');
        if (rows.length > 1) {
            rows[rows.length - 1].remove();
        } else if (rows.length === 1) {
            rows[0].querySelector('input[type="hidden"]').value = '0';
            rows[0].querySelector('input[type="text"]').value = '';
        }
    });
}

modalNombre.addEventListener('input', function () {
    const nombre = this.value.trim();
    const currentId = modalAction.value === 'update_type' ? (modalTipoId.value || '0') : '0';
    this.classList.remove('error', 'valid');
    nombreError.classList.remove('show');
    nombreValid.classList.remove('show', 'fade-out');
    if (!nombre) return;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'verificar_nombre.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        if (xhr.status !== 200) return;
        if (xhr.responseText === 'exists') {
            modalNombre.classList.add('error');
            nombreError.classList.add('show');
        } else if (xhr.responseText === 'available') {
            modalNombre.classList.add('valid');
            nombreValid.classList.add('show');
            setTimeout(() => nombreValid.classList.add('fade-out'), 1800);
            setTimeout(() => nombreValid.classList.remove('show', 'fade-out'), 2800);
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
    openTipoModal('<?php echo $modalMode === 'edit' ? 'edit' : 'create'; ?>', {
        id: '<?php echo (int)$formData['id']; ?>',
        nombre: <?php echo json_encode($formData['nombre'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        descripcion: <?php echo json_encode($formData['descripcion'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        modelos: <?php echo json_encode($formData['modelos'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    });
});
<?php endif; ?>
</script>
</body>
</html>
