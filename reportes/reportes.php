<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Guatemala');

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../index.php');
    exit;
}

$rol_id = (int)($_SESSION['rol_id'] ?? 0);
$sucursal_id = (int)$_SESSION['sucursal_activa'];
$username = $_SESSION['username'] ?? 'Usuario';

if (!in_array($rol_id, [1, 2, 3, 4, 5], true)) {
    header('Location: ../index.php');
    exit;
}

require '../config/database.php';

$stmtPatios = $pdo->prepare("SELECT id, nombre FROM patios WHERE sucursal_id = :suc AND estado_id = 1 ORDER BY nombre");
$stmtPatios->execute(['suc' => $sucursal_id]);
$patios = $stmtPatios->fetchAll(PDO::FETCH_ASSOC);

$stmtCont = $pdo->prepare("
    SELECT DISTINCT c.id, c.nombre, c.estado_id
    FROM contenedores c
    JOIN bloques b ON c.bloque_id = b.id
    JOIN patios p ON b.patio_id = p.id
    WHERE p.sucursal_id = :suc
    ORDER BY c.nombre ASC
");
$stmtCont->execute(['suc' => $sucursal_id]);
$listaContenedores = $stmtCont->fetchAll(PDO::FETCH_ASSOC);

$stmtSuc = $pdo->prepare("SELECT nombre FROM sucursales WHERE id = :id");
$stmtSuc->execute(['id' => $sucursal_id]);
$nombreSucursal = $stmtSuc->fetchColumn() ?: 'Desconocida';

$sucursales = [];
if ($rol_id === 1) {
    $stmtSucursales = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursales = $stmtSucursales->fetchAll(PDO::FETCH_ASSOC);
}

$menuItems = [
    ['label' => 'Contenedores', 'href' => '../bloques/bloques.php', 'icon' => '../imagenes/bloques.png', 'active' => false],
    ['label' => 'Seguimiento PYP', 'href' => '../bloques/contenedor.php', 'icon' => '../imagenes/cont.png', 'active' => false],
    ['label' => 'Traslados', 'href' => '../traslados/traslados.php', 'icon' => '../imagenes/traslados.png', 'active' => false],
    ['label' => 'Despachos', 'href' => '../despachos/despachos.php', 'icon' => '../imagenes/egresos.png', 'active' => false],
    ['label' => 'Reportes', 'href' => '../reportes/reportes.php', 'icon' => '../imagenes/reportes.png', 'active' => true],
    ['label' => 'Parametros', 'href' => '../parametros/parametros.php', 'icon' => '../imagenes/parametros.png', 'active' => false],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Generador de Reportes</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/reportes/reportes.css">
    <script>
        (function () {
            const savedTheme = localStorage.getItem('proportsa-theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
</head>
<body>
<div class="app-shell report-shell">
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

        <main class="dashboard-main reportes-main">
            <section class="panel-card reportes-shell-card">
                <div class="module-head reportes-head">
                    <div class="view-header-copy">
                        <h1>Generador de Reportes</h1>
                        <p>Configura filtros, columnas y exportaciones desde un unico centro de reporteo para inventario, auditoria y trazabilidad.</p>
                    </div>
                </div>

                <div id="print-header-content">
                    <h2 id="ph-titulo">Reporte de Sistema</h2>
                    <p><strong>Sucursal:</strong> <?php echo htmlspecialchars($nombreSucursal); ?></p>
                    <p><strong>Impreso por:</strong> <?php echo htmlspecialchars($username); ?> | <strong>Fecha:</strong> <?php echo date('d/m/Y H:i'); ?></p>
                    <p id="ph-rango"></p>
                </div>

                <form id="reportForm" method="GET" target="_blank" class="report-form">
                    <div class="report-builder">
                        <aside class="sidebar-options">
                            <div class="option-group">
                                <div class="option-copy">
                                    <h3>1. Tipo de reporte</h3>
                                    <p>Selecciona la salida principal que quieres consultar o exportar.</p>
                                </div>
                                <select id="tipoReporte" name="tipo" onchange="toggleOpciones()">
                                    <option value="inventario">Contenedores Activos</option>
                                    <option value="despachos">Contenedores Despachados</option>
                                    <option value="movimientos">Bitacora Movimientos</option>
                                    <option value="auditorias">Auditorias PYP</option>
                                    <option value="traslados">Reporte de Traslados</option>
                                    <option value="cambios">Detalle de Cambios</option>
                                    <option value="historial">Historial de Contenedor</option>
                                </select>
                            </div>

                            <div class="option-group" id="filtros-fecha">
                                <div class="option-copy">
                                    <h3>2. Rango de fechas</h3>
                                    <p>Acota el periodo operativo que debe cubrir el reporte.</p>
                                </div>
                                <div class="date-grid">
                                    <div>
                                        <label class="field-inline" for="f_inicio">Desde</label>
                                        <input type="date" id="f_inicio" name="f_inicio" value="<?php echo date('Y-m-01'); ?>">
                                    </div>
                                    <div>
                                        <label class="field-inline" for="f_fin">Hasta</label>
                                        <input type="date" id="f_fin" name="f_fin" value="<?php echo date('Y-m-t'); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="option-group" id="filtro-patio">
                                <div class="option-copy">
                                    <h3>3. Patio</h3>
                                    <p>Filtra por area operativa o deja el alcance completo de la sucursal.</p>
                                </div>
                                <select id="patio_id" name="patio_id" onchange="cargarBloques()">
                                    <option value="">-- Todos los Patios --</option>
                                    <?php foreach ($patios as $p): ?>
                                        <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="option-group" id="filtro-bloque">
                                <div class="option-copy">
                                    <h3>4. Bloque</h3>
                                    <p>Se habilita cuando eliges un patio especifico.</p>
                                </div>
                                <select id="bloque_id" name="bloque_id" disabled>
                                    <option value="">-- Todos los Bloques --</option>
                                </select>
                            </div>

                            <div class="option-group" id="filtro-buscador" style="display:none;">
                                <div class="option-copy">
                                    <h3>2. Buscar contenedor</h3>
                                    <p>Localiza un equipo concreto para consultar su historial completo.</p>
                                </div>
                                <input type="text" id="searchCont" placeholder="Escribe codigo..." onkeyup="filtrarContenedores()" autocomplete="off">
                                <input type="hidden" name="contenedor_id" id="selected_contenedor_id">
                                <div id="lista-contenedores">
                                    <?php foreach ($listaContenedores as $c): ?>
                                        <div class="cont-item" onclick="seleccionarContenedor(this, <?php echo (int)$c['id']; ?>)">
                                            <span class="cont-nombre"><?php echo htmlspecialchars($c['nombre']); ?></span>
                                            <?php if ((int)$c['estado_id'] === 1): ?>
                                                <span class="badge badge-activo">Activo</span>
                                            <?php else: ?>
                                                <span class="badge badge-inactivo">Inactivo</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div id="seleccion-msg" class="selection-msg" style="display:none;">
                                    <strong>Seleccionado:</strong> <span id="sel-nombre">Ninguno</span>
                                </div>
                            </div>

                            <div class="option-group" id="cols-inventario">
                                <div class="option-copy option-copy-split">
                                    <div>
                                        <h3>5. Columnas</h3>
                                        <p>Define la estructura exacta del reporte antes de generar la vista previa.</p>
                                    </div>
                                    <div class="option-tools">
                                        <button type="button" class="mini-action" data-columns-target="cols-inventario" data-columns-action="all">Todo</button>
                                        <button type="button" class="mini-action" data-columns-target="cols-inventario" data-columns-action="none">Limpiar</button>
                                    </div>
                                </div>
                                <div class="checkbox-grid">
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="patio" checked> Patio</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="bloque" checked> Bloque</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="posicion" checked> Posicion</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="nombre" checked> Contenedor</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="tipo" checked> Tipo</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="modelo" checked> Modelo</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_unidad" checked> Est. Uni.</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="observaciones_unidad"> Obs. Unidad</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_box" checked> Est. Box</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="observaciones_box"> Obs. Box</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_ipsa" checked> Est. IPSA</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="estatus_pyp" checked> Est. PYP</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols[]" value="fecha_ingreso" checked> Ingreso</label>
                                </div>
                            </div>

                            <div class="option-group" id="cols-despachos" style="display:none;">
                                <div class="option-copy option-copy-split">
                                    <div>
                                        <h3>5. Columnas</h3>
                                        <p>Activa solo los datos relevantes para despacho y retiro.</p>
                                    </div>
                                    <div class="option-tools">
                                        <button type="button" class="mini-action" data-columns-target="cols-despachos" data-columns-action="all">Todo</button>
                                        <button type="button" class="mini-action" data-columns-target="cols-despachos" data-columns-action="none">Limpiar</button>
                                    </div>
                                </div>
                                <div class="checkbox-grid">
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="patio" checked> Patio</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="bloque" checked> Bloque</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="contenedor" checked> Contenedor</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="tipo"> Tipo</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="modelo"> Modelo</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="estatus_unidad"> Est. Uni.</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="estatus_box"> Est. Box</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="estatus_ipsa"> Est. IPSA</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="estatus_pyp"> Est. PYP</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="dias_estadia" checked> Dias Estadia</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="usuario" checked> Despachador</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="observaciones"> Observaciones</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="fecha_ingreso" checked> Ingreso</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_desp[]" value="fecha_despacho" checked> Despacho</label>
                                </div>
                            </div>

                            <div class="option-group" id="cols-movimientos" style="display:none;">
                                <div class="option-copy option-copy-split">
                                    <div>
                                        <h3>3. Columnas</h3>
                                        <p>Resume la trazabilidad operativa y administrativa del equipo.</p>
                                    </div>
                                    <div class="option-tools">
                                        <button type="button" class="mini-action" data-columns-target="cols-movimientos" data-columns-action="all">Todo</button>
                                        <button type="button" class="mini-action" data-columns-target="cols-movimientos" data-columns-action="none">Limpiar</button>
                                    </div>
                                </div>
                                <div class="checkbox-grid">
                                    <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="patio" checked> Patio</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="bloque" checked> Bloque</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="contenedor" checked> Contenedor</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="tipo"> Tipo</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="modelo"> Modelo</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="operacion" checked> Operacion</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="usuario" checked> Usuario</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="detalle" checked> Detalles</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_mov[]" value="fecha" checked> Fecha</label>
                                </div>
                            </div>

                            <div class="option-group" id="cols-auditorias" style="display:none;">
                                <div class="option-copy option-copy-split">
                                    <div>
                                        <h3>3. Columnas</h3>
                                        <p>Organiza la lectura de hallazgos, auditor y fecha de registro.</p>
                                    </div>
                                    <div class="option-tools">
                                        <button type="button" class="mini-action" data-columns-target="cols-auditorias" data-columns-action="all">Todo</button>
                                        <button type="button" class="mini-action" data-columns-target="cols-auditorias" data-columns-action="none">Limpiar</button>
                                    </div>
                                </div>
                                <div class="checkbox-grid">
                                    <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="patio" checked> Patio</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="bloque" checked> Bloque</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="contenedor" checked> Equipo</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="estatus_pyp" checked> Est. PYP</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="info" checked> Info</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="usuario"> Auditor</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_aud[]" value="fecha" checked> Fecha</label>
                                </div>
                            </div>

                            <div class="option-group" id="cols-traslados" style="display:none;">
                                <div class="option-copy option-copy-split">
                                    <div>
                                        <h3>3. Columnas</h3>
                                        <p>Detalla origen, destino y responsable del movimiento interno.</p>
                                    </div>
                                    <div class="option-tools">
                                        <button type="button" class="mini-action" data-columns-target="cols-traslados" data-columns-action="all">Todo</button>
                                        <button type="button" class="mini-action" data-columns-target="cols-traslados" data-columns-action="none">Limpiar</button>
                                    </div>
                                </div>
                                <div class="checkbox-grid">
                                    <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="patio" checked> Patio Destino</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="bloque" checked> Bloque Destino</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="contenedor" checked> Equipo</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="origen" checked> Desde (Origen)</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="destino" checked> Hacia (Destino)</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="usuario" checked> Operador</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="detalle"> Observaciones</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_tras[]" value="fecha" checked> Fecha</label>
                                </div>
                            </div>

                            <div class="option-group" id="cols-cambios" style="display:none;">
                                <div class="option-copy option-copy-split">
                                    <div>
                                        <h3>5. Columnas</h3>
                                        <p>Muestra el antes y despues de las ediciones realizadas en el sistema.</p>
                                    </div>
                                    <div class="option-tools">
                                        <button type="button" class="mini-action" data-columns-target="cols-cambios" data-columns-action="all">Todo</button>
                                        <button type="button" class="mini-action" data-columns-target="cols-cambios" data-columns-action="none">Limpiar</button>
                                    </div>
                                </div>
                                <div class="checkbox-grid">
                                    <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="patio" checked> Patio</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="bloque" checked> Bloque</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="contenedor" checked> Contenedor</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="usuario" checked> Usuario</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="tipo_cambio" checked> Tipo Cambio</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="campo" checked> Campo</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="anterior" checked> Valor Anterior</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="nuevo" checked> Valor Nuevo</label>
                                    <label class="checkbox-label"><input type="checkbox" name="cols_cambios[]" value="fecha" checked> Fecha</label>
                                </div>
                            </div>

                            <div class="actions">
                                <button type="button" class="btn-preview" onclick="cargarVistaPrevia()">Vista Previa</button>
                                <div class="actions-divider"></div>
                                <label class="actions-label">Exportar</label>
                                <input type="hidden" name="formato" id="formato_salida" value="excel">
                                <div class="export-grid">
                                    <button type="button" class="btn-excel" onclick="prepararExportacion('excel')">Excel</button>
                                    <button type="button" class="btn-pdf" onclick="prepararExportacion('pdf')">PDF</button>
                                </div>
                            </div>
                        </aside>

                        <section class="preview-area">
                            <div class="preview-head">
                                <div>
                                    <h2>Vista Previa</h2>
                                    <p>Verifica el resultado antes de exportar o imprimir.</p>
                                </div>
                            </div>
                            <div id="loading" class="loading-state" style="display:none;">Cargando...</div>
                            <div id="tabla-resultado">
                                <div class="preview-empty">
                                    <div class="preview-empty-icon" aria-hidden="true">
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                    </div>
                                    <p>Configura tu reporte y luego genera la <strong>Vista Previa</strong>.</p>
                                </div>
                            </div>
                        </section>
                    </div>
                </form>
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
const reportNames = {
    inventario: 'Contenedores Activos',
    despachos: 'Contenedores Despachados',
    movimientos: 'Bitacora Movimientos',
    auditorias: 'Auditorias PYP',
    traslados: 'Reporte de Traslados',
    cambios: 'Detalle de Cambios',
    historial: 'Historial de Contenedor'
};

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

function attachColumnsActions() {
    document.querySelectorAll('[data-columns-action]').forEach((button) => {
        button.addEventListener('click', function () {
            const targetId = this.getAttribute('data-columns-target');
            const action = this.getAttribute('data-columns-action');
            const target = document.getElementById(targetId);
            if (!target) return;

            target.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
                checkbox.checked = action === 'all';
            });
        });
    });
}

function toggleOpciones() {
    const tipo = document.getElementById('tipoReporte').value;
    const allColDivs = document.querySelectorAll('[id^="cols-"]');
    allColDivs.forEach((div) => div.style.display = 'none');

    document.getElementById('filtros-fecha').style.display = 'none';
    document.getElementById('filtro-patio').style.display = 'none';
    document.getElementById('filtro-bloque').style.display = 'none';
    document.getElementById('filtro-buscador').style.display = 'none';

    if (tipo === 'historial') {
        document.getElementById('filtro-buscador').style.display = 'block';
    } else {
        document.getElementById('filtros-fecha').style.display = 'block';
        document.getElementById('filtro-patio').style.display = 'block';
        document.getElementById('filtro-bloque').style.display = 'block';

        if (tipo === 'inventario') document.getElementById('cols-inventario').style.display = 'block';
        else if (tipo === 'despachos') document.getElementById('cols-despachos').style.display = 'block';
        else if (tipo === 'movimientos') document.getElementById('cols-movimientos').style.display = 'block';
        else if (tipo === 'auditorias') document.getElementById('cols-auditorias').style.display = 'block';
        else if (tipo === 'traslados') document.getElementById('cols-traslados').style.display = 'block';
        else if (tipo === 'cambios') document.getElementById('cols-cambios').style.display = 'block';
    }

}

function filtrarContenedores() {
    const input = document.getElementById('searchCont');
    const filter = input.value.toUpperCase();
    const lista = document.getElementById('lista-contenedores');
    const items = lista.getElementsByClassName('cont-item');
    for (let i = 0; i < items.length; i++) {
        const nombre = items[i].querySelector('.cont-nombre').textContent;
        items[i].style.display = nombre.toUpperCase().indexOf(filter) > -1 ? '' : 'none';
    }
}

function seleccionarContenedor(el, id) {
    document.getElementById('selected_contenedor_id').value = id;
    const items = document.getElementsByClassName('cont-item');
    for (let i = 0; i < items.length; i++) {
        items[i].classList.remove('selected');
    }
    el.classList.add('selected');
    const nombre = el.querySelector('.cont-nombre').textContent;
    document.getElementById('seleccion-msg').style.display = 'block';
    document.getElementById('sel-nombre').innerText = nombre;
}

function cargarBloques() {
    const patioId = document.getElementById('patio_id').value;
    const bloqueSelect = document.getElementById('bloque_id');
    bloqueSelect.innerHTML = '<option value="">-- Todos los Bloques --</option>';
    bloqueSelect.disabled = true;
    if (patioId) {
        fetch(`../ingresos/obtener_bloques.php?patio_id=${patioId}`)
            .then((r) => r.json())
            .then((data) => {
                if (data.length > 0) {
                    data.forEach((b) => {
                        const opt = document.createElement('option');
                        opt.value = b.id;
                        opt.textContent = `Bloque ${b.numero}`;
                        bloqueSelect.appendChild(opt);
                    });
                    bloqueSelect.disabled = false;
                }
            })
            .catch((e) => console.error('Error cargando bloques', e));
    }
}

function getScriptURL() {
    const tipo = document.getElementById('tipoReporte').value;
    if (tipo === 'inventario') return 'generar_inventario.php';
    if (tipo === 'despachos') return 'generar_despachos.php';
    if (tipo === 'movimientos') return 'generar_movimientos.php';
    if (tipo === 'auditorias') return 'generar_auditorias.php';
    if (tipo === 'traslados') return 'generar_traslados.php';
    if (tipo === 'cambios') return 'generar_cambios.php';
    if (tipo === 'historial') return 'generar_historial.php';
    return '';
}

function cargarVistaPrevia() {
    const tipo = document.getElementById('tipoReporte').value;
    if (tipo === 'historial') {
        if (!document.getElementById('selected_contenedor_id').value) {
            alert('Seleccione un contenedor.');
            return;
        }
    }

    const form = document.getElementById('reportForm');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    params.append('modo', 'preview');

    document.getElementById('loading').style.display = 'block';
    document.getElementById('tabla-resultado').innerHTML = '';

    fetch(`${getScriptURL()}?${params.toString()}`)
        .then((response) => response.json())
        .then((data) => {
            document.getElementById('loading').style.display = 'none';
            if (data.error) {
                document.getElementById('tabla-resultado').innerHTML = `<p class="preview-error">Error: ${data.error}</p>`;
                return;
            }

            if (tipo === 'historial') construirTablaSimple(data.headers, data.rows);
            else construirTablaAgrupada(data.headers, data.rows);
        })
        .catch((err) => {
            document.getElementById('loading').style.display = 'none';
            document.getElementById('tabla-resultado').innerHTML = '<p class="preview-error">No se pudo cargar la vista previa.</p>';
            console.error(err);
        });
}

function prepararExportacion(formato) {
    if (document.getElementById('tipoReporte').value === 'historial' && !document.getElementById('selected_contenedor_id').value) {
        alert('Seleccione un contenedor.');
        return;
    }
    document.getElementById('formato_salida').value = formato;
    document.getElementById('reportForm').action = getScriptURL();
    document.getElementById('reportForm').target = '_blank';
    document.getElementById('reportForm').submit();
}

function construirTablaAgrupada(headers, rows) {
    if (!rows || rows.length === 0) {
        document.getElementById('tabla-resultado').innerHTML = '<p class="preview-message">No se encontraron datos.</p>';
        return;
    }
    const container = document.getElementById('tabla-resultado');
    container.innerHTML = '';
    const grupos = {};
    rows.forEach((row) => {
        const key = row.patio_nombre && row.bloque_numero ? `${row.patio_nombre} - Bloque ${row.bloque_numero}` : 'General';
        if (!grupos[key]) grupos[key] = [];
        grupos[key].push(row);
    });
    Object.keys(grupos).forEach((titulo) => {
        if (titulo !== 'General' || Object.keys(grupos).length > 1) {
            const h3 = document.createElement('h3');
            h3.className = 'group-header';
            h3.textContent = titulo;
            container.appendChild(h3);
        }
        const table = document.createElement('table');
        table.className = 'preview-table';
        let thead = '<thead><tr>';
        headers.forEach((h) => {
            if (h !== 'Patio' && h !== 'Bloque') thead += `<th>${h}</th>`;
        });
        thead += '</tr></thead>';
        table.innerHTML = thead;
        const tbody = document.createElement('tbody');
        grupos[titulo].forEach((row) => {
            const tr = document.createElement('tr');
            const clavesOcultas = ['patio_nombre', 'bloque_numero'];
            Object.keys(row).forEach((key) => {
                if (!clavesOcultas.includes(key)) {
                    const td = document.createElement('td');
                    td.textContent = row[key];
                    tr.appendChild(td);
                }
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        container.appendChild(table);
    });
}

function construirTablaSimple(headers, rows) {
    if (!rows || rows.length === 0) {
        document.getElementById('tabla-resultado').innerHTML = '<p class="preview-message">No hay historial disponible.</p>';
        return;
    }
    let html = '<table class="preview-table"><thead><tr>';
    headers.forEach((h) => html += `<th>${h}</th>`);
    html += '</tr></thead><tbody>';
    rows.forEach((row) => {
        html += '<tr>';
        Object.values(row).forEach((val) => html += `<td>${val}</td>`);
        html += '</tr>';
    });
    html += '</tbody></table>';
    document.getElementById('tabla-resultado').innerHTML = html;
}

attachColumnsActions();
toggleOpciones();
</script>
</body>
</html>
