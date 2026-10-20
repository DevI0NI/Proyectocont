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

$sqlPatios = 'SELECT id, nombre FROM patios WHERE estado_id = 1 AND sucursal_id = :sucursal_id ORDER BY id ASC';
$stmtPatios = $pdo->prepare($sqlPatios);
$stmtPatios->execute(['sucursal_id' => $sucursal_id]);
$patios = $stmtPatios->fetchAll(PDO::FETCH_ASSOC);

$sqlBloques = '
    SELECT b.id, b.patio_id, b.numero, b.capacidad_maxima
    FROM bloques b
    JOIN patios p ON b.patio_id = p.id
    WHERE b.estado_id = 1 AND p.sucursal_id = :sucursal_id
    ORDER BY p.nombre ASC, b.numero ASC
';
$stmtBloques = $pdo->prepare($sqlBloques);
$stmtBloques->execute(['sucursal_id' => $sucursal_id]);
$bloques = $stmtBloques->fetchAll(PDO::FETCH_ASSOC);

$bloquesPorPatio = [];
foreach ($bloques as $bloque) {
    $bloquesPorPatio[$bloque['patio_id']][] = $bloque;
}

$conteoBloquesPorPatio = [];
foreach ($bloquesPorPatio as $patioId => $bloquesPatio) {
    $conteoBloquesPorPatio[(int)$patioId] = count($bloquesPatio);
}

$totalPatios = count($patios);
$totalBloques = count($bloques);
$primerPatioId = $patios !== [] ? (int)$patios[0]['id'] : 0;

$sqlMaxBloques = '
    SELECT b.patio_id, COALESCE(MAX(b.numero), 0) AS max_numero
    FROM bloques b
    JOIN patios p ON b.patio_id = p.id
    WHERE p.sucursal_id = :sucursal_id
    GROUP BY b.patio_id
';
$stmtMaxBloques = $pdo->prepare($sqlMaxBloques);
$stmtMaxBloques->execute(['sucursal_id' => $sucursal_id]);
$maxNumeroPorPatio = [];
foreach ($stmtMaxBloques->fetchAll(PDO::FETCH_ASSOC) as $filaMax) {
    $maxNumeroPorPatio[(int)$filaMax['patio_id']] = (int)$filaMax['max_numero'];
}

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
    <title>Configuracion de Patios y Bloques</title>
    <link rel="stylesheet" href="../../styles/index.css">
    <link rel="stylesheet" href="../../styles/parametros/bloques/bloques.css">
    <link rel="stylesheet" href="../../styles/parametros/bloques/configuracion.css">
    <script>
        (function () {
            const savedTheme = localStorage.getItem('proportsa-theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <script>
      (function(){
        const pos = sessionStorage.getItem('scrollY');
        if (pos) {
          window.addEventListener('load', () => {
            window.scrollTo(0, parseInt(pos,10));
            sessionStorage.removeItem('scrollY');
          });
        }
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
                            <h1>Configuracion de Patios</h1>
                        </div>
                    </div>
                </div>

                <div class="config-body">
                    <section class="config-section config-panel is-active" id="panelPatios" data-panel="patios" role="tabpanel" aria-labelledby="tabPatios">
                        <div class="config-section-head">
                            <div>
                                <h2>Configurar Patios</h2>
                                <p>Administra patios por nombre, revisa sus bloques y manten el inventario ordenado.</p>
                            </div>
                            <div class="config-section-tools">
                                <label class="config-search" for="buscarPatio">
                                    <span>Buscar patio</span>
                                    <input type="search" id="buscarPatio" placeholder="Ej. Patio Norte">
                                </label>
                                <label class="config-order" for="ordenPatios">
                                    <span>Ordenar</span>
                                    <select id="ordenPatios">
                                        <option value="numero_asc">Patio 1-9</option>
                                        <option value="numero_desc">Patio 9-1</option>
                                        <option value="nombre_asc">A-Z</option>
                                        <option value="nombre_desc">Z-A</option>
                                    </select>
                                </label>
                                <button type="button" class="toolbar-button toolbar-button-primary" id="openCreatePatioModal">Nuevo Patio</button>
                            </div>
                        </div>

                        <div class="config-scroll-area patios-scroll-area">
                            <div class="table-wrap bloques-table-wrap">
                                <table class="bloques-table" id="patiosTable">
                                    <thead>
                                        <tr>
                                            <th>Patio</th>
                                            <th>Nombre</th>
                                            <th>Bloques</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody id="patiosTableBody">
                                    <?php foreach ($patios as $patioIndex => $patio): ?>
                                        <tr
                                            data-id="<?php echo $patio['id']; ?>"
                                            data-index="<?php echo $patioIndex + 1; ?>"
                                            data-name="<?php echo htmlspecialchars(mb_strtolower($patio['nombre']), ENT_QUOTES); ?>"
                                            data-label="<?php echo htmlspecialchars($patio['nombre'], ENT_QUOTES); ?>"
                                            data-bloques="<?php echo (int)($conteoBloquesPorPatio[(int)$patio['id']] ?? 0); ?>"
                                            data-patio-id="<?php echo $patio['id']; ?>"
                                        >
                                            <td><span class="table-badge">Patio <?php echo $patioIndex + 1; ?></span></td>
                                            <td><span class="table-primary"><?php echo htmlspecialchars($patio['nombre']); ?></span></td>
                                            <td><span class="table-muted"><?php echo (int)($conteoBloquesPorPatio[(int)$patio['id']] ?? 0); ?> bloque(s)</span></td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="configuracion_bloques.php?patio_id=<?php echo (int)$patio['id']; ?>" class="action-pill action-pill-info">Bloques</a>
                                                    <button type="button" class="action-pill action-pill-edit open-edit-patio" data-id="<?php echo $patio['id']; ?>" data-name="<?php echo htmlspecialchars($patio['nombre'], ENT_QUOTES); ?>">Editar</button>
                                                    <button type="button" class="action-pill action-pill-delete open-delete-patio" data-id="<?php echo $patio['id']; ?>" data-name="<?php echo htmlspecialchars($patio['nombre'], ENT_QUOTES); ?>" data-bloques="<?php echo (int)($conteoBloquesPorPatio[(int)$patio['id']] ?? 0); ?>">Eliminar</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <div class="modal-backdrop" id="patioModalBackdrop"></div>
                    <div class="modal-shell" id="patioModal" role="dialog" aria-modal="true" aria-labelledby="patioModalTitle">
                        <div class="modal-card">
                            <div class="modal-head">
                                <div class="modal-head-copy">
                                    <h2 id="patioModalTitle">Nuevo Patio</h2>
                                    <p>Guarda el nombre operativo del patio para esta sucursal.</p>
                                </div>
                                <button type="button" class="modal-close" id="closePatioModal" aria-label="Cerrar">&times;</button>
                            </div>
                            <form method="post" action="guardar_configuracion_patios.php" id="patioModalForm" class="sucursal-form">
                                <input type="hidden" name="action" id="patioModalAction" value="create_patio">
                                <input type="hidden" name="id" id="patioModalId" value="0">
                                <div class="form-row">
                                    <label for="patioModalNombre">Nombre del patio</label>
                                    <div class="field-stack">
                                        <input type="text" name="nombre" id="patioModalNombre" placeholder="Ej. Norte" required>
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="button" class="toolbar-button toolbar-button-secondary" id="cancelPatioModal">Cancelar</button>
                                    <button type="submit" class="toolbar-button toolbar-button-primary" id="savePatioModal">Guardar</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="modal-backdrop" id="deletePatioBackdrop"></div>
                    <div class="modal-shell modal-shell-compact" id="deletePatioModal" role="dialog" aria-modal="true" aria-labelledby="deletePatioTitle">
                        <div class="modal-card modal-card-compact">
                            <div class="modal-head modal-head-compact">
                                <div class="modal-head-copy">
                                    <h2 id="deletePatioTitle">Eliminar patio</h2>
                                    <p id="deletePatioMessage">Esta accion desactivara el patio seleccionado.</p>
                                </div>
                                <button type="button" class="modal-close" id="closeDeletePatioModal" aria-label="Cerrar">&times;</button>
                            </div>
                            <form method="post" action="guardar_configuracion_patios.php" class="confirm-actions">
                                <input type="hidden" name="action" value="delete_patio">
                                <input type="hidden" name="id" id="deletePatioId" value="0">
                                <button type="button" class="toolbar-button toolbar-button-secondary" id="cancelDeletePatioModal">Cancelar</button>
                                <button type="submit" class="toolbar-button toolbar-button-danger">Eliminar</button>
                            </form>
                        </div>
                    </div>

                    <section class="config-section config-panel" id="panelBloques" data-panel="bloques" role="tabpanel" aria-labelledby="tabBloques" hidden>
                        <div class="config-section-head">
                            <div>
                                <h2>Configurar Bloques</h2>
                                <p>Ajusta capacidad y estructura de bloques por cada patio activo.</p>
                            </div>
                            <div class="config-section-tools config-section-tools-wide">
                                <label class="config-search" for="buscarBloquePatio">
                                    <span>Buscar patio</span>
                                    <input type="search" id="buscarBloquePatio" placeholder="Filtra patios con bloques">
                                </label>
                                <div class="config-mini-actions">
                                    <button type="button" class="toolbar-button toolbar-button-secondary" id="expandirPatiosBloques">Expandir todo</button>
                                    <button type="button" class="toolbar-button toolbar-button-secondary" id="contraerPatiosBloques">Contraer todo</button>
                                </div>
                            </div>
                        </div>

                        <form method="post" action="guardar_configuracion_bloques.php" id="form-bloques" class="config-form">
                            <div class="bloques-groups config-scroll-area">
                                <?php foreach ($patios as $patio): ?>
                                    <article class="patio-block-card" data-bloque-patio="<?php echo htmlspecialchars(mb_strtolower($patio['nombre']), ENT_QUOTES); ?>">
                                        <button
                                            type="button"
                                            class="patio-block-toggle<?php echo (int)$patio['id'] === $primerPatioId ? ' is-open' : ''; ?>"
                                            data-patio-toggle="<?php echo $patio['id']; ?>"
                                            aria-expanded="<?php echo (int)$patio['id'] === $primerPatioId ? 'true' : 'false'; ?>"
                                            aria-controls="bloques-panel-<?php echo $patio['id']; ?>"
                                        >
                                            <div class="patio-block-toggle-copy">
                                                <h3><?php echo htmlspecialchars($patio['nombre']); ?></h3>
                                                <p><?php echo (int)($conteoBloquesPorPatio[(int)$patio['id']] ?? 0); ?> bloque(s) registrados</p>
                                            </div>
                                            <span class="patio-block-toggle-meta">
                                                <span class="patio-block-badge"><?php echo (int)($conteoBloquesPorPatio[(int)$patio['id']] ?? 0); ?></span>
                                                <span class="patio-block-caret" aria-hidden="true">&#9662;</span>
                                            </span>
                                        </button>

                                        <div class="patio-block-panel<?php echo (int)$patio['id'] === $primerPatioId ? ' is-open' : ''; ?>" id="bloques-panel-<?php echo $patio['id']; ?>">
                                            <div
                                                id="bloques-container-<?php echo $patio['id']; ?>"
                                                class="config-grid"
                                                data-next-number="<?php echo (int)(($maxNumeroPorPatio[(int)$patio['id']] ?? 0) + 1); ?>"
                                            >
                                                <?php if (isset($bloquesPorPatio[$patio['id']])): ?>
                                                    <?php foreach ($bloquesPorPatio[$patio['id']] as $bloque): ?>
                                                        <div class="checkbox-container config-row" data-id="<?php echo $bloque['id']; ?>" id="bloque-row-<?php echo $bloque['id']; ?>">
                                                            <label for="bloque-<?php echo $bloque['id']; ?>">Bloque <?php echo $bloque['numero']; ?></label>
                                                            <input
                                                                type="number"
                                                                id="bloque-<?php echo $bloque['id']; ?>"
                                                                name="bloques[<?php echo $bloque['id']; ?>]"
                                                                value="<?php echo $bloque['capacidad_maxima']; ?>"
                                                                disabled
                                                            >
                                                            <input
                                                                type="checkbox"
                                                                name="bloques_eliminar[]"
                                                                value="<?php echo $bloque['id']; ?>"
                                                                class="checkbox-bloque"
                                                            >
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="config-empty">No hay bloques registrados en este patio.</p>
                                                <?php endif; ?>

                                                <div class="config-actions bloques-buttons" id="bloques-buttons-<?php echo $patio['id']; ?>">
                                                    <button type="button" class="toolbar-button toolbar-button-secondary editar-bloque" data-patio-id="<?php echo $patio['id']; ?>">Editar</button>
                                                    <button type="button" class="toolbar-button toolbar-button-secondary agregar-bloque btn-cancel hidden" id="cancelar-editar-bloque-<?php echo $patio['id']; ?>" data-patio-id="<?php echo $patio['id']; ?>">Cancelar edicion</button>

                                                    <button type="button" class="toolbar-button toolbar-button-secondary agregar-bloque" data-patio-id="<?php echo $patio['id']; ?>">Agregar Bloque</button>
                                                    <button type="button" class="toolbar-button toolbar-button-secondary agregar-bloque btn-cancel hidden" id="cancelar-bloque-<?php echo $patio['id']; ?>" data-patio-id="<?php echo $patio['id']; ?>">Cancelar agregar</button>

                                                    <button type="button" class="toolbar-button toolbar-button-primary guardar-local hidden" id="guardar-bloques-<?php echo $patio['id']; ?>">Confirmar</button>

                                                    <button type="button" class="toolbar-button toolbar-button-danger eliminar-button" id="eliminar-bloque-<?php echo $patio['id']; ?>" disabled>Eliminar Bloque</button>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </form>
                    </section>
                </div>
            </section>
        </main>
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
const configTabs = document.querySelectorAll('.config-tab');
const configPanels = document.querySelectorAll('.config-panel');
const patioSearchInput = document.getElementById('buscarPatio');
const patioOrderSelect = document.getElementById('ordenPatios');
const patiosTableBody = document.getElementById('patiosTableBody');
const bloquesSearchInput = document.getElementById('buscarBloquePatio');
const expandirPatiosBloques = document.getElementById('expandirPatiosBloques');
const contraerPatiosBloques = document.getElementById('contraerPatiosBloques');

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

function activateConfigPanel(target) {
    configTabs.forEach((tab) => {
        const isActive = tab.dataset.target === target;
        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    configPanels.forEach((panel) => {
        const isActive = panel.dataset.panel === target;
        panel.classList.toggle('is-active', isActive);
        panel.hidden = !isActive;
    });
}

function setPatioBlockExpanded(patioId, expanded) {
    const toggleButton = document.querySelector(`[data-patio-toggle="${patioId}"]`);
    const panel = document.getElementById(`bloques-panel-${patioId}`);
    if (!toggleButton || !panel) return;

    toggleButton.classList.toggle('is-open', expanded);
    toggleButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    panel.classList.toggle('is-open', expanded);
}

function filterPatios(value) {
    const search = value.trim().toLowerCase();
    document.querySelectorAll('#patiosTableBody tr').forEach((row) => {
        const rowName = row.dataset.name || '';
        row.style.display = search !== '' && !rowName.includes(search) ? 'none' : '';
    });
}

function sortPatios(order) {
    if (!patiosTableBody) return;

    const rows = Array.from(patiosTableBody.querySelectorAll('tr'));
    rows.sort((a, b) => {
        const indexA = parseInt(a.dataset.index || '0', 10);
        const indexB = parseInt(b.dataset.index || '0', 10);
        const nameA = a.dataset.name || '';
        const nameB = b.dataset.name || '';

        if (order === 'numero_desc') return indexB - indexA;
        if (order === 'nombre_asc') return nameA.localeCompare(nameB, 'es');
        if (order === 'nombre_desc') return nameB.localeCompare(nameA, 'es');
        return indexA - indexB;
    });

    rows.forEach((row) => patiosTableBody.appendChild(row));
}

function filterBloquePatios(value) {
    const search = value.trim().toLowerCase();
    let firstVisibleId = null;

    document.querySelectorAll('.patio-block-card').forEach((card) => {
        const rowName = card.dataset.bloquePatio || '';
        const visible = search === '' || rowName.includes(search);
        card.hidden = !visible;

        if (visible && firstVisibleId === null) {
            const button = card.querySelector('[data-patio-toggle]');
            if (button) {
                firstVisibleId = button.dataset.patioToggle;
            }
        }
    });

    if (search !== '' && firstVisibleId !== null) {
        document.querySelectorAll('[data-patio-toggle]').forEach((button) => {
            setPatioBlockExpanded(button.dataset.patioToggle, button.dataset.patioToggle === firstVisibleId);
        });
    }
}

if (toastPayload && toastPayload.text) {
    window.addEventListener('DOMContentLoaded', function () {
        showToast(toastPayload.text, toastPayload.type || 'success');
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

configTabs.forEach((tab) => {
    tab.addEventListener('click', function () {
        activateConfigPanel(tab.dataset.target);
    });
});

if (patioSearchInput) {
    patioSearchInput.addEventListener('input', function () {
        filterPatios(this.value);
    });
}

if (patioOrderSelect) {
    patioOrderSelect.addEventListener('change', function () {
        sortPatios(this.value);
        if (patioSearchInput) {
            filterPatios(patioSearchInput.value);
        }
    });
}

if (bloquesSearchInput) {
    bloquesSearchInput.addEventListener('input', function () {
        filterBloquePatios(this.value);
    });
}

document.querySelectorAll('[data-patio-toggle]').forEach((button) => {
    button.addEventListener('click', function () {
        const patioId = button.dataset.patioToggle;
        const isOpen = button.getAttribute('aria-expanded') === 'true';
        setPatioBlockExpanded(patioId, !isOpen);
    });
});

expandirPatiosBloques?.addEventListener('click', function () {
    document.querySelectorAll('.patio-block-card:not([hidden]) [data-patio-toggle]').forEach((button) => {
        setPatioBlockExpanded(button.dataset.patioToggle, true);
    });
});

contraerPatiosBloques?.addEventListener('click', function () {
    document.querySelectorAll('.patio-block-card:not([hidden]) [data-patio-toggle]').forEach((button) => {
        setPatioBlockExpanded(button.dataset.patioToggle, false);
    });
});
</script>

<script>
  const show    = el => el && el.classList.remove('hidden');
  const hide    = el => el && el.classList.add('hidden');
  const disable = el => el && el.classList.add('disabled');
  const enable  = el => el && el.classList.remove('disabled');

  function submitAndKeepScroll(form) {
    sessionStorage.setItem('scrollY', String(window.scrollY));
    form.requestSubmit();
  }

  const openCreatePatioModal = document.getElementById('openCreatePatioModal');
  const patioModal = document.getElementById('patioModal');
  const patioModalBackdrop = document.getElementById('patioModalBackdrop');
  const closePatioModal = document.getElementById('closePatioModal');
  const cancelPatioModal = document.getElementById('cancelPatioModal');
  const patioModalTitle = document.getElementById('patioModalTitle');
  const patioModalAction = document.getElementById('patioModalAction');
  const patioModalId = document.getElementById('patioModalId');
  const patioModalNombre = document.getElementById('patioModalNombre');
  const deletePatioModal = document.getElementById('deletePatioModal');
  const deletePatioBackdrop = document.getElementById('deletePatioBackdrop');
  const closeDeletePatioModal = document.getElementById('closeDeletePatioModal');
  const cancelDeletePatioModal = document.getElementById('cancelDeletePatioModal');
  const deletePatioId = document.getElementById('deletePatioId');
  const deletePatioMessage = document.getElementById('deletePatioMessage');

  function openPatioModal(mode, payload = {}) {
    patioModalTitle.textContent = mode === 'edit' ? 'Editar Patio' : 'Nuevo Patio';
    patioModalAction.value = mode === 'edit' ? 'update_patio' : 'create_patio';
    patioModalId.value = payload.id || '0';
    patioModalNombre.value = payload.name || '';
    patioModal.classList.add('show');
    patioModalBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
    setTimeout(() => patioModalNombre.focus(), 40);
  }

  function closePatioModalFn() {
    patioModal.classList.remove('show');
    patioModalBackdrop.classList.remove('show');
    if (!deletePatioModal.classList.contains('show')) {
      document.body.classList.remove('modal-open');
    }
  }

  function openDeletePatioModal(id, name, bloques) {
    deletePatioId.value = id;
    deletePatioMessage.textContent = bloques > 0
      ? `El patio ${name} tiene ${bloques} bloque(s). Si siguen activos, no se podra eliminar.`
      : `Vas a desactivar el patio ${name}.`;
    deletePatioModal.classList.add('show');
    deletePatioBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
  }

  function closeDeletePatioModalFn() {
    deletePatioModal.classList.remove('show');
    deletePatioBackdrop.classList.remove('show');
    if (!patioModal.classList.contains('show')) {
      document.body.classList.remove('modal-open');
    }
  }

  openCreatePatioModal?.addEventListener('click', () => openPatioModal('create'));
  closePatioModal?.addEventListener('click', closePatioModalFn);
  cancelPatioModal?.addEventListener('click', closePatioModalFn);
  patioModalBackdrop?.addEventListener('click', closePatioModalFn);
  closeDeletePatioModal?.addEventListener('click', closeDeletePatioModalFn);
  cancelDeletePatioModal?.addEventListener('click', closeDeletePatioModalFn);
  deletePatioBackdrop?.addEventListener('click', closeDeletePatioModalFn);

  document.querySelectorAll('.open-edit-patio').forEach((button) => {
    button.addEventListener('click', () => {
      openPatioModal('edit', {
        id: button.dataset.id,
        name: button.dataset.name
      });
    });
  });

  document.querySelectorAll('.open-delete-patio').forEach((button) => {
    button.addEventListener('click', () => {
      openDeletePatioModal(button.dataset.id, button.dataset.name, parseInt(button.dataset.bloques || '0', 10));
    });
  });

  document.querySelectorAll('.open-bloques-panel').forEach((button) => {
    button.addEventListener('click', () => {
      const patioId = button.dataset.patioId || '';
      const patioNombre = button.dataset.patioNombre || '';

      activateConfigPanel('bloques');

      if (bloquesSearchInput) {
        bloquesSearchInput.value = patioNombre;
        filterBloquePatios(patioNombre);
      }

      document.querySelectorAll('[data-patio-toggle]').forEach((toggleButton) => {
        const isTarget = toggleButton.dataset.patioToggle === patioId;
        setPatioBlockExpanded(toggleButton.dataset.patioToggle, isTarget);
      });

      document.querySelector(`[data-patio-toggle="${patioId}"]`)?.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    });
  });

  const formBloques = document.getElementById('form-bloques');

  function insertBeforeButtons(container, node) {
    const buttonsEl = container.querySelector('.bloques-buttons');
    if (buttonsEl) container.insertBefore(node, buttonsEl); else container.appendChild(node);
  }

  const bloquesMode = {};
  const bloquesDeleteMode = {};

  function getMode(patioId){ return bloquesMode[patioId] || 'idle'; }
  function setMode(patioId, m){
    bloquesMode[patioId] = m;
    setPatioBlockExpanded(patioId, true);
    updateBloquesUI(patioId);
  }

  function updateBloquesUI(patioId){
    const contBtns      = document.getElementById(`bloques-buttons-${patioId}`);
    const btnEdit       = contBtns.querySelector(`.editar-bloque[data-patio-id="${patioId}"]`);
    const btnCancelEdit = document.getElementById(`cancelar-editar-bloque-${patioId}`);
    const btnAdd        = contBtns.querySelector(`.agregar-bloque:not(.btn-cancel)[data-patio-id="${patioId}"]`);
    const btnCancelAdd  = document.getElementById(`cancelar-bloque-${patioId}`);
    const btnGuardar    = document.getElementById(`guardar-bloques-${patioId}`);
    const btnEliminar   = document.getElementById(`eliminar-bloque-${patioId}`);
    const inputs        = document.querySelectorAll(`#bloques-container-${patioId} input[type="number"]`);

    [btnEdit, btnCancelEdit, btnAdd, btnCancelAdd, btnGuardar, btnEliminar].forEach(b => b && hide(b));

    const mode = getMode(patioId);

    if (mode === 'idle'){
      show(btnEdit);
      show(btnAdd);
      show(btnEliminar);
      inputs.forEach(i => i.disabled = true);
      btnEliminar.disabled = false;
    }
    if (mode === 'edit'){
      show(btnCancelEdit);
      show(btnGuardar);
      inputs.forEach(i => { i.dataset.original = i.value; i.disabled = false; });
      setBloquesDeleteMode(patioId, false);
    }
    if (mode === 'add'){
      show(btnAdd);
      show(btnCancelAdd);
      show(btnGuardar);
      inputs.forEach(i => i.disabled = false);
      setBloquesDeleteMode(patioId, false);
    }
  }

  function setBloquesDeleteMode(patioId, on){
    bloquesDeleteMode[patioId] = on;
    const cont = document.getElementById(`bloques-container-${patioId}`);
    if (!cont) return;

    cont.classList.toggle('delete-mode-hint', on);
    cont.querySelectorAll('.checkbox-bloque').forEach(cb=>{
      cb.style.display = on ? 'none' : '';
      cb.checked = false;
    });

    const contBtns = document.getElementById(`bloques-buttons-${patioId}`);
    const btnEdit  = contBtns.querySelector(`.editar-bloque[data-patio-id="${patioId}"]`);
    const btnAdd   = contBtns.querySelector(`.agregar-bloque:not(.btn-cancel)[data-patio-id="${patioId}"]`);
    const btnDel   = document.getElementById(`eliminar-bloque-${patioId}`);
    const btnGuardar = document.getElementById(`guardar-bloques-${patioId}`);

    if (btnDel){
      btnDel.disabled = false;
      enable(btnDel);
      btnDel.textContent = on ? 'Cancelar seleccion' : 'Eliminar Bloque';
    }

    if (on){
      hide(btnEdit);
      hide(btnAdd);
      show(btnGuardar);
    } else {
      cont.querySelectorAll('.to-delete').forEach(r=>r.classList.remove('to-delete'));
      if (getMode(patioId) === 'idle'){
        show(btnEdit);
        show(btnAdd);
        show(btnDel);
        hide(btnGuardar);
      }
    }
  }

  document.querySelectorAll('.editar-bloque').forEach(btn => {
    btn.addEventListener('click', function () {
      const patioId = this.dataset.patioId;
      setMode(patioId, 'edit');
    });
  });

  document.querySelectorAll('[id^="cancelar-editar-bloque-"]').forEach(btn => {
    btn.addEventListener('click', function () {
      const patioId = this.dataset.patioId || this.id.split('-').pop();
      const inputs = document.querySelectorAll(`#bloques-container-${patioId} input[type="number"]`);
      inputs.forEach(i => {
        if ('original' in i.dataset){ i.value = i.dataset.original; delete i.dataset.original; }
        i.disabled = true;
      });
      setMode(patioId, 'idle');
    });
  });

  document.querySelectorAll('.agregar-bloque').forEach(btn => {
    if (btn.classList.contains('btn-cancel')) return;
    btn.addEventListener('click', function () {
      const patioId = this.dataset.patioId;
      if (getMode(patioId) !== 'add') setMode(patioId, 'add');

      const container   = document.getElementById(`bloques-container-${patioId}`);
      const baseNext    = parseInt(container.dataset.nextNumber || '1', 10);
      const temporales  = container.querySelectorAll('[data-temp="bloque"]').length;
      const numero      = baseNext + temporales;

      const div = document.createElement('div');
      div.classList.add('checkbox-container','config-row','is-temp');
      div.dataset.temp = 'bloque';
      div.innerHTML = `
        <label>Nuevo Bloque ${numero}</label>
        <input type="number" name="bloques_nuevos[${patioId}][]" value="25" placeholder="Capacidad maxima" min="0" required>
        <span class="config-filler"></span>
      `;
      insertBeforeButtons(container, div);
      updateBloquesUI(patioId);
    });
  });

  document.querySelectorAll('[id^="cancelar-bloque-"]').forEach(btn => {
    btn.addEventListener('click', function () {
      const patioId   = this.dataset.patioId || this.id.split('-').pop();
      const container = document.getElementById(`bloques-container-${patioId}`);
      const temps = container.querySelectorAll('[data-temp="bloque"]');
      if (temps.length) temps[temps.length-1].remove();
      if (!container.querySelector('[data-temp="bloque"]')) setMode(patioId, 'idle');
      else updateBloquesUI(patioId);
    });
  });

  document.querySelectorAll('[id^="eliminar-bloque-"]').forEach(btn=>{
    btn.disabled = false;
    enable(btn);
    btn.addEventListener('click', (e)=>{
      e.preventDefault();
      const patioId = btn.id.split('-').pop();
      if (getMode(patioId) !== 'idle') setMode(patioId, 'idle');
      const on = !!bloquesDeleteMode[patioId];
      setBloquesDeleteMode(patioId, !on);
    });
  });

  document.querySelectorAll('[id^="bloques-container-"]').forEach(container=>{
    container.addEventListener('click', (ev)=>{
      const patioId = container.id.split('-').pop();
      if (!bloquesDeleteMode[patioId]) return;
      const row = ev.target.closest('.checkbox-container');
      if (!row) return;
      row.classList.toggle('to-delete');
      const btnGuardar = document.getElementById(`guardar-bloques-${patioId}`);
      show(btnGuardar);
    });
  });

  formBloques.addEventListener('submit', ()=>{
    formBloques.querySelectorAll('input[name="bloques_eliminar[]"]').forEach(n=>n.remove());
    document.querySelectorAll('[id^="bloques-container-"]').forEach(cont=>{
      cont.querySelectorAll('.to-delete').forEach(row=>{
        const id = row.dataset.id;
        if (!id) return;
        const hidden = document.createElement('input');
        hidden.type  = 'hidden';
        hidden.name  = 'bloques_eliminar[]';
        hidden.value = id;
        formBloques.appendChild(hidden);
      });
      const patioId = cont.id.split('-').pop();
      setBloquesDeleteMode(patioId, false);
    });
  });

  document.querySelectorAll('[id^="guardar-bloques-"]').forEach(btn => {
    btn.addEventListener('click', () => submitAndKeepScroll(formBloques));
  });

  document.querySelectorAll('[id^="eliminar-bloque-"]').forEach(btn=>{ btn.disabled = false; enable(btn); });
</script>

<script>
  (function(){
    var vp = document.querySelector('meta[name="viewport"]');
    if (!vp) {
      vp = document.createElement('meta');
      vp.name = 'viewport';
      document.head.appendChild(vp);
    }
    var BASE = 'width=device-width, initial-scale=1, viewport-fit=cover';

    function setViewport(content){ vp.setAttribute('content', content); }
    function lockZoom(){ setViewport(BASE + ', maximum-scale=1'); }
    function unlockZoom(){ setViewport(BASE + ', maximum-scale=5'); }

    unlockZoom();

    var focusSelector = 'input, select, textarea';
    document.addEventListener('focusin', function(e){
      if (e.target.matches(focusSelector)) lockZoom();
    }, {passive:true});
    document.addEventListener('focusout', function(e){
      if (e.target.matches(focusSelector)) {
        setTimeout(unlockZoom, 120);
      }
    }, {passive:true});

    window.addEventListener('orientationchange', function(){
      unlockZoom();
      setTimeout(function(){ unlockZoom(); }, 150);
    }, {passive:true});

    function normalizeIfScaled(){
      if (window.visualViewport && Math.abs(window.visualViewport.scale - 1) > 0.01) {
        unlockZoom();
      }
    }
    window.addEventListener('resize', normalizeIfScaled, {passive:true});
  })();
</script>
</body>
</html>
