<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require 'config/database.php';

$rol_id = (int)$_SESSION['rol_id'];
$username = $_SESSION['username'] ?? 'Usuario';
$esInternoDashboard = in_array($rol_id, [1, 2, 3], true);
$esPypDashboard = ($rol_id === 5);
$sucursales = [];
$nombreSucursalActiva = "Sin asignar";

if ($rol_id == 1) {
    $stmtSuc = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursales = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);

    if (!isset($_SESSION['sucursal_activa']) && count($sucursales) > 0) {
        $_SESSION['sucursal_activa'] = $sucursales[0]['id'];
    }
}

if (!isset($_SESSION['sucursal_activa']) && isset($_SESSION['sucursal_usuario'])) {
    $_SESSION['sucursal_activa'] = $_SESSION['sucursal_usuario'];
}

$sucursal_activa = $_SESSION['sucursal_activa'] ?? null;

if ($sucursal_activa) {
    $stmtNom = $pdo->prepare("SELECT nombre FROM sucursales WHERE id = :id");
    $stmtNom->execute(['id' => $sucursal_activa]);
    $nombreSucursalActiva = $stmtNom->fetchColumn() ?: "Desconocida";
}

$dashboard = [
    'activos' => 0,
    'despachados' => 0,
    'disponibles' => 0,
    'pendientes' => 0,
    'oos' => 0,
    'auditados' => 0,
    'ocupados' => 0,
    'capacidad' => 0
];

$ocupacionPatios = [];
$actividadReciente = [];

if ($sucursal_activa) {
    $stmtDashboard = $pdo->prepare("
        SELECT
            SUM(CASE WHEN c.estado_id = 1 THEN 1 ELSE 0 END) AS activos,
            SUM(CASE WHEN c.estado_id = 2 THEN 1 ELSE 0 END) AS despachados,
            SUM(CASE WHEN c.estado_id = 1 AND ei.nombre = 'Disponible' THEN 1 ELSE 0 END) AS disponibles,
            SUM(CASE WHEN c.estado_id = 1 AND ei.nombre = 'Pendiente' THEN 1 ELSE 0 END) AS pendientes,
            SUM(CASE WHEN c.estado_id = 1 AND ei.nombre = 'OOS' THEN 1 ELSE 0 END) AS oos,
            SUM(CASE WHEN c.estado_id = 1 AND pa.estatus_pyp IS NOT NULL AND pa.estatus_pyp <> '' THEN 1 ELSE 0 END) AS auditados
        FROM contenedores c
        JOIN bloques b ON c.bloque_id = b.id
        JOIN patios p ON b.patio_id = p.id
        LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id
        LEFT JOIN pyp_auditoria pa ON c.id = pa.contenedor_id AND pa.estado_id = 1
        WHERE p.sucursal_id = :sucursal
    ");
    $stmtDashboard->execute(['sucursal' => $sucursal_activa]);
    $dashboard = array_merge($dashboard, $stmtDashboard->fetch(PDO::FETCH_ASSOC) ?: []);

    $stmtCapacidad = $pdo->prepare("
        SELECT
            (
                SELECT COUNT(*)
                FROM contenedores c
                JOIN bloques b ON c.bloque_id = b.id
                JOIN patios p ON b.patio_id = p.id
                WHERE p.sucursal_id = :sucursal
                  AND c.estado_id = 1
                  AND b.estado_id = 1
                  AND p.estado_id = 1
            ) AS ocupados,
            (
                SELECT COALESCE(SUM(b.capacidad_maxima), 0)
                FROM bloques b
                JOIN patios p ON b.patio_id = p.id
                WHERE p.sucursal_id = :sucursal
                  AND b.estado_id = 1
                  AND p.estado_id = 1
            ) AS capacidad
    ");
    $stmtCapacidad->execute(['sucursal' => $sucursal_activa]);
    $dashboard = array_merge($dashboard, $stmtCapacidad->fetch(PDO::FETCH_ASSOC) ?: []);

    $stmtPatios = $pdo->prepare("
        SELECT
            p.nombre,
            COALESCE(ocupacion.ocupados, 0) AS ocupados,
            COALESCE(capacidad.capacidad, 0) AS capacidad
        FROM patios p
        LEFT JOIN (
            SELECT
                b.patio_id,
                COUNT(c.id) AS ocupados
            FROM bloques b
            LEFT JOIN contenedores c ON b.id = c.bloque_id AND c.estado_id = 1
            WHERE b.estado_id = 1
            GROUP BY b.patio_id
        ) ocupacion ON p.id = ocupacion.patio_id
        LEFT JOIN (
            SELECT
                b.patio_id,
                SUM(b.capacidad_maxima) AS capacidad
            FROM bloques b
            WHERE b.estado_id = 1
            GROUP BY b.patio_id
        ) capacidad ON p.id = capacidad.patio_id
        WHERE p.sucursal_id = :sucursal AND p.estado_id = 1
        ORDER BY p.nombre ASC
    ");
    $stmtPatios->execute(['sucursal' => $sucursal_activa]);
    $ocupacionPatios = $stmtPatios->fetchAll(PDO::FETCH_ASSOC);

    if ($esInternoDashboard) {
        $stmtReciente = $pdo->prepare("
            SELECT
                le.fecha_evento,
                t.nombre AS operacion,
                c.nombre AS contenedor,
                u.username AS usuario,
                le.observaciones
            FROM logs_eventos le
            JOIN tipos_operacion t ON le.tipo_operacion_id = t.id
            LEFT JOIN contenedores c ON le.contenedor_id = c.id
            LEFT JOIN users u ON le.usuario_id = u.id
            LEFT JOIN bloques b ON c.bloque_id = b.id
            LEFT JOIN patios p ON b.patio_id = p.id
            WHERE p.sucursal_id = :sucursal
            ORDER BY le.fecha_evento DESC
            LIMIT 8
        ");
        $stmtReciente->execute(['sucursal' => $sucursal_activa]);
        $actividadReciente = $stmtReciente->fetchAll(PDO::FETCH_ASSOC);
    }
}

$porcentajeOcupacion = 0;
if ((int)$dashboard['capacidad'] > 0) {
    $porcentajeOcupacion = round(((int)$dashboard['ocupados'] / (int)$dashboard['capacidad']) * 100);
}

$dashboardCards = [
    [
        'accent' => 'accent-primary',
        'label' => 'Contenedores activos',
        'value' => (int)$dashboard['activos'],
        'foot' => 'Inventario actual en sucursal'
    ],
    [
        'accent' => 'accent-green',
        'label' => 'Disponibles',
        'value' => (int)$dashboard['disponibles'],
        'foot' => 'Listos para operacion'
    ],
    [
        'accent' => 'accent-amber',
        'label' => 'Pendientes',
        'value' => (int)$dashboard['pendientes'],
        'foot' => 'Esperando seguimiento'
    ],
    [
        'accent' => 'accent-red',
        'label' => 'OOS / reparacion',
        'value' => (int)$dashboard['oos'],
        'foot' => 'Fuera de servicio'
    ]
];

if ($esInternoDashboard) {
    $dashboardCards[] = [
        'accent' => 'accent-slate',
        'label' => 'Despachados',
        'value' => (int)$dashboard['despachados'],
        'foot' => 'Historial acumulado'
    ];
    $dashboardCards[] = [
        'accent' => 'accent-cyan',
        'label' => 'Auditados PYP',
        'value' => (int)$dashboard['auditados'],
        'foot' => 'Activos con seguimiento'
    ];
} elseif ($esPypDashboard) {
    $dashboardCards[] = [
        'accent' => 'accent-cyan',
        'label' => 'Auditados PYP',
        'value' => (int)$dashboard['auditados'],
        'foot' => 'Activos con seguimiento'
    ];
}

$menuItems = [];
if (in_array($rol_id, [1, 2, 3])) {
    $menuItems[] = ['label' => 'Contenedores', 'href' => 'bloques/bloques.php', 'icon' => 'imagenes/bloques.png'];
}
$menuItems[] = ['label' => 'Seguimiento PYP', 'href' => 'bloques/contenedor.php', 'icon' => 'imagenes/cont.png'];
if (in_array($rol_id, [1, 2, 3])) {
    $menuItems[] = ['label' => 'Traslados', 'href' => 'traslados/traslados.php', 'icon' => 'imagenes/traslados.png'];
    $menuItems[] = ['label' => 'Despachos', 'href' => 'despachos/despachos.php', 'icon' => 'imagenes/egresos.png'];
}
if (in_array($rol_id, [1, 2])) {
    $menuItems[] = ['label' => 'Reportes', 'href' => 'reportes/reportes.php', 'icon' => 'imagenes/reportes.png'];
    $menuItems[] = ['label' => 'Parametros', 'href' => 'parametros/parametros.php', 'icon' => 'imagenes/parametros.png'];
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
    <title>Dashboard - Proportsa</title>
    <link rel="stylesheet" href="styles/index.css">
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
            <img src="imagenes/IPSA.png" alt="Logo" class="sidebar-logo">
            <div class="sidebar-brand-copy">
                <span class="sidebar-kicker">Sistema Portuario</span>
                <span class="sidebar-name">Proportsa</span>
            </div>
            <button class="sidebar-close" id="sidebarClose" type="button" aria-label="Cerrar menu">
                &times;
            </button>
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
                    <a href="logout.php" class="sidebar-mobile-user-logout">Cerrar sesion</a>
                </div>
            </div>

            <?php if ($rol_id == 1): ?>
                <form action="cambiar_sucursal.php" method="POST" class="sidebar-mobile-sucursal-form">
                    <input type="hidden" name="sucursal_id" id="sidebar_mobile_sucursal_id" value="<?php echo (int)$sucursal_activa; ?>">
                    <button type="button" class="sidebar-mobile-sucursal-trigger" id="sidebarMobileSucursalTrigger" aria-haspopup="listbox" aria-expanded="false">
                        <span class="sidebar-mobile-sucursal-copy">
                            <small>Sucursal</small>
                            <strong id="sidebarMobileSucursalValue"><?php echo htmlspecialchars($nombreSucursalActiva); ?></strong>
                        </span>
                        <span class="sidebar-mobile-sucursal-caret" aria-hidden="true">&#9662;</span>
                    </button>

                    <div class="sidebar-mobile-sucursal-menu" id="sidebarMobileSucursalMenu" role="listbox">
                        <?php foreach ($sucursales as $suc): ?>
                            <button
                                type="button"
                                class="sidebar-mobile-sucursal-option <?php echo ($sucursal_activa == $suc['id']) ? 'active' : ''; ?>"
                                data-value="<?php echo (int)$suc['id']; ?>"
                                role="option"
                                aria-selected="<?php echo ($sucursal_activa == $suc['id']) ? 'true' : 'false'; ?>"
                            >
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
                    <span class="theme-icon theme-sun">â˜€</span>
                    <span class="theme-icon theme-moon">â˜¾</span>
                </button>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="index.php" class="nav-item active">
                <span class="nav-icon dashboard-dot"></span>
                <span class="nav-label">Dashboard</span>
            </a>
            <?php foreach ($menuItems as $item): ?>
                <a href="<?php echo htmlspecialchars($item['href']); ?>" class="nav-item">
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
                    <?php if ($rol_id == 1): ?>
                        <form action="cambiar_sucursal.php" method="POST" class="topbar-sucursal-form">
                            <span class="topbar-sucursal-label">Sucursal</span>
                            <div class="topbar-sucursal-dropdown" id="sucursalDropdown">
                                <input type="hidden" name="sucursal_id" id="sucursal_id" value="<?php echo (int)$sucursal_activa; ?>">
                                <button type="button" class="topbar-sucursal-trigger" id="sucursalTrigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="topbar-sucursal-value" id="sucursalValue"><?php echo htmlspecialchars($nombreSucursalActiva); ?></span>
                                    <span class="topbar-sucursal-caret" aria-hidden="true">&#9662;</span>
                                </button>

                                <div class="topbar-sucursal-menu" id="sucursalMenu" role="listbox">
                                    <?php foreach ($sucursales as $suc): ?>
                                        <button
                                            type="button"
                                            class="topbar-sucursal-option <?php echo ($sucursal_activa == $suc['id']) ? 'active' : ''; ?>"
                                            data-value="<?php echo (int)$suc['id']; ?>"
                                            role="option"
                                            aria-selected="<?php echo ($sucursal_activa == $suc['id']) ? 'true' : 'false'; ?>"
                                        >
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
                    <span class="theme-icon theme-sun">☀</span>
                    <span class="theme-icon theme-moon">☾</span>
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

                        <div class="mobile-user-sucursal">
                            <?php if ($rol_id == 1): ?>
                                <form action="cambiar_sucursal.php" method="POST" class="mobile-user-sucursal-form">
                                    <input type="hidden" name="sucursal_id" id="mobile_sucursal_id" value="<?php echo (int)$sucursal_activa; ?>">
                                    <button type="button" class="mobile-user-sucursal-trigger" id="mobileSucursalTrigger" aria-haspopup="listbox" aria-expanded="false">
                                        <span class="mobile-user-sucursal-copy">
                                            <small>Sucursal</small>
                                            <strong id="mobileSucursalValue"><?php echo htmlspecialchars($nombreSucursalActiva); ?></strong>
                                        </span>
                                        <span class="mobile-user-sucursal-caret" aria-hidden="true">&#9662;</span>
                                    </button>

                                    <div class="mobile-user-sucursal-menu" id="mobileSucursalMenu" role="listbox">
                                        <?php foreach ($sucursales as $suc): ?>
                                            <button
                                                type="button"
                                                class="mobile-user-sucursal-option <?php echo ($sucursal_activa == $suc['id']) ? 'active' : ''; ?>"
                                                data-value="<?php echo (int)$suc['id']; ?>"
                                                role="option"
                                                aria-selected="<?php echo ($sucursal_activa == $suc['id']) ? 'true' : 'false'; ?>"
                                            >
                                                <?php echo htmlspecialchars($suc['nombre']); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="mobile-user-sucursal-static">
                                    <small>Sucursal</small>
                                    <strong><?php echo htmlspecialchars($nombreSucursalActiva); ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>

                        <a href="logout.php" class="user-menu-item user-menu-logout">Cerrar sesion</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="dashboard-main">
            <section class="view-header">
                <div class="view-header-copy">
                    <h1>Dashboard</h1>
                    <p>Vista general de la operacion en <?php echo htmlspecialchars($nombreSucursalActiva); ?></p>
                </div>
            </section>

            <section class="stats-grid">
                <?php foreach ($dashboardCards as $card): ?>
                    <article class="stat-card <?php echo htmlspecialchars($card['accent']); ?>">
                        <span class="stat-label"><?php echo htmlspecialchars($card['label']); ?></span>
                        <strong class="stat-value"><?php echo (int)$card['value']; ?></strong>
                        <small class="stat-foot"><?php echo htmlspecialchars($card['foot']); ?></small>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="dashboard-grid">
                <article class="panel-card occupancy-panel">
                    <div class="panel-header">
                        <div>
                            <h2>Ocupacion general</h2>
                            <p>Capacidad utilizada en la sucursal activa</p>
                        </div>
                        <div class="occupancy-badge"><?php echo $porcentajeOcupacion; ?>%</div>
                    </div>

                    <div class="occupancy-track">
                        <div class="occupancy-fill" style="width: <?php echo max(0, min(100, $porcentajeOcupacion)); ?>%;"></div>
                    </div>

                    <div class="occupancy-meta">
                        <div>
                            <span class="meta-label">Ocupados</span>
                            <strong><?php echo (int)$dashboard['ocupados']; ?></strong>
                        </div>
                        <div>
                            <span class="meta-label">Capacidad total</span>
                            <strong><?php echo (int)$dashboard['capacidad']; ?></strong>
                        </div>
                    </div>
                </article>

                <?php if ($esInternoDashboard): ?>
                    <article class="panel-card table-panel">
                        <div class="panel-header">
                            <div>
                                <h2>Actividad reciente</h2>
                                <p>Ultimos movimientos registrados en la sucursal</p>
                            </div>
                        </div>

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Operacion</th>
                                        <th>Contenedor</th>
                                        <th>Usuario</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($actividadReciente) > 0): ?>
                                        <?php foreach ($actividadReciente as $evento): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($evento['fecha_evento']))); ?></td>
                                                <td><?php echo htmlspecialchars($evento['operacion'] ?? 'N/D'); ?></td>
                                                <td><?php echo htmlspecialchars($evento['contenedor'] ?? 'Sin equipo'); ?></td>
                                                <td><?php echo htmlspecialchars($evento['usuario'] ?? 'Sistema'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="empty-row">No hay actividad reciente para mostrar.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                <?php endif; ?>

                <article class="panel-card patios-panel">
                    <div class="panel-header">
                        <div>
                            <h2>Ocupacion por patio</h2>
                            <p>Resumen rapido por area operativa</p>
                        </div>
                    </div>

                    <div class="patio-list">
                        <?php if (count($ocupacionPatios) > 0): ?>
                            <?php foreach ($ocupacionPatios as $patio): ?>
                                <?php
                                    $patioCapacidad = (int)$patio['capacidad'];
                                    $patioOcupados = (int)$patio['ocupados'];
                                    $patioPorcentaje = $patioCapacidad > 0 ? round(($patioOcupados / $patioCapacidad) * 100) : 0;
                                ?>
                                <div class="patio-item">
                                    <div class="patio-head">
                                        <strong><?php echo htmlspecialchars($patio['nombre']); ?></strong>
                                        <span><?php echo $patioOcupados; ?>/<?php echo $patioCapacidad; ?></span>
                                    </div>
                                    <div class="mini-track">
                                        <div class="mini-fill" style="width: <?php echo max(0, min(100, $patioPorcentaje)); ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="empty-copy">No hay patios activos para esta sucursal.</p>
                        <?php endif; ?>
                    </div>
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
    const mobileSucursalTrigger = document.getElementById('mobileSucursalTrigger');
    const mobileSucursalMenu = document.getElementById('mobileSucursalMenu');
    const mobileSucursalValue = document.getElementById('mobileSucursalValue');
    const mobileSucursalInput = document.getElementById('mobile_sucursal_id');
    const sidebarMobileUserMenu = document.getElementById('sidebarMobileUserMenu');
    const sidebarMobileUserTrigger = document.getElementById('sidebarMobileUserTrigger');
    const sidebarMobileUserDropdown = document.getElementById('sidebarMobileUserDropdown');
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

    sidebarClose.addEventListener('click', closeSidebar);
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
    if (sidebarThemeToggle) {
        sidebarThemeToggle.addEventListener('click', toggleTheme);
    }

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
                const selectedValue = option.getAttribute('data-value');
                const selectedLabel = option.textContent.trim();

                sucursalInput.value = selectedValue;
                sucursalValue.textContent = selectedLabel;

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

    if (mobileSucursalTrigger && mobileSucursalMenu && mobileSucursalValue && mobileSucursalInput) {
        const mobileSucursalOptions = mobileSucursalMenu.querySelectorAll('.mobile-user-sucursal-option');

        mobileSucursalTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            const isOpen = mobileSucursalMenu.classList.toggle('open');
            mobileSucursalTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        mobileSucursalOptions.forEach(function (option) {
            option.addEventListener('click', function () {
                const selectedValue = option.getAttribute('data-value');
                const selectedLabel = option.textContent.trim();

                mobileSucursalInput.value = selectedValue;
                mobileSucursalValue.textContent = selectedLabel;

                mobileSucursalOptions.forEach(function (item) {
                    item.classList.remove('active');
                    item.setAttribute('aria-selected', 'false');
                });

                option.classList.add('active');
                option.setAttribute('aria-selected', 'true');

                mobileSucursalMenu.classList.remove('open');
                mobileSucursalTrigger.setAttribute('aria-expanded', 'false');
                mobileSucursalInput.form.submit();
            });
        });

        document.addEventListener('click', function (event) {
            if (!mobileSucursalTrigger.closest('.mobile-user-sucursal').contains(event.target)) {
                mobileSucursalMenu.classList.remove('open');
                mobileSucursalTrigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    if (sidebarMobileUserMenu && sidebarMobileUserTrigger && sidebarMobileUserDropdown) {
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
                const selectedValue = option.getAttribute('data-value');
                const selectedLabel = option.textContent.trim();

                sidebarMobileSucursalInput.value = selectedValue;
                sidebarMobileSucursalValue.textContent = selectedLabel;

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
