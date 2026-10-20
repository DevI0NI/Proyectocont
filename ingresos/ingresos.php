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
$usuario_id = (int)$_SESSION['user_id'];
$sucursales = [];
$nombreSucursalActiva = 'Sin asignar';
$error_message = '';

date_default_timezone_set('America/Guatemala');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

$stmtTiposUnidad = $pdo->query('SELECT id, nombre FROM tipos_unidad WHERE estado_id = 1 ORDER BY nombre ASC');
$tiposUnidad = $stmtTiposUnidad->fetchAll(PDO::FETCH_ASSOC);

$stmtCat = $pdo->prepare('SELECT id FROM tipos_estatus WHERE nombre = :nombre');
$stmtCat->execute(['nombre' => 'Unidad']);
$idCatUnidad = $stmtCat->fetchColumn();
$stmtEstUnidad = $pdo->prepare('SELECT id, nombre FROM estatus_contenedores WHERE tipo_estatus_id = :id AND estado_id = 1 ORDER BY nombre ASC');
$stmtEstUnidad->execute(['id' => $idCatUnidad]);
$estadosUnidad = $stmtEstUnidad->fetchAll(PDO::FETCH_ASSOC);

$stmtCat->execute(['nombre' => 'Estructura']);
$idCatBox = $stmtCat->fetchColumn();
$stmtEstBox = $pdo->prepare('SELECT id, nombre FROM estatus_contenedores WHERE tipo_estatus_id = :id AND estado_id = 1 ORDER BY nombre ASC');
$stmtEstBox->execute(['id' => $idCatBox]);
$statusBox = $stmtEstBox->fetchAll(PDO::FETCH_ASSOC);

$stmtIpsaList = $pdo->query("SELECT id, nombre FROM estatus_ipsa WHERE estado_id = 1 ORDER BY nombre ASC");
$listaEstatusIpsa = $stmtIpsaList->fetchAll(PDO::FETCH_ASSOC);

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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fechaIngreso        = $_POST['fecha_ingreso'] . ' ' . date('H:i:s');
    $codigoContenedor    = trim(strtoupper($_POST['codigo_contenedor']));
    $tipoUnidad          = (int)$_POST['tipo_unidad'];
    $modelo              = (int)$_POST['modelo'];
    $estadoUnidadId      = (int)$_POST['estado_unidad'];
    $observacionesEstado = trim($_POST['observaciones_estado']);
    $statusBoxId         = (int)$_POST['status_box'];
    $observacionesBox    = trim($_POST['observaciones_box']);
    $estatusIpsaId       = (int)$_POST['estatus_ipsa'];
    $bloqueId            = (int)$_POST['bloque'];
    $posicion            = (int)$_POST['posicion'];

    try {
        $pdo->beginTransaction();

        $stmtCheckSuc = $pdo->prepare('SELECT p.sucursal_id FROM bloques b JOIN patios p ON b.patio_id = p.id WHERE b.id = :id');
        $stmtCheckSuc->execute(['id' => $bloqueId]);
        if ($stmtCheckSuc->fetchColumn() != $sucursal_id) {
            throw new Exception('Error de seguridad: El bloque no pertenece a la sucursal activa.');
        }

        $stmtCap = $pdo->prepare('SELECT capacidad_actual, capacidad_maxima FROM bloques WHERE id = :id');
        $stmtCap->execute(['id' => $bloqueId]);
        $cap = $stmtCap->fetch(PDO::FETCH_ASSOC);
        if ($cap['capacidad_actual'] >= $cap['capacidad_maxima']) {
            throw new Exception('El bloque seleccionado esta lleno.');
        }

        $stmtPos = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE bloque_id = :id AND posicion = :pos AND estado_id = 1');
        $stmtPos->execute(['id' => $bloqueId, 'pos' => $posicion]);
        if ($stmtPos->fetchColumn() > 0) {
            throw new Exception("La posicion $posicion ya esta ocupada por otro contenedor activo.");
        }

        $stmtExist = $pdo->prepare('SELECT id, estado_id FROM contenedores WHERE nombre = :nombre');
        $stmtExist->execute(['nombre' => $codigoContenedor]);
        $contenedorExistente = $stmtExist->fetch(PDO::FETCH_ASSOC);

        $contenedorId = 0;
        $esReingreso = false;

        if ($contenedorExistente) {
            if ($contenedorExistente['estado_id'] == 1) {
                throw new Exception('El numero de equipo ya existe y esta activo en el patio.');
            } else {
                $esReingreso = true;
                $contenedorId = $contenedorExistente['id'];

                $sqlUpdate = '
                    UPDATE contenedores SET
                        bloque_id = :blk,
                        posicion = :pos,
                        tipo_unidad_id = :tip,
                        modelo_id = :mod,
                        estatus_unidad_id = :e_uni,
                        observaciones_unidad = :o_uni,
                        estatus_box_id = :e_box,
                        observaciones_box = :o_box,
                        estatus_ipsa_id = :ipsa,
                        fecha_ingreso = :fec,
                        estado_id = 1
                    WHERE id = :id
                ';
                $stmtUpd = $pdo->prepare($sqlUpdate);
                $stmtUpd->execute([
                    'blk' => $bloqueId,
                    'pos' => $posicion,
                    'tip' => $tipoUnidad,
                    'mod' => $modelo,
                    'e_uni' => $estadoUnidadId,
                    'o_uni' => $observacionesEstado,
                    'e_box' => $statusBoxId,
                    'o_box' => $observacionesBox,
                    'ipsa' => $estatusIpsaId,
                    'fec' => $fechaIngreso,
                    'id' => $contenedorId
                ]);

                $stmtDelPyp = $pdo->prepare('DELETE FROM pyp_auditoria WHERE contenedor_id = :cid');
                $stmtDelPyp->execute(['cid' => $contenedorId]);
            }
        } else {
            $sqlIns = '
                INSERT INTO contenedores (
                    nombre, bloque_id, posicion, tipo_unidad_id, modelo_id,
                    estatus_unidad_id, observaciones_unidad, estatus_box_id, observaciones_box,
                    estatus_ipsa_id, fecha_ingreso, estado_id
                ) VALUES (
                    :nom, :blk, :pos, :tip, :mod,
                    :e_uni, :o_uni, :e_box, :o_box,
                    :ipsa, :fec, 1
                )
            ';
            $stmtIns = $pdo->prepare($sqlIns);
            $stmtIns->execute([
                'nom' => $codigoContenedor,
                'blk' => $bloqueId,
                'pos' => $posicion,
                'tip' => $tipoUnidad,
                'mod' => $modelo,
                'e_uni' => $estadoUnidadId,
                'o_uni' => $observacionesEstado,
                'e_box' => $statusBoxId,
                'o_box' => $observacionesBox,
                'ipsa' => $estatusIpsaId,
                'fec' => $fechaIngreso
            ]);
            $contenedorId = $pdo->lastInsertId();
        }

        $stmtOp = $pdo->prepare('SELECT id FROM tipos_operacion WHERE nombre = "INGRESO"');
        $stmtOp->execute();
        $opId = $stmtOp->fetchColumn() ?: 1;

        $logMensaje = $esReingreso ? 'Reingreso de equipo (Historial previo archivado).' : 'Ingreso inicial (Equipo nuevo).';

        $stmtLog = $pdo->prepare('INSERT INTO logs_eventos (contenedor_id, usuario_id, tipo_operacion_id, observaciones, estado_id) VALUES (?, ?, ?, ?, 1)');
        $stmtLog->execute([$contenedorId, $usuario_id, $opId, $logMensaje]);
        $eventoId = $pdo->lastInsertId();

        $stmtDetalle = $pdo->prepare('INSERT INTO logs_traslados (evento_id, bloque_nuevo_id, posicion_nueva) VALUES (?, ?, ?)');
        $stmtDetalle->execute([$eventoId, $bloqueId, $posicion]);

        $stmtInc = $pdo->prepare('UPDATE bloques SET capacidad_actual = capacidad_actual + 1 WHERE id = :bid');
        $stmtInc->execute(['bid' => $bloqueId]);

        $pdo->commit();

        $_SESSION['toast_message'] = $esReingreso ? 'Contenedor reingresado y reseteado correctamente.' : 'Contenedor ingresado correctamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: ingresos.php');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Nuevo Ingreso</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/ingresos.css/ingresos.css">
    <script>
        (function () {
            const savedTheme = localStorage.getItem('proportsa-theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <style>
        #toast-container { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; width: 90%; max-width: 400px; pointer-events: none; }
        .toast { padding: 15px 20px; border-radius: 8px; color: #fff; font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,0.15); opacity: 0; transform: translateY(-20px); transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 10px; }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.error { background-color: #dc3545; border-left: 5px solid #bd2130; }
        .toast.success { background-color: #28a745; border-left: 5px solid #1e7e34; }
        input.error { border-color: #ff4d4d !important; background-color: #fff5f5; }
        input.valid { border-color: #28a745 !important; }
        .error-message { color: #ff4d4d; display: none; font-size: 0.85em; font-weight: bold; }
        .valid-message { color: #28a745; display: none; font-size: 0.85em; font-weight: bold; }
    </style>
</head>
<body>
<div id="toast-container"></div>

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
                    <h1>Nuevo Ingreso</h1>
                    <p>Registra un equipo nuevo o procesa un reingreso dentro de <?php echo htmlspecialchars($nombreSucursalActiva); ?>.</p>
                </div>
            </section>

            <?php if ($error_message): ?>
                <div class="ingreso-alert ingreso-alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <section class="panel-card ingreso-form-panel">
                <form action="ingresos.php" method="post" class="ingreso-form">
                    <div class="form-group">
                        <label for="fecha_ingreso">Fecha de Ingreso</label>
                        <div class="field-wrap">
                            <div class="date-shell">
                                <input type="date" id="fecha_ingreso" name="fecha_ingreso" required value="<?php echo isset($_POST['fecha_ingreso']) ? htmlspecialchars($_POST['fecha_ingreso']) : ''; ?>">
                                <span class="date-shell-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" focusable="false">
                                        <path d="M7 2.75a.75.75 0 0 1 .75.75V5h8.5V3.5a.75.75 0 0 1 1.5 0V5h.75A2.5 2.5 0 0 1 21 7.5v11A2.5 2.5 0 0 1 18.5 21h-13A2.5 2.5 0 0 1 3 18.5v-11A2.5 2.5 0 0 1 5.5 5h.75V3.5A.75.75 0 0 1 7 2.75ZM4.5 9v9.5c0 .552.448 1 1 1h13a1 1 0 0 0 1-1V9h-15Z" fill="currentColor"/>
                                    </svg>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="codigo_contenedor">Numero de Equipo</label>
                        <div class="field-wrap">
                            <input type="text" id="codigo_contenedor" name="codigo_contenedor" required maxlength="11" pattern="^[A-Z0-9]{11}$" title="Debe tener exactamente 11 caracteres, solo mayusculas y numeros" value="<?php echo isset($_POST['codigo_contenedor']) ? htmlspecialchars($_POST['codigo_contenedor']) : ''; ?>">
                            <span class="error-message" id="contenedor-error">Este contenedor ya existe.</span>
                            <span class="valid-message" id="contenedor-valid">Contenedor disponible.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="tipo_unidad">Tipo de Unidad</label>
                        <div class="field-wrap">
                            <div class="select-shell">
                                <select id="tipo_unidad" name="tipo_unidad" class="js-custom-select-source" required>
                                    <option value="">Seleccione un tipo</option>
                                    <?php foreach ($tiposUnidad as $tipo): ?>
                                        <option value="<?php echo $tipo['id']; ?>" <?php echo (isset($_POST['tipo_unidad']) && $_POST['tipo_unidad'] == $tipo['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tipo['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="modelo">Modelo</label>
                        <div class="field-wrap">
                            <div class="select-shell">
                                <select id="modelo" name="modelo" class="js-custom-select-source" required>
                                    <option value="">Seleccione un modelo</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="estado_unidad">Estado de Unidad</label>
                        <div class="field-wrap">
                            <div class="select-shell">
                                <select id="estado_unidad" name="estado_unidad" class="js-custom-select-source" required onchange="calcularIpsa()">
                                    <option value="">Seleccione un estado</option>
                                    <?php foreach ($estadosUnidad as $estado): ?>
                                        <option value="<?php echo $estado['id']; ?>" data-nombre="<?php echo htmlspecialchars($estado['nombre']); ?>">
                                            <?php echo htmlspecialchars($estado['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group form-group-textarea">
                        <label for="observaciones_estado">Observaciones Unidad</label>
                        <div class="field-wrap">
                            <textarea id="observaciones_estado" name="observaciones_estado"><?php echo isset($_POST['observaciones_estado']) ? htmlspecialchars($_POST['observaciones_estado']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="status_box">Status de Box</label>
                        <div class="field-wrap">
                            <div class="select-shell">
                                <select id="status_box" name="status_box" class="js-custom-select-source" required onchange="calcularIpsa()">
                                    <option value="">Seleccione un status</option>
                                    <?php foreach ($statusBox as $status): ?>
                                        <option value="<?php echo $status['id']; ?>" data-nombre="<?php echo htmlspecialchars($status['nombre']); ?>">
                                            <?php echo htmlspecialchars($status['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group form-group-textarea">
                        <label for="observaciones_box">Observaciones Box</label>
                        <div class="field-wrap">
                            <textarea id="observaciones_box" name="observaciones_box"><?php echo isset($_POST['observaciones_box']) ? htmlspecialchars($_POST['observaciones_box']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="estatus_ipsa">Estatus IPSA</label>
                        <div class="field-wrap field-wrap-inline">
                            <div class="select-shell select-shell-accent">
                                <select id="estatus_ipsa" name="estatus_ipsa" required class="ipsa-select js-custom-select-source">
                                    <?php foreach ($listaEstatusIpsa as $ipsa): ?>
                                        <option value="<?php echo $ipsa['id']; ?>" data-nombre="<?php echo htmlspecialchars($ipsa['nombre']); ?>">
                                            <?php echo htmlspecialchars($ipsa['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <small class="field-note">El sistema sugiere un estatus, pero puedes cambiarlo.</small>
                        </div>
                    </div>

                    <div class="ubicacion-container">
                        <h2>Ubicacion</h2>
                        <div class="ubicacion-fields">
                            <div class="select-shell">
                                <select id="patio" name="patio" class="js-custom-select-source" required>
                                    <option value="">Seleccione un patio</option>
                                    <?php foreach ($patios as $patio): ?>
                                        <option value="<?php echo $patio['id']; ?>" <?php echo (isset($_POST['patio']) && $_POST['patio'] == $patio['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($patio['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="select-shell">
                                <select id="bloque" name="bloque" class="js-custom-select-source" required disabled>
                                    <option value="">Seleccione un bloque</option>
                                </select>
                            </div>

                            <input type="text" id="posicion" name="posicion" placeholder="Posicion" readonly required>
                        </div>
                    </div>

                    <div class="buttons">
                        <a href="../bloques/bloques.php" class="toolbar-button toolbar-button-secondary ingreso-back-button">
                            <img src="../imagenes/regresar.png" alt="" aria-hidden="true" class="toolbar-icon">
                            <span>Retroceder</span>
                        </a>
                        <a href="eir_ingreso.php" class="toolbar-button toolbar-button-secondary ingreso-doc-button">
                            <img src="../imagenes/report.png" alt="" aria-hidden="true" class="toolbar-icon">
                            <span>Constancia EIR</span>
                        </a>
                        <button type="submit" class="guardar-button">
                            <img src="../imagenes/Save.png" alt="Guardar">
                            <span>Guardar</span>
                        </button>
                    </div>
                </form>
            </section>
        </main>
    </div>
</div>

<script>
function calcularIpsa() {
    const unidadSelect = document.getElementById('estado_unidad');
    const boxSelect = document.getElementById('status_box');
    const ipsaSelect = document.getElementById('estatus_ipsa');

    const nomUnidad = unidadSelect.options[unidadSelect.selectedIndex]?.dataset.nombre || '';
    const nomBox = boxSelect.options[boxSelect.selectedIndex]?.dataset.nombre || '';

    let sugerido = 'Pendiente';
    if (nomUnidad === 'DMG' || nomBox === 'DMG') {
        sugerido = 'OOS';
    } else if (nomUnidad === 'OK' && (nomBox === 'OK' || nomBox.includes('Reparacion Minima'))) {
        sugerido = 'Disponible';
    } else if (nomUnidad.includes('Pendiente') || nomBox.includes('Pendiente')) {
        sugerido = 'Pendiente';
    }

    for (let i = 0; i < ipsaSelect.options.length; i++) {
        if (ipsaSelect.options[i].dataset.nombre === sugerido) {
            ipsaSelect.selectedIndex = i;
            break;
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const fechaInput = document.getElementById('fecha_ingreso');
    if (fechaInput && !fechaInput.value) {
        const hoy = new Date();
        const yyyy = hoy.getFullYear();
        const mm = String(hoy.getMonth() + 1).padStart(2, '0');
        const dd = String(hoy.getDate()).padStart(2, '0');
        fechaInput.value = `${yyyy}-${mm}-${dd}`;
    }
    <?php if (isset($_POST['tipo_unidad'])): ?>document.getElementById('tipo_unidad').dispatchEvent(new Event('change'));<?php endif; ?>
    <?php if (isset($_POST['patio'])): ?>document.getElementById('patio').dispatchEvent(new Event('change'));<?php endif; ?>
});

document.getElementById('tipo_unidad').addEventListener('change', function () {
    const tipoId = this.value;
    const select = document.getElementById('modelo');
    const prev = "<?php echo $_POST['modelo'] ?? ''; ?>";

    select.innerHTML = '<option value="">Cargando...</option>';
    if (typeof select._refreshCustomSelect === 'function') {
        select._refreshCustomSelect();
    }
    if (tipoId) {
        fetch(`obtener_modelos.php?tipo_unidad_id=${tipoId}`)
            .then(r => r.json())
            .then(data => {
                select.innerHTML = '<option value="">Seleccione un modelo</option>';
                data.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item.id;
                    opt.text = item.nombre;
                    if (item.id == prev) opt.selected = true;
                    select.appendChild(opt);
                });
                if (typeof select._refreshCustomSelect === 'function') {
                    select._refreshCustomSelect();
                }
            });
    } else {
        select.innerHTML = '<option value="">Seleccione un modelo</option>';
        if (typeof select._refreshCustomSelect === 'function') {
            select._refreshCustomSelect();
        }
    }
});

document.getElementById('patio').addEventListener('change', function () {
    const patioId = this.value;
    const select = document.getElementById('bloque');
    const prev = "<?php echo $_POST['bloque'] ?? ''; ?>";

    select.innerHTML = '<option value="">Cargando...</option>';
    select.disabled = true;
    document.getElementById('posicion').value = '';
    if (typeof select._refreshCustomSelect === 'function') {
        select._refreshCustomSelect();
    }

    if (patioId) {
        fetch(`obtener_bloques.php?patio_id=${patioId}`)
            .then(r => r.json())
            .then(data => {
                select.innerHTML = '<option value="">Seleccione un bloque</option>';
                if (data.length > 0) {
                    select.disabled = false;
                    data.forEach(item => {
                        const opt = document.createElement('option');
                        opt.value = item.id;
                        opt.text = `Bloque ${item.numero}`;
                          if (item.id == prev) opt.selected = true;
                          select.appendChild(opt);
                      });
                      if (typeof select._refreshCustomSelect === 'function') {
                          select._refreshCustomSelect();
                      }
                      if (prev) select.dispatchEvent(new Event('change'));
                  } else {
                      select.innerHTML = '<option value="">Sin bloques disponibles</option>';
                      if (typeof select._refreshCustomSelect === 'function') {
                          select._refreshCustomSelect();
                      }
                  }
              });
    } else {
        select.innerHTML = '<option value="">Seleccione un bloque</option>';
        if (typeof select._refreshCustomSelect === 'function') {
            select._refreshCustomSelect();
        }
    }
});

document.getElementById('bloque').addEventListener('change', function () {
    const bloqueId = this.value;
    const input = document.getElementById('posicion');
    input.value = 'Calculando...';
    if (bloqueId) {
        fetch(`obtener_posicion.php?bloque_id=${bloqueId}`)
            .then(r => r.json())
            .then(data => {
                if (data.posicion) {
                    input.value = data.posicion;
                } else if (data.error) {
                    input.value = '';
                    alert(data.error);
                    this.value = '';
                }
            });
    } else {
        input.value = '';
    }
});

document.getElementById('codigo_contenedor').addEventListener('input', function () {
    this.value = this.value.toUpperCase();
    const val = this.value;
    const regex = /^[A-Z0-9]{11}$/;
    const err = document.getElementById('contenedor-error');
    const ok = document.getElementById('contenedor-valid');

    this.classList.remove('error', 'valid');
    err.style.display = 'none';
    ok.style.display = 'none';

    if (regex.test(val)) {
        fetch('verificar_contenedor.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'nombre=' + encodeURIComponent(val)
        })
        .then(r => r.text())
        .then(res => {
            if (res === 'exists') {
                this.classList.add('error');
                err.textContent = 'Este contenedor ya esta activo en patio.';
                err.style.display = 'inline';
            } else {
                this.classList.add('valid');
                ok.textContent = (res === 'available') ? 'Nuevo ingreso disponible.' : 'Reingreso disponible.';
                ok.style.display = 'inline';
            }
        });
    }
});

function showToast(msg, type = 'success') {
    const c = document.getElementById('toast-container');
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.textContent = msg;
    c.appendChild(t);
    void t.offsetWidth;
    t.classList.add('show');
    setTimeout(() => {
        t.classList.remove('show');
        setTimeout(() => t.remove(), 300);
    }, 4000);
}

<?php if (isset($_SESSION['toast_message'])): ?>
document.addEventListener('DOMContentLoaded', function () {
    showToast(
        <?php echo json_encode($_SESSION['toast_message']); ?>,
        <?php echo json_encode($_SESSION['toast_type'] ?? 'success'); ?>
    );
});
<?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
<?php endif; ?>

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
</script>
</body>
</html>
