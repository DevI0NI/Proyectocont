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

$sucursal_id = (int)$_SESSION['sucursal_activa'];
$usuario_id = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Usuario';
$rol_id = (int)($_SESSION['rol_id'] ?? 0);

date_default_timezone_set('America/Guatemala');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../config/database.php';

if (in_array($rol_id, [4, 5], true)) {
    header('Location: contenedor.php');
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

// =======================================================
// 0. CAPTURAR REFERENCIAS
// =======================================================
$ref_patio = isset($_GET['ref_patio']) ? $_GET['ref_patio'] : '';
$ref_bloque = isset($_GET['ref_bloque']) ? $_GET['ref_bloque'] : '';

// =======================================================
// 1. CARGAR DATOS ACTUALES (GET)
// =======================================================
$contenedor = null;
$modelos = [];

if (isset($_GET['nombre'])) {
    $nombreContenedor = $_GET['nombre'];

    $stmt = $pdo->prepare('
        SELECT c.*,
               t.nombre as tipo_nombre,
               m.nombre as modelo_nombre,
               eu.nombre as estatus_unidad_nombre,
               eb.nombre as estatus_box_nombre,
               ei.nombre as estatus_ipsa_nombre
        FROM contenedores c
        LEFT JOIN tipos_unidad t ON c.tipo_unidad_id = t.id
        LEFT JOIN modelos m ON c.modelo_id = m.id
        LEFT JOIN estatus_contenedores eu ON c.estatus_unidad_id = eu.id
        LEFT JOIN estatus_contenedores eb ON c.estatus_box_id = eb.id
        LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id
        JOIN patios p ON c.bloque_id = (SELECT id FROM bloques WHERE id = c.bloque_id)
                      AND p.id = (SELECT patio_id FROM bloques WHERE id = c.bloque_id)
        WHERE c.nombre = :nombre
          AND p.sucursal_id = :sucursal
          AND c.estado_id = 1
    ');
    $stmt->execute(['nombre' => $nombreContenedor, 'sucursal' => $sucursal_id]);
    $contenedor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contenedor) {
        $_SESSION['toast_message'] = 'Contenedor no encontrado.';
        $_SESSION['toast_type'] = 'error';
        header('Location: bloques.php');
        exit;
    }

    if (!empty($contenedor['tipo_unidad_id'])) {
        $stmtMod = $pdo->prepare('SELECT id, nombre FROM modelos WHERE tipo_unidad_id = ? AND estado_id = 1');
        $stmtMod->execute([$contenedor['tipo_unidad_id']]);
        $modelos = $stmtMod->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    header('Location: bloques.php');
    exit;
}

// =======================================================
// 2. CARGAR CATALOGOS
// =======================================================
$stmtTipos = $pdo->query('SELECT id, nombre FROM tipos_unidad WHERE estado_id = 1 ORDER BY nombre');
$tiposMap = $stmtTipos->fetchAll(PDO::FETCH_KEY_PAIR);

$stmtCat = $pdo->prepare('SELECT id FROM tipos_estatus WHERE nombre = :nombre');
$stmtCat->execute(['nombre' => 'Unidad']);
$idCatUnidad = $stmtCat->fetchColumn();

$stmtCat->execute(['nombre' => 'Estructura']);
$idCatBox = $stmtCat->fetchColumn();

$stmtEstU = $pdo->prepare('SELECT id, nombre FROM estatus_contenedores WHERE tipo_estatus_id = ? AND estado_id = 1 ORDER BY nombre');
$stmtEstU->execute([$idCatUnidad]);
$estadosUnidad = $stmtEstU->fetchAll(PDO::FETCH_ASSOC);
$estUnitMap = [];
foreach ($estadosUnidad as $e) {
    $estUnitMap[$e['id']] = $e['nombre'];
}

$stmtEstB = $pdo->prepare('SELECT id, nombre FROM estatus_contenedores WHERE tipo_estatus_id = ? AND estado_id = 1 ORDER BY nombre');
$stmtEstB->execute([$idCatBox]);
$statusBox = $stmtEstB->fetchAll(PDO::FETCH_ASSOC);
$estBoxMap = [];
foreach ($statusBox as $e) {
    $estBoxMap[$e['id']] = $e['nombre'];
}

$stmtIpsa = $pdo->query('SELECT id, nombre FROM estatus_ipsa WHERE estado_id = 1 ORDER BY nombre');
$listaIpsa = $stmtIpsa->fetchAll(PDO::FETCH_ASSOC);
$ipsaMap = [];
foreach ($listaIpsa as $i) {
    $ipsaMap[$i['id']] = $i['nombre'];
}

// =======================================================
// 3. PROCESAR FORMULARIO (POST)
// =======================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nuevoNombre = substr(trim(strtoupper($_POST['codigo_contenedor'])), 0, 11);
    $nuevoTipo = (int)$_POST['tipo_unidad'];
    $nuevoModelo = (int)$_POST['modelo'];
    $nuevoEstUni = (int)$_POST['estado_unidad'];
    $nuevaObsUni = trim($_POST['observaciones_estado']);
    $nuevoEstBox = (int)$_POST['status_box'];
    $nuevaObsBox = trim($_POST['observaciones_box']);
    $nuevoIpsa = (int)$_POST['estatus_ipsa'];

    $postRefPatio = $_POST['ref_patio'] ?? '';
    $postRefBloque = $_POST['ref_bloque'] ?? '';
    $idContenedor = $contenedor['id'];

    try {
        $pdo->beginTransaction();

        if ($nuevoNombre !== $contenedor['nombre']) {
            $stmtDup = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE nombre = :nombre AND id != :id');
            $stmtDup->execute(['nombre' => $nuevoNombre, 'id' => $idContenedor]);
            if ($stmtDup->fetchColumn() > 0) {
                throw new Exception('Ya existe otro contenedor con ese nombre.');
            }
        }

        $sqlUpd = '
            UPDATE contenedores SET
                nombre = :nom, tipo_unidad_id = :tip, modelo_id = :mod,
                estatus_unidad_id = :e_uni, observaciones_unidad = :o_uni,
                estatus_box_id = :e_box, observaciones_box = :o_box,
                estatus_ipsa_id = :ipsa
            WHERE id = :id
        ';
        $stmtUpd = $pdo->prepare($sqlUpd);
        $stmtUpd->execute([
            'nom' => $nuevoNombre, 'tip' => $nuevoTipo, 'mod' => $nuevoModelo,
            'e_uni' => $nuevoEstUni, 'o_uni' => $nuevaObsUni,
            'e_box' => $nuevoEstBox, 'o_box' => $nuevaObsBox,
            'ipsa' => $nuevoIpsa, 'id' => $idContenedor
        ]);

        $opModDatos = 4;
        $opModEstatus = 3;

        function registrarEventoLog($pdo, $cid, $uid, $op, $obs) {
            $stmt = $pdo->prepare('INSERT INTO logs_eventos (contenedor_id, usuario_id, tipo_operacion_id, observaciones, estado_id) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$cid, $uid, $op, $obs]);
            return $pdo->lastInsertId();
        }

        if ($contenedor['nombre'] !== $nuevoNombre) {
            $eid = registrarEventoLog($pdo, $idContenedor, $usuario_id, $opModDatos, 'Cambio de Nombre');
            $stmtDet = $pdo->prepare('INSERT INTO logs_modificaciones (evento_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, 'Nombre', $contenedor['nombre'], $nuevoNombre]);
        }

        if ($contenedor['tipo_unidad_id'] != $nuevoTipo) {
            $eid = registrarEventoLog($pdo, $idContenedor, $usuario_id, $opModDatos, 'Cambio de Tipo de Unidad');
            $stmtDet = $pdo->prepare('INSERT INTO logs_modificaciones (evento_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, 'Tipo Unidad', $contenedor['tipo_nombre'], $tiposMap[$nuevoTipo] ?? $nuevoTipo]);
        }

        if ($contenedor['modelo_id'] != $nuevoModelo) {
            $stmtM = $pdo->prepare('SELECT nombre FROM modelos WHERE id = ?');
            $stmtM->execute([$nuevoModelo]);
            $nomModNew = $stmtM->fetchColumn();

            $eid = registrarEventoLog($pdo, $idContenedor, $usuario_id, $opModDatos, 'Cambio de Modelo');
            $stmtDet = $pdo->prepare('INSERT INTO logs_modificaciones (evento_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, 'Modelo', $contenedor['modelo_nombre'], $nomModNew]);
        }

        if ($contenedor['observaciones_unidad'] !== $nuevaObsUni) {
            $eid = registrarEventoLog($pdo, $idContenedor, $usuario_id, $opModDatos, 'Actualizacion Obs. Unidad');
            $stmtDet = $pdo->prepare('INSERT INTO logs_modificaciones (evento_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, 'Obs. Unidad', $contenedor['observaciones_unidad'], $nuevaObsUni]);
        }

        if ($contenedor['observaciones_box'] !== $nuevaObsBox) {
            $eid = registrarEventoLog($pdo, $idContenedor, $usuario_id, $opModDatos, 'Actualizacion Obs. Box');
            $stmtDet = $pdo->prepare('INSERT INTO logs_modificaciones (evento_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, 'Obs. Box', $contenedor['observaciones_box'], $nuevaObsBox]);
        }

        $stmtEstLog = $pdo->prepare('INSERT INTO logs_estatus (evento_id, tipo_estatus_id, valor_anterior_id, valor_nuevo_id) VALUES (?, ?, ?, ?)');

        if ($contenedor['estatus_unidad_id'] != $nuevoEstUni) {
            $eid = registrarEventoLog($pdo, $idContenedor, $usuario_id, $opModEstatus, 'Cambio Estatus Unidad');
            $stmtEstLog->execute([$eid, $idCatUnidad, $contenedor['estatus_unidad_id'], $nuevoEstUni]);
        }

        if ($contenedor['estatus_box_id'] != $nuevoEstBox) {
            $eid = registrarEventoLog($pdo, $idContenedor, $usuario_id, $opModEstatus, 'Cambio Estatus Estructura');
            $stmtEstLog->execute([$eid, $idCatBox, $contenedor['estatus_box_id'], $nuevoEstBox]);
        }

        if ($contenedor['estatus_ipsa_id'] != $nuevoIpsa) {
            $eid = registrarEventoLog($pdo, $idContenedor, $usuario_id, $opModEstatus, 'Cambio Estatus IPSA');
            $stmtDet = $pdo->prepare('INSERT INTO logs_ipsa (evento_id, estatus_anterior_id, estatus_nuevo_id) VALUES (?, ?, ?)');
            $stmtDet->execute([$eid, $contenedor['estatus_ipsa_id'], $nuevoIpsa]);
        }

        $pdo->commit();

        $_SESSION['toast_message'] = 'Contenedor actualizado correctamente.';
        $_SESSION['toast_type'] = 'success';

        $redirectUrl = 'modificar_contenedor.php?nombre=' . urlencode($nuevoNombre);
        if (!empty($postRefPatio)) {
            $redirectUrl .= '&ref_patio=' . urlencode($postRefPatio);
        }
        if (!empty($postRefBloque)) {
            $redirectUrl .= '&ref_bloque=' . urlencode($postRefBloque);
        }

        header('Location: ' . $redirectUrl);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['toast_message'] = 'Error: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';

        $redirectUrl = 'modificar_contenedor.php?nombre=' . urlencode($nombreContenedor);
        if (!empty($postRefPatio)) {
            $redirectUrl .= '&ref_patio=' . urlencode($postRefPatio);
        }
        if (!empty($postRefBloque)) {
            $redirectUrl .= '&ref_bloque=' . urlencode($postRefBloque);
        }

        header('Location: ' . $redirectUrl);
        exit;
    }
}

$backLink = 'bloques.php';
if (!empty($ref_patio) && !empty($ref_bloque)) {
    $backLink = 'bloques.php?patio_id=' . urlencode((string)$ref_patio) . '&bloque_id=' . urlencode((string)$ref_bloque);
}

$menuItems = [];
if (in_array($rol_id, [1, 2, 3], true)) {
    $menuItems[] = ['label' => 'Contenedores', 'href' => 'bloques.php', 'icon' => '../imagenes/bloques.png', 'active' => true];
}
$menuItems[] = ['label' => 'Seguimiento PYP', 'href' => 'contenedor.php', 'icon' => '../imagenes/cont.png', 'active' => false];
if (in_array($rol_id, [1, 2, 3], true)) {
    $menuItems[] = ['label' => 'Traslados', 'href' => '../traslados/traslados.php', 'icon' => '../imagenes/traslados.png', 'active' => false];
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
    <title>Modificar Contenedor - Proportsa</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/parametros/bloques/modificar_contenedor.css">
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
                            <button type="button" class="sidebar-mobile-sucursal-option <?php echo ($sucursal_id === (int)$suc['id']) ? 'active' : ''; ?>" data-value="<?php echo (int)$suc['id']; ?>" role="option" aria-selected="<?php echo ($sucursal_id === (int)$suc['id']) ? 'true' : 'false'; ?>">
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
                                        <button type="button" class="topbar-sucursal-option <?php echo ($sucursal_id === (int)$suc['id']) ? 'active' : ''; ?>" data-value="<?php echo (int)$suc['id']; ?>" role="option" aria-selected="<?php echo ($sucursal_id === (int)$suc['id']) ? 'true' : 'false'; ?>">
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

        <main class="dashboard-main modify-main">
            <section class="panel-card modify-module">
                <div class="modify-head">
                    <div class="view-header-copy">
                        <h1>Modificar Contenedor</h1>
                        <p>Actualiza los datos operativos del contenedor sin perder el contexto del bloque actual.</p>
                    </div>
                    <div class="modify-actions">
                        <a href="<?php echo htmlspecialchars($backLink); ?>" class="toolbar-button toolbar-button-secondary">
                            <span>Volver al bloque</span>
                        </a>
                        <button type="submit" form="contenedorForm" class="toolbar-button toolbar-button-primary">
                            <img src="../imagenes/Save.png" alt="" class="toolbar-icon">
                            <span>Guardar</span>
                        </button>
                    </div>
                </div>

                <div id="toast-container" class="toast-container"></div>

                <form action="" method="post" id="contenedorForm" class="modify-form" novalidate>
                    <input type="hidden" name="ref_patio" value="<?php echo htmlspecialchars($ref_patio); ?>">
                    <input type="hidden" name="ref_bloque" value="<?php echo htmlspecialchars($ref_bloque); ?>">
                    <input type="hidden" name="bloque" value="<?php echo htmlspecialchars((string)$contenedor['bloque_id']); ?>">
                    <input type="hidden" name="posicion" value="<?php echo htmlspecialchars((string)$contenedor['posicion']); ?>">

                    <div class="modify-grid">
                        <article class="modify-item field-half">
                            <label for="codigo_contenedor" class="modify-label">Codigo del contenedor</label>
                            <div class="modify-control-wrap">
                                <input type="text" id="codigo_contenedor" name="codigo_contenedor" class="modify-control" value="<?php echo htmlspecialchars($contenedor['nombre']); ?>" required maxlength="11" pattern="^[A-Z0-9]{11}$" title="Debe tener 11 caracteres alfanumericos" autocomplete="off">
                                <small class="field-message field-message-error" id="contenedor-error">Ya existe otro contenedor con ese codigo.</small>
                                <small class="field-message field-message-valid" id="contenedor-valid">Codigo disponible.</small>
                            </div>
                        </article>

                        <article class="modify-item field-half">
                            <label for="tipo_unidad" class="modify-label">Tipo de unidad</label>
                            <div class="modify-control-wrap modify-control-wrap-select">
                                <select id="tipo_unidad" name="tipo_unidad" class="modify-control modify-select js-custom-select-source" required>
                                    <?php foreach ($tiposMap as $id => $nombre): ?>
                                        <option value="<?php echo $id; ?>" <?php echo ((int)$id === (int)$contenedor['tipo_unidad_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </article>

                        <article class="modify-item field-half">
                            <label for="modelo" class="modify-label">Modelo</label>
                            <div class="modify-control-wrap modify-control-wrap-select">
                                <select id="modelo" name="modelo" class="modify-control modify-select js-custom-select-source" required>
                                    <?php foreach ($modelos as $mod): ?>
                                        <option value="<?php echo $mod['id']; ?>" <?php echo ((int)$mod['id'] === (int)$contenedor['modelo_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($mod['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </article>

                        <article class="modify-item field-half">
                            <label for="estado_unidad" class="modify-label">Estado de la unidad</label>
                            <div class="modify-control-wrap modify-control-wrap-select">
                                <select id="estado_unidad" name="estado_unidad" class="modify-control modify-select js-custom-select-source" required>
                                    <?php foreach ($estUnitMap as $id => $nombre): ?>
                                        <option value="<?php echo $id; ?>" <?php echo ((int)$id === (int)$contenedor['estatus_unidad_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </article>

                        <article class="modify-item field-third">
                            <label for="status_box" class="modify-label">Status box</label>
                            <div class="modify-control-wrap modify-control-wrap-select">
                                <select id="status_box" name="status_box" class="modify-control modify-select js-custom-select-source" required>
                                    <?php foreach ($estBoxMap as $id => $nombre): ?>
                                        <option value="<?php echo $id; ?>" <?php echo ((int)$id === (int)$contenedor['estatus_box_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </article>

                        <article class="modify-item field-third">
                            <label for="estatus_ipsa" class="modify-label">Estatus IPSA</label>
                            <div class="modify-control-wrap modify-control-wrap-select">
                                <select id="estatus_ipsa" name="estatus_ipsa" class="modify-control modify-select js-custom-select-source" required>
                                    <?php foreach ($ipsaMap as $id => $nombre): ?>
                                        <option value="<?php echo $id; ?>" <?php echo ((int)$id === (int)$contenedor['estatus_ipsa_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </article>

                        <article class="modify-item field-third">
                            <span class="modify-label">Ubicacion actual</span>
                            <div class="modify-value">
                                <?php echo htmlspecialchars('Bloque ' . (string)$contenedor['bloque_id'] . ', Posicion ' . (string)$contenedor['posicion']); ?>
                            </div>
                        </article>

                        <article class="modify-item field-textarea field-wide">
                            <label for="observaciones_estado" class="modify-label">Observaciones unidad</label>
                            <textarea id="observaciones_estado" name="observaciones_estado" class="modify-control modify-textarea"><?php echo htmlspecialchars((string)$contenedor['observaciones_unidad']); ?></textarea>
                        </article>

                        <article class="modify-item field-textarea field-wide">
                            <label for="observaciones_box" class="modify-label">Observaciones box</label>
                            <textarea id="observaciones_box" name="observaciones_box" class="modify-control modify-textarea"><?php echo htmlspecialchars((string)$contenedor['observaciones_box']); ?></textarea>
                        </article>
                    </div>
                </form>
            </section>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
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

    function toggleThemeMode() {
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
        const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', nextTheme);
        localStorage.setItem('proportsa-theme', nextTheme);
        syncThemeToggle();
    }

    themeToggle.addEventListener('click', toggleThemeMode);
    if (sidebarThemeToggle) sidebarThemeToggle.addEventListener('click', toggleThemeMode);

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
                sucursalInput.form.submit();
            });
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
                sidebarMobileSucursalInput.form.submit();
            });
        });
    }

    const codigoInput = document.getElementById('codigo_contenedor');
    const nombreOriginal = <?php echo json_encode($nombreContenedor); ?>;
    const err = document.getElementById('contenedor-error');
    const ok = document.getElementById('contenedor-valid');

    function resetCodigoState() {
        codigoInput.classList.remove('is-error', 'is-valid');
        err.classList.remove('show');
        ok.classList.remove('show');
    }

    codigoInput.addEventListener('input', function () {
        this.value = this.value.toUpperCase();
        const nombre = this.value.trim();
        resetCodigoState();

        if (nombre === nombreOriginal || nombre.length !== 11) {
            return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '../ingresos/verificar_contenedor.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            if (xhr.status === 200) {
                if (xhr.responseText === 'exists') {
                    codigoInput.classList.add('is-error');
                    err.classList.add('show');
                } else {
                    codigoInput.classList.add('is-valid');
                    ok.classList.add('show');
                }
            }
        };
        xhr.send('nombre=' + encodeURIComponent(nombre));
    });

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
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
                item.addEventListener('click', function () {
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    refresh();
                    dropdown.classList.remove('open');
                    trigger.setAttribute('aria-expanded', 'false');
                });
                menu.appendChild(item);
            });
        }

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            const willOpen = !dropdown.classList.contains('open');
            closeAllCustomSelects(dropdown);
            dropdown.classList.toggle('open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });

        select.addEventListener('change', refresh);
        refresh();
    }

    document.querySelectorAll('.js-custom-select-source').forEach(buildCustomSelect);

    document.addEventListener('click', function () {
        closeAllCustomSelects();
    });

    document.getElementById('tipo_unidad').addEventListener('change', function () {
        const id = this.value;
        const select = document.getElementById('modelo');
        if (!id) return;

        fetch(`../ingresos/obtener_modelos.php?tipo_unidad_id=${id}`)
            .then(function (response) { return response.json(); })
            .then(function (data) {
                let opts = '';
                data.forEach(function (modelo) {
                    opts += `<option value="${modelo.id}">${modelo.nombre}</option>`;
                });
                select.innerHTML = opts;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
    });

    function showToast(msg, type) {
        const container = document.getElementById('toast-container');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast toast-${type || 'success'}`;
        toast.textContent = msg;
        container.appendChild(toast);
        setTimeout(function () { toast.classList.add('show'); }, 10);
        setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () { toast.remove(); }, 300);
        }, 3600);
    }

    <?php if (isset($_SESSION['toast_message'])): ?>
    showToast(
        <?php echo json_encode($_SESSION['toast_message']); ?>,
        <?php echo json_encode($_SESSION['toast_type'] ?? 'success'); ?>
    );
    <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
    <?php endif; ?>

    syncThemeToggle();
});
</script>
</body>
</html>
