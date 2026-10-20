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

if ($rol_id === 1) {
    $stmtSuc = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursales = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
}

$stmtNomSucursal = $pdo->prepare('SELECT nombre FROM sucursales WHERE id = :id');
$stmtNomSucursal->execute(['id' => $sucursal_id]);
$nombreSucursalActiva = $stmtNomSucursal->fetchColumn() ?: 'Desconocida';

if (!isset($_GET['nombre']) || trim((string)$_GET['nombre']) === '') {
    $_SESSION['toast_message'] = 'No se especifico un contenedor.';
    $_SESSION['toast_type'] = 'error';
    header('Location: bloques.php');
    exit;
}

$nombreContenedor = trim((string)$_GET['nombre']);

$stmt = $pdo->prepare('
    SELECT
        c.*,
        t.nombre AS tipo_unidad_nombre,
        m.nombre AS modelo_nombre,
        b.id AS bloque_id_actual,
        b.numero AS bloque_numero,
        p.id AS patio_id_actual,
        p.nombre AS patio_nombre,
        eu.nombre AS estatus_unidad,
        eb.nombre AS estatus_box,
        ei.nombre AS estatus_ipsa
    FROM contenedores c
    LEFT JOIN tipos_unidad t ON c.tipo_unidad_id = t.id
    LEFT JOIN modelos m ON c.modelo_id = m.id
    LEFT JOIN bloques b ON c.bloque_id = b.id
    LEFT JOIN patios p ON b.patio_id = p.id
    LEFT JOIN estatus_contenedores eu ON c.estatus_unidad_id = eu.id
    LEFT JOIN estatus_contenedores eb ON c.estatus_box_id = eb.id
    LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id
    WHERE c.nombre = :nombre
      AND p.sucursal_id = :sucursal
      AND c.estado_id = 1
    LIMIT 1
');
$stmt->execute([
    'nombre' => $nombreContenedor,
    'sucursal' => $sucursal_id
]);
$contenedor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contenedor) {
    $_SESSION['toast_message'] = 'Contenedor no encontrado o no pertenece a esta sucursal.';
    $_SESSION['toast_type'] = 'error';
    header('Location: bloques.php');
    exit;
}

$refPatio = isset($_GET['ref_patio']) ? (int)$_GET['ref_patio'] : 0;
$refBloque = isset($_GET['ref_bloque']) ? (int)$_GET['ref_bloque'] : 0;

$backLink = 'bloques.php';
if ($refPatio > 0 && $refBloque > 0) {
    $backLink = 'bloques.php?patio_id=' . $refPatio . '&bloque_id=' . $refBloque;
}

$modLink = 'modificar_contenedor.php?nombre=' . urlencode((string)$contenedor['nombre']);
if ($refPatio > 0 && $refBloque > 0) {
    $modLink .= '&ref_patio=' . $refPatio . '&ref_bloque=' . $refBloque;
}

$ubicacion = trim(sprintf(
    '%s, Bloque %s, Posicion %s',
    (string)($contenedor['patio_nombre'] ?? 'Sin patio'),
    (string)($contenedor['bloque_numero'] ?? '-'),
    (string)($contenedor['posicion'] ?? '-')
));

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

$infoFields = [
    ['label' => 'Codigo del contenedor', 'value' => $contenedor['nombre'] ?? '', 'class' => 'field-half'],
    ['label' => 'Tipo de unidad', 'value' => $contenedor['tipo_unidad_nombre'] ?? '', 'class' => 'field-half'],
    ['label' => 'Modelo', 'value' => $contenedor['modelo_nombre'] ?? '', 'class' => 'field-half'],
    ['label' => 'Estado de la unidad', 'value' => $contenedor['estatus_unidad'] ?? '', 'class' => 'field-half'],
    ['label' => 'Status box', 'value' => $contenedor['estatus_box'] ?? '', 'class' => 'field-third'],
    ['label' => 'Estatus IPSA', 'value' => $contenedor['estatus_ipsa'] ?? 'Sin definir', 'class' => 'field-third'],
    ['label' => 'Ubicacion', 'value' => $ubicacion, 'class' => 'field-third'],
    ['label' => 'Observaciones unidad', 'value' => $contenedor['observaciones_unidad'] ?? 'Sin observaciones registradas.', 'class' => 'field-textarea field-wide'],
    ['label' => 'Observaciones box', 'value' => $contenedor['observaciones_box'] ?? 'Sin observaciones registradas.', 'class' => 'field-textarea field-wide'],
];
?>
<!DOCTYPE html>   
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Informacion del Contenedor - Proportsa</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/parametros/bloques/informacion_contenedor.css">
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

        <main class="dashboard-main info-main">
            <section class="panel-card info-module">
                <div class="info-head">
                    <div class="view-header-copy">
                        <h1>Informacion del Contenedor</h1>
                        <p>Consulta el detalle operativo del contenedor seleccionado dentro del bloque activo.</p>
                    </div>
                    <div class="info-actions">
                        <a href="<?php echo htmlspecialchars($modLink); ?>" class="toolbar-button toolbar-button-primary">
                            <img src="../imagenes/modificar.png" alt="" class="toolbar-icon">
                            <span>Modificar</span>
                        </a>
                        <a href="<?php echo htmlspecialchars($backLink); ?>" class="toolbar-button toolbar-button-secondary">
                            <span>Volver al bloque</span>
                        </a>
                    </div>
                </div>

                <div class="info-grid">
                    <?php foreach ($infoFields as $field): ?>
                        <article class="info-item <?php echo htmlspecialchars($field['class']); ?>">
                            <span class="info-label"><?php echo htmlspecialchars($field['label']); ?></span>
                            <?php if (str_contains($field['class'], 'field-textarea')): ?>
                                <div class="info-value info-value-textarea"><?php echo nl2br(htmlspecialchars((string)$field['value'])); ?></div>
                            <?php else: ?>
                                <div class="info-value"><?php echo htmlspecialchars((string)$field['value']); ?></div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
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

    syncThemeToggle();
});
</script>
</body>
</html>
