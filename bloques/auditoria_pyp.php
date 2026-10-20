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
$usuario_id = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Usuario';
$sucursal_id = (int)$_SESSION['sucursal_activa'];
$sucursales = [];
$nombreSucursalActiva = 'Sin asignar';
$error_message = '';

if (!in_array($rol_id, [4, 5], true)) {
    $_SESSION['toast_message'] = 'No tienes permisos para realizar auditorias.';
    $_SESSION['toast_type'] = 'error';
    header('Location: contenedor.php');
    exit;
}

if ($rol_id === 1) {
    $stmtSuc = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursales = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
}

$stmtNomSucursal = $pdo->prepare('SELECT nombre FROM sucursales WHERE id = :id');
$stmtNomSucursal->execute(['id' => $sucursal_id]);
$nombreSucursalActiva = $stmtNomSucursal->fetchColumn() ?: 'Desconocida';

$ref_patio = isset($_GET['ref_patio']) ? (int)$_GET['ref_patio'] : 0;
$ref_bloque = isset($_GET['ref_bloque']) ? (int)$_GET['ref_bloque'] : 0;

$backLink = 'contenedor.php';
if ($ref_patio > 0 && $ref_bloque > 0) {
    $backLink .= '?patio_id=' . $ref_patio . '&bloque_id=' . $ref_bloque;
}

if (!isset($_GET['nombre']) || trim((string)$_GET['nombre']) === '') {
    header('Location: ' . $backLink);
    exit;
}

$nombreContenedor = trim((string)$_GET['nombre']);

$stmt = $pdo->prepare('
    SELECT
        c.id,
        c.nombre,
        p.nombre AS patio_nombre,
        b.numero AS bloque_numero,
        c.posicion,
        pa.estatus_pyp,
        pa.informacion_adicional,
        pa.fecha_registro
    FROM contenedores c
    JOIN bloques b ON c.bloque_id = b.id
    JOIN patios p ON b.patio_id = p.id
    LEFT JOIN pyp_auditoria pa ON c.id = pa.contenedor_id AND pa.estado_id = 1
    WHERE c.nombre = :nombre
      AND p.sucursal_id = :sucursal
      AND c.estado_id = 1
    LIMIT 1
');
$stmt->execute([
    'nombre' => $nombreContenedor,
    'sucursal' => $sucursal_id
]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data || empty($data['estatus_pyp']) && empty($data['fecha_registro']) && empty($data['informacion_adicional'])) {
    $_SESSION['toast_message'] = 'No se encontro una auditoria PYP activa para este contenedor.';
    $_SESSION['toast_type'] = 'error';
    header('Location: ' . $backLink);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nuevoEstatus = trim((string)($_POST['estatus_pyp'] ?? ''));
    $nuevaInfo = trim((string)($_POST['informacion_adicional'] ?? ''));
    $idContenedor = (int)$data['id'];

    if ($nuevoEstatus === '') {
        $error_message = 'El estatus PYP es obligatorio.';
    } elseif (strlen($nuevoEstatus) > 25) {
        $error_message = 'El estatus no puede superar los 25 caracteres.';
    } elseif ($nuevaInfo === '') {
        $error_message = 'La informacion adicional es obligatoria.';
    } elseif (strlen($nuevaInfo) > 150) {
        $error_message = 'La informacion adicional no puede superar los 150 caracteres.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtUpd = $pdo->prepare('
                UPDATE pyp_auditoria
                SET estatus_pyp = :estatus,
                    informacion_adicional = :info,
                    usuario_id = :uid,
                    fecha_registro = NOW()
                WHERE contenedor_id = :cid
                  AND estado_id = 1
            ');
            $stmtUpd->execute([
                'estatus' => $nuevoEstatus,
                'info' => $nuevaInfo,
                'uid' => $usuario_id,
                'cid' => $idContenedor
            ]);

            if ($data['estatus_pyp'] !== $nuevoEstatus || $data['informacion_adicional'] !== $nuevaInfo) {
                $obsLog = 'Auditoria PYP actualizada. Estatus: ' . $nuevoEstatus . '.';
                $stmtLog = $pdo->prepare('
                    INSERT INTO logs_eventos (contenedor_id, usuario_id, tipo_operacion_id, observaciones, estado_id)
                    VALUES (?, ?, 4, ?, 1)
                ');
                $stmtLog->execute([$idContenedor, $usuario_id, $obsLog]);
            }

            $pdo->commit();

            $_SESSION['toast_message'] = 'Auditoria guardada correctamente.';
            $_SESSION['toast_type'] = 'success';
            header('Location: ' . $backLink);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$ubicacion = trim(sprintf(
    '%s, Bloque %s, Posicion %s',
    (string)($data['patio_nombre'] ?? 'Sin patio'),
    (string)($data['bloque_numero'] ?? '-'),
    (string)($data['posicion'] ?? '-')
));

$menuItems = [];
$menuItems[] = ['label' => 'Seguimiento PYP', 'href' => 'contenedor.php', 'icon' => '../imagenes/cont.png', 'active' => true];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Auditoria PYP - Proportsa</title>
    <link rel="stylesheet" href="../styles/index.css">
    <link rel="stylesheet" href="../styles/parametros/bloques/modificar_contenedor.css">
    <link rel="stylesheet" href="../styles/parametros/bloques/auditoria_pyp.css">
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

            <div class="sidebar-mobile-sucursal-static">
                <small>Sucursal</small>
                <strong><?php echo htmlspecialchars($nombreSucursalActiva); ?></strong>
            </div>

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
                    <div class="topbar-sucursal-pill">
                        <span class="pill-label">Sucursal</span>
                        <strong><?php echo htmlspecialchars($nombreSucursalActiva); ?></strong>
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
                        <div class="user-menu-dropdown">
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
            </div>
        </header>

        <main class="dashboard-main modify-main audit-main">
            <section class="panel-card modify-module audit-module">
                <div class="modify-head">
                    <div class="view-header-copy">
                        <h1>Auditoria PYP</h1>
                        <p>Actualiza el estado y el detalle de la revision del contenedor asignado.</p>
                    </div>
                    <div class="modify-actions">
                        <a href="<?php echo htmlspecialchars($backLink); ?>" class="toolbar-button toolbar-button-secondary">
                            <img src="../imagenes/regresar.png" alt="" class="toolbar-icon">
                            <span>Cancelar</span>
                        </a>
                        <button type="submit" form="auditForm" class="toolbar-button toolbar-button-primary">
                            <img src="../imagenes/Save.png" alt="" class="toolbar-icon">
                            <span>Guardar cambios</span>
                        </button>
                    </div>
                </div>

                <?php if ($error_message !== ''): ?>
                    <div class="audit-alert audit-alert-error">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <form action="" method="post" id="auditForm" class="modify-form">
                    <div class="modify-grid">
                        <article class="modify-item field-half">
                            <span class="modify-label">Contenedor</span>
                            <div class="modify-value"><?php echo htmlspecialchars((string)$data['nombre']); ?></div>
                        </article>

                        <article class="modify-item field-half">
                            <span class="modify-label">Ubicacion</span>
                            <div class="modify-value"><?php echo htmlspecialchars($ubicacion); ?></div>
                        </article>

                        <article class="modify-item field-half">
                            <label for="estatus_pyp" class="modify-label">Estatus PYP</label>
                            <div class="modify-control-wrap">
                                <input
                                    type="text"
                                    id="estatus_pyp"
                                    name="estatus_pyp"
                                    class="modify-control"
                                    maxlength="25"
                                    required
                                    autocomplete="off"
                                    placeholder="Ej: En revision, aprobado..."
                                    value="<?php echo htmlspecialchars((string)($data['estatus_pyp'] ?? '')); ?>"
                                >
                                <span class="char-count" id="contador-estatus">0 / 25</span>
                            </div>
                        </article>

                        <article class="modify-item field-half">
                            <span class="modify-label">Ultima actualizacion</span>
                            <div class="modify-value"><?php echo htmlspecialchars((string)($data['fecha_registro'] ?? 'Sin registro')); ?></div>
                        </article>

                        <article class="modify-item field-textarea field-wide">
                            <label for="informacion_adicional" class="modify-label">Informacion adicional</label>
                            <div class="modify-control-wrap">
                                <textarea
                                    id="informacion_adicional"
                                    name="informacion_adicional"
                                    class="modify-control modify-textarea"
                                    rows="5"
                                    maxlength="150"
                                    required
                                    placeholder="Detalles obligatorios de la auditoria..."
                                ><?php echo htmlspecialchars((string)($data['informacion_adicional'] ?? '')); ?></textarea>
                                <span class="char-count" id="contador-info">0 / 150</span>
                            </div>
                        </article>
                    </div>
                </form>
            </section>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('sidebarToggle');
    const sidebarClose = document.getElementById('sidebarClose');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const themeToggle = document.getElementById('themeToggle');
    const sidebarThemeToggle = document.getElementById('sidebarThemeToggle');
    const userMenu = document.getElementById('userMenu');
    const userMenuTrigger = document.getElementById('userMenuTrigger');
    const sidebarMobileUserMenu = document.getElementById('sidebarMobileUserMenu');
    const sidebarMobileUserTrigger = document.getElementById('sidebarMobileUserTrigger');
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
        const isOpen = userMenu.classList.toggle('open');
        userMenuTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
        if (!userMenu.contains(event.target)) {
            userMenu.classList.remove('open');
            userMenuTrigger.setAttribute('aria-expanded', 'false');
        }
        if (sidebarMobileUserMenu && !sidebarMobileUserMenu.contains(event.target)) {
            sidebarMobileUserMenu.classList.remove('open');
            if (sidebarMobileUserTrigger) {
                sidebarMobileUserTrigger.setAttribute('aria-expanded', 'false');
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            userMenu.classList.remove('open');
            userMenuTrigger.setAttribute('aria-expanded', 'false');
        }
    });

    if (sidebarMobileUserMenu && sidebarMobileUserTrigger) {
        sidebarMobileUserTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            const isOpen = sidebarMobileUserMenu.classList.toggle('open');
            sidebarMobileUserTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    function setupCounter(inputId, counterId, maxLen) {
        const input = document.getElementById(inputId);
        const counter = document.getElementById(counterId);
        if (!input || !counter) {
            return;
        }

        function update() {
            const len = input.value.length;
            counter.textContent = `${len} / ${maxLen}`;
            counter.classList.toggle('text-danger', len >= maxLen);
        }

        input.addEventListener('input', update);
        update();
    }

    setupCounter('estatus_pyp', 'contador-estatus', 25);
    setupCounter('informacion_adicional', 'contador-info', 150);
    syncThemeToggle();
});
</script>
</body>
</html>
