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
if (in_array($rol_id, [4, 5], true)) {
    header('Location: contenedor.php');
    exit;
}

$username = $_SESSION['username'] ?? 'Usuario';
$sucursal_id = (int)$_SESSION['sucursal_activa'];
$sucursales = [];
$nombreSucursalActiva = 'Sin asignar';

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
$patiosMap = [];
foreach ($patios as $patio) {
    $patiosMap[(int)$patio['id']] = $patio['nombre'];
}

$patioSeleccionado = isset($_GET['patio_id']) ? (int)$_GET['patio_id'] : 0;
$bloqueSeleccionado = isset($_GET['bloque_id']) ? (int)$_GET['bloque_id'] : 0;
$bloques = [];
$bloquesMap = [];
$nombrePatioSeleccionado = '';
$nombreBloqueSeleccionado = '';
$bloqueActual = 0;

if ($patioSeleccionado > 0) {
    $stmtCheckPatio = $pdo->prepare('SELECT id, nombre FROM patios WHERE id = :id AND sucursal_id = :sucursal AND estado_id = 1');
    $stmtCheckPatio->execute(['id' => $patioSeleccionado, 'sucursal' => $sucursal_id]);
    $patioValido = $stmtCheckPatio->fetch(PDO::FETCH_ASSOC);

    if (!$patioValido) {
        $patioSeleccionado = 0;
        $bloqueSeleccionado = 0;
    } else {
        $nombrePatioSeleccionado = $patioValido['nombre'];

        $stmtBloques = $pdo->prepare('
            SELECT
                b.id,
                b.numero AS bloque,
                COALESCE(COUNT(DISTINCT c.id), 0) AS capacidad_actual,
                b.capacidad_maxima
            FROM bloques b
            LEFT JOIN contenedores c
                ON c.bloque_id = b.id
               AND c.estado_id = 1
            WHERE b.patio_id = :patio_id
              AND b.estado_id = 1
            GROUP BY b.id, b.numero, b.capacidad_maxima
            ORDER BY b.numero
        ');
        $stmtBloques->execute(['patio_id' => $patioSeleccionado]);
        $bloques = $stmtBloques->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bloques as $index => $bloque) {
            $bloquesMap[(int)$bloque['id']] = $bloque;
            if ((int)$bloque['id'] === $bloqueSeleccionado) {
                $bloqueActual = $index;
                $nombreBloqueSeleccionado = $bloque['bloque'];
            }
        }

        if (!$bloqueSeleccionado && count($bloques) > 0) {
            header('Location: ?patio_id=' . $patioSeleccionado . '&bloque_id=' . (int)$bloques[0]['id']);
            exit;
        }

        if ($bloqueSeleccionado && !isset($bloquesMap[$bloqueSeleccionado])) {
            if (count($bloques) > 0) {
                header('Location: ?patio_id=' . $patioSeleccionado . '&bloque_id=' . (int)$bloques[0]['id']);
                exit;
            }
            $bloqueSeleccionado = 0;
        }
    }
}

$stmtTodosContenedores = $pdo->prepare("
    SELECT
        c.nombre,
        c.posicion,
        eu.nombre AS estatus,
        eb.nombre AS estatus_box,
        ei.nombre AS estatus_ipsa,
        DATE_FORMAT(c.fecha_ingreso, '%d/%m/%Y %H:%i') AS fecha_ingreso,
        b.id AS bloque_id,
        b.numero AS bloque_numero,
        p.id AS patio_id,
        p.nombre AS patio_nombre
    FROM contenedores c
    JOIN bloques b ON c.bloque_id = b.id
    JOIN patios p ON b.patio_id = p.id
    LEFT JOIN estatus_contenedores eu ON c.estatus_unidad_id = eu.id
    LEFT JOIN estatus_contenedores eb ON c.estatus_box_id = eb.id
    LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id
    WHERE c.estado_id = 1
      AND p.sucursal_id = :sucursal
    ORDER BY p.nombre, b.numero, c.posicion DESC
");
$stmtTodosContenedores->execute(['sucursal' => $sucursal_id]);
$todosContenedores = $stmtTodosContenedores->fetchAll(PDO::FETCH_ASSOC);

$contenedores = [];
if ($patioSeleccionado && $bloqueSeleccionado) {
    $stmtContenedores = $pdo->prepare("
        SELECT
            c.posicion,
            c.nombre,
            eu.nombre AS estatus,
            eb.nombre AS estatus_box,
            ei.nombre AS estatus_ipsa,
            DATE_FORMAT(c.fecha_ingreso, '%d/%m/%Y %H:%i') AS fecha_ingreso
        FROM contenedores c
        JOIN bloques b ON c.bloque_id = b.id
        JOIN patios p ON b.patio_id = p.id
        LEFT JOIN estatus_contenedores eu ON c.estatus_unidad_id = eu.id
        LEFT JOIN estatus_contenedores eb ON c.estatus_box_id = eb.id
        LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id
        WHERE c.estado_id = 1
          AND c.bloque_id = :bloque
          AND b.patio_id = :patio
          AND p.sucursal_id = :sucursal
        ORDER BY c.posicion DESC
    ");
    $stmtContenedores->execute([
        'bloque' => $bloqueSeleccionado,
        'patio' => $patioSeleccionado,
        'sucursal' => $sucursal_id
    ]);
    $contenedores = $stmtContenedores->fetchAll(PDO::FETCH_ASSOC);
}

$numBotonesMostrados = 10;
$totalBloques = count($bloques);
$rangoInicio = 0;
$rangoFin = min($numBotonesMostrados, $totalBloques);

if ($totalBloques > 0) {
    if ($bloqueActual >= 8) {
        $rangoInicio = $bloqueActual - 7;
        $rangoFin = min($bloqueActual + 3, $totalBloques);
    }

    if ($rangoFin - $rangoInicio < $numBotonesMostrados && $totalBloques >= $numBotonesMostrados) {
        if ($bloqueActual < $totalBloques - 2) {
            $rangoFin = min($rangoInicio + $numBotonesMostrados, $totalBloques);
        } else {
            $rangoInicio = max(0, $totalBloques - $numBotonesMostrados);
            $rangoFin = $totalBloques;
        }
    }
}

$capacidadActual = count($contenedores);
$capacidadMaxima = 0;
if ($bloqueSeleccionado && isset($bloquesMap[$bloqueSeleccionado])) {
    $capacidadMaxima = (int)$bloquesMap[$bloqueSeleccionado]['capacidad_maxima'];
}
$ocupacion = $capacidadMaxima > 0 ? round(($capacidadActual / $capacidadMaxima) * 100) : 0;

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

function badgeClass(?string $value, string $type = 'generic'): string
{
    $value = strtoupper(trim((string)$value));
    $base = 'status-badge';

    if ($value === '') {
        return $base . ' badge-neutral';
    }

    return match ($type) {
        'unit' => match ($value) {
            'OK', 'DISPONIBLE', 'OPERATIVO' => $base . ' badge-success',
            'DMG', 'DAMAGE', 'DANADO', 'DANADO/A' => $base . ' badge-warning',
            default => $base . ' badge-neutral',
        },
        'box' => match ($value) {
            'OK', 'OPERATIVO' => $base . ' badge-success',
            'DMG', 'MAL', 'DANADO', 'DANADO/A' => $base . ' badge-warning',
            default => $base . ' badge-neutral',
        },
        'ipsa' => match ($value) {
            'DISPONIBLE', 'DISP' => $base . ' badge-success',
            'OOS', 'REPARACION', 'REPARACIÓN' => $base . ' badge-danger',
            'PENDIENTE', 'PEND' => $base . ' badge-warning',
            default => $base . ' badge-neutral',
        },
        default => $base . ' badge-neutral',
    };
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Bloques - Proportsa</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/parametros/bloques/bloques.css">
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

        <main class="dashboard-main bloques-main">
            <section class="panel-card bloques-module">
                <div class="module-head">
                <div class="view-header-copy">
                    <h1>Configuracion de Bloques</h1>
                    <p>Consulta ocupacion, recorre bloques por patio y navega a los movimientos del inventario interno.</p>
                </div>
                </div>
                <div class="module-toolbar">
                <div class="bloques-toolbar">
                    <div class="toolbar-search">
                        <input type="text" id="buscarInput" placeholder="Buscar contenedores..." autocomplete="off">
                    </div>
                    <div class="module-filters">
                        <div class="patio-dropdown" id="patioDropdown">
                            <button type="button" class="patio-trigger" id="patioTrigger" aria-haspopup="listbox" aria-expanded="false">
                                <span class="patio-trigger-copy">
                                    <small>Patio</small>
                                    <strong id="patioTriggerValue"><?php echo $nombrePatioSeleccionado ? htmlspecialchars($nombrePatioSeleccionado) : 'Seleccionar Patio'; ?></strong>
                                </span>
                                <span class="patio-trigger-caret" aria-hidden="true">&#9662;</span>
                            </button>
                            <div class="patio-menu" id="patioMenu" role="listbox">
                                <?php foreach ($patios as $patio): ?>
                                    <a href="?patio_id=<?php echo (int)$patio['id']; ?>" class="patio-option <?php echo ($patioSeleccionado === (int)$patio['id']) ? 'active' : ''; ?>" role="option" aria-selected="<?php echo ($patioSeleccionado === (int)$patio['id']) ? 'true' : 'false'; ?>">
                                        <?php echo htmlspecialchars($patio['nombre']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php if ($totalBloques > 0): ?>
                        <form method="GET" class="inline-block-selector toolbar-block-selector">
                            <input type="hidden" name="patio_id" value="<?php echo $patioSeleccionado; ?>">
                            <div class="block-select-shell compact">
                                <select name="bloque_id" id="bloqueSelector" class="block-select compact" onchange="this.form.submit()">
                                    <?php foreach ($bloques as $bloque): ?>
                                        <option value="<?php echo (int)$bloque['id']; ?>" <?php echo ((int)$bloque['id'] === $bloqueSeleccionado) ? 'selected' : ''; ?>>
                                            Bloque <?php echo htmlspecialchars($bloque['bloque']); ?> - <?php echo ((int)$bloque['id'] === $bloqueSeleccionado) ? $capacidadActual : (int)$bloque['capacidad_actual']; ?>/<?php echo (int)$bloque['capacidad_maxima']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    <?php endif; ?>
                        <a href="../ingresos/ingresos.php" class="toolbar-button toolbar-button-primary">
                            <img src="../imagenes/nuevo.png" alt="" class="toolbar-icon">
                            <span>Nuevo</span>
                        </a>
                    </div>
                </div>

                <div class="bloques-table-panel">
                <div class="table-panel-head">
                    <div class="table-panel-heading">
                        <h2>Inventario del Bloque</h2>
                        <p id="tableStatusText"><?php echo $bloqueSeleccionado ? 'Contenedores del bloque seleccionado.' : 'Selecciona un patio para comenzar.'; ?></p>
                    </div>
                </div>

                <?php if (!$patioSeleccionado): ?>
                <div class="table-empty-state">
                    <div class="empty-state-icon" aria-hidden="true">
                        <span></span>
                        <span></span>
                    </div>
                    <div class="empty-state-copy">
                        <h3>Selecciona un patio para comenzar</h3>
                        <p>El inventario del bloque aparecera aqui cuando elijas un patio y luego un bloque.</p>
                    </div>
                </div>
                <?php else: ?>
                <div class="table-wrap bloques-table-wrap">
                    <table class="bloques-table">
                        <thead>
                            <tr>
                                <th>Posicion</th>
                                <th>Nombre</th>
                                <th>Estatus Unidad</th>
                                <th>Estatus Box</th>
                                <th>Estatus IPSA</th>
                                <th>Fecha Ingreso</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="contenedoresTabla">
                            <?php if (count($contenedores) > 0): ?>
                                <?php foreach ($contenedores as $contenedor): ?>
                                    <?php
                                        $params = 'nombre=' . urlencode($contenedor['nombre']);
                                        if ($patioSeleccionado && $bloqueSeleccionado) {
                                            $params .= '&ref_patio=' . $patioSeleccionado . '&ref_bloque=' . $bloqueSeleccionado;
                                        }
                                    ?>
                                    <tr>
                                        <td data-label="Posicion"><span class="position-badge"><?php echo (int)$contenedor['posicion']; ?></span></td>
                                        <td data-label="Nombre"><span class="table-name"><?php echo htmlspecialchars($contenedor['nombre']); ?></span></td>
                                        <td data-label="Estatus Unidad"><span class="<?php echo badgeClass($contenedor['estatus'] ?? '', 'unit'); ?>"><?php echo htmlspecialchars($contenedor['estatus'] ?? ''); ?></span></td>
                                        <td data-label="Estatus Box"><span class="<?php echo badgeClass($contenedor['estatus_box'] ?? '', 'box'); ?>"><?php echo htmlspecialchars($contenedor['estatus_box'] ?? ''); ?></span></td>
                                        <td data-label="Estatus IPSA"><span class="<?php echo badgeClass($contenedor['estatus_ipsa'] ?? '', 'ipsa'); ?>"><?php echo htmlspecialchars($contenedor['estatus_ipsa'] ?? ''); ?></span></td>
                                        <td data-label="Fecha Ingreso"><span class="table-date"><?php echo htmlspecialchars($contenedor['fecha_ingreso'] ?? ''); ?></span></td>
                                        <td data-label="Acciones">
                                            <div class="table-actions">
                                                <a href="informacion_contenedor.php?<?php echo $params; ?>" class="table-action action-info">Informacion</a>
                                                <a href="../traslados/traslados.php?<?php echo $params; ?>" class="table-action action-move">Trasladar</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="table-empty-cell">No hay contenedores registrados para este bloque.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                </div>
            </section>

        </main>
    </div>
</div>

<script>
const currentBlockContainers = <?php echo json_encode($contenedores, JSON_UNESCAPED_UNICODE); ?>;
const allContainers = <?php echo json_encode($todosContenedores, JSON_UNESCAPED_UNICODE); ?>;

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
    const patioDropdown = document.getElementById('patioDropdown');
    const patioTrigger = document.getElementById('patioTrigger');
    const patioMenu = document.getElementById('patioMenu');
    const buscarInput = document.getElementById('buscarInput');
    const contenedoresTabla = document.getElementById('contenedoresTabla');
    const tableStatusText = document.getElementById('tableStatusText');
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
        if (patioDropdown && patioTrigger) {
            patioDropdown.classList.remove('open');
            patioTrigger.setAttribute('aria-expanded', 'false');
        }
        const isOpen = userMenu.classList.toggle('open');
        userMenuTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
        if (!userMenu.contains(event.target)) {
            userMenu.classList.remove('open');
            userMenuTrigger.setAttribute('aria-expanded', 'false');
        }
        if (patioDropdown && !patioDropdown.contains(event.target)) {
            patioDropdown.classList.remove('open');
            patioTrigger.setAttribute('aria-expanded', 'false');
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
            if (patioDropdown && patioTrigger) {
                patioDropdown.classList.remove('open');
                patioTrigger.setAttribute('aria-expanded', 'false');
            }
        }
    });

    if (sucursalDropdown && sucursalTrigger && sucursalMenu && sucursalValue && sucursalInput) {
        const sucursalOptions = sucursalMenu.querySelectorAll('.topbar-sucursal-option');
        sucursalTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            userMenu.classList.remove('open');
            userMenuTrigger.setAttribute('aria-expanded', 'false');
            if (patioDropdown && patioTrigger) {
                patioDropdown.classList.remove('open');
                patioTrigger.setAttribute('aria-expanded', 'false');
            }
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

    if (patioDropdown && patioTrigger && patioMenu) {
        patioTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            userMenu.classList.remove('open');
            userMenuTrigger.setAttribute('aria-expanded', 'false');
            if (sucursalDropdown && sucursalTrigger) {
                sucursalDropdown.classList.remove('open');
                sucursalTrigger.setAttribute('aria-expanded', 'false');
            }
            const isOpen = patioDropdown.classList.toggle('open');
            patioTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    function buildActions(contenedor) {
        const params = new URLSearchParams({
            nombre: contenedor.nombre,
            ref_patio: contenedor.patio_id,
            ref_bloque: contenedor.bloque_id
        }).toString();

        return `
            <div class="table-actions">
                <a href="informacion_contenedor.php?${params}" class="table-action action-info">Informacion</a>
                <a href="../traslados/traslados.php?${params}" class="table-action action-move">Trasladar</a>
            </div>
        `;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function badgeClass(value, type) {
        const normalized = String(value || '').trim().toUpperCase();
        if (!normalized) return 'status-badge badge-neutral';

        if (type === 'unit') {
            if (['OK', 'DISPONIBLE', 'OPERATIVO'].includes(normalized)) return 'status-badge badge-success';
            if (['DMG', 'DAMAGE', 'DANADO', 'DAÑADO'].includes(normalized)) return 'status-badge badge-warning';
        }

        if (type === 'box') {
            if (['OK', 'OPERATIVO'].includes(normalized)) return 'status-badge badge-success';
            if (['DMG', 'MAL', 'DANADO', 'DAÑADO'].includes(normalized)) return 'status-badge badge-warning';
        }

        if (type === 'ipsa') {
            if (['DISPONIBLE', 'DISP'].includes(normalized)) return 'status-badge badge-success';
            if (['OOS', 'REPARACION', 'REPARACIÓN'].includes(normalized)) return 'status-badge badge-danger';
            if (['PENDIENTE', 'PEND'].includes(normalized)) return 'status-badge badge-warning';
        }

        return 'status-badge badge-neutral';
    }

    function renderRows(rows, searching) {
        contenedoresTabla.innerHTML = '';
        if (!rows.length) {
            contenedoresTabla.innerHTML = `<tr><td colspan="7" class="table-empty-cell">${searching ? 'No se encontraron contenedores.' : 'No hay contenedores registrados para este bloque.'}</td></tr>`;
            return;
        }

        rows.forEach(function (contenedor) {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td data-label="Posicion"><span class="position-badge">${escapeHtml(contenedor.posicion ?? '')}</span></td>
                <td data-label="Nombre"><span class="table-name">${escapeHtml(contenedor.nombre ?? '')}</span></td>
                <td data-label="Estatus Unidad"><span class="${badgeClass(contenedor.estatus, 'unit')}">${escapeHtml(contenedor.estatus ?? '')}</span></td>
                <td data-label="Estatus Box"><span class="${badgeClass(contenedor.estatus_box, 'box')}">${escapeHtml(contenedor.estatus_box ?? '')}</span></td>
                <td data-label="Estatus IPSA"><span class="${badgeClass(contenedor.estatus_ipsa, 'ipsa')}">${escapeHtml(contenedor.estatus_ipsa ?? '')}</span></td>
                <td data-label="Fecha Ingreso"><span class="table-date">${escapeHtml(contenedor.fecha_ingreso ?? '')}</span></td>
                <td data-label="Acciones">${buildActions(contenedor)}</td>
            `;

            if (searching && contenedor.patio_id && contenedor.bloque_id) {
                tr.classList.add('search-row');
                tr.addEventListener('click', function (event) {
                    if (event.target.closest('a')) return;
                    window.location.href = `?patio_id=${contenedor.patio_id}&bloque_id=${contenedor.bloque_id}`;
                });
            }

            contenedoresTabla.appendChild(tr);
        });
    }

    buscarInput.addEventListener('input', function () {
        const value = buscarInput.value.trim().toLowerCase();

        if (!value) {
            tableStatusText.textContent = <?php echo json_encode($bloqueSeleccionado ? 'Contenedores del bloque seleccionado.' : 'Selecciona un patio para comenzar.'); ?>;
            renderRows(currentBlockContainers, false);
            return;
        }

        const filtered = allContainers.filter(function (contenedor) {
            return (contenedor.nombre || '').toLowerCase().includes(value);
        });

        tableStatusText.textContent = `Busqueda global en sucursal: ${filtered.length} resultado(s).`;
        renderRows(filtered, true);
    });

    syncThemeToggle();
});
</script>
</body>
</html>
