<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

if ((int)($_SESSION['rol_id'] ?? 0) !== 1) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'Acceso denegado. Solo el Super Admin puede editar sucursales.'];
    header('Location: ../../index.php');
    exit;
}

require '../../config/database.php';

$sucursal_id = (int)($_SESSION['sucursal_activa'] ?? 0);
$username = $_SESSION['username'] ?? 'Usuario';
$sucursalesHeader = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$stmtNomSucursal = $pdo->prepare('SELECT nombre FROM sucursales WHERE id = :id');
$stmtNomSucursal->execute(['id' => $sucursal_id]);
$nombreSucursalActiva = $stmtNomSucursal->fetchColumn() ?: 'Desconocida';

$error_message = '';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM sucursales WHERE id = :id AND estado_id = 1');
$stmt->execute(['id' => $id]);
$sucursal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sucursal) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'La sucursal no existe o esta inactiva.'];
    header('Location: sucursales.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $ubicacion = trim($_POST['ubicacion'] ?? '');

    try {
        $pdo->beginTransaction();

        $stmtCheck = $pdo->prepare('SELECT id FROM sucursales WHERE nombre = :nombre AND id != :id');
        $stmtCheck->execute(['nombre' => $nombre, 'id' => $id]);
        if ($stmtCheck->fetchColumn()) {
            throw new Exception('Este nombre ya existe en el historial del sistema. Por favor, elige otro.');
        }

        $stmtUpdate = $pdo->prepare('UPDATE sucursales SET nombre = :nombre, ubicacion = :ubicacion WHERE id = :id');
        $stmtUpdate->execute([
            'nombre' => $nombre,
            'ubicacion' => $ubicacion,
            'id' => $id,
        ]);

        $pdo->commit();
        $_SESSION['toast'] = ['type' => 'success', 'text' => 'Sucursal actualizada correctamente.'];
        header('Location: sucursales.php');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

$menuItems = [
    ['label' => 'Contenedores', 'href' => '../../bloques/bloques.php', 'icon' => '../../imagenes/bloques.png', 'active' => false],
    ['label' => 'Seguimiento PYP', 'href' => '../../bloques/contenedor.php', 'icon' => '../../imagenes/cont.png', 'active' => false],
    ['label' => 'Traslados', 'href' => '../../traslados/traslados.php', 'icon' => '../../imagenes/traslados.png', 'active' => false],
    ['label' => 'Despachos', 'href' => '../../despachos/despachos.php', 'icon' => '../../imagenes/egresos.png', 'active' => false],
    ['label' => 'Reportes', 'href' => '../../reportes/reportes.php', 'icon' => '../../imagenes/reportes.png', 'active' => false],
    ['label' => 'Parametros', 'href' => '../parametros.php', 'icon' => '../../imagenes/parametros.png', 'active' => true],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Editar Sucursal - Proportsa</title>
    <link rel="stylesheet" href="../../styles/index.css">
    <link rel="stylesheet" href="../../styles/parametros/sucursales/editar_sucursal.css">
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
                    <span class="user-avatar sidebar-mobile-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 12a4.25 4.25 0 1 0-4.25-4.25A4.25 4.25 0 0 0 12 12Zm0 2c-4.21 0-7.5 2.19-7.5 5a.75.75 0 0 0 .75.75h13.5A.75.75 0 0 0 19.5 19c0-2.81-3.29-5-7.5-5Z" fill="currentColor"/></svg></span>
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
                    <?php foreach ($sucursalesHeader as $suc): ?>
                        <button type="button" class="sidebar-mobile-sucursal-option <?php echo ($sucursal_id == $suc['id']) ? 'active' : ''; ?>" data-value="<?php echo (int)$suc['id']; ?>" role="option" aria-selected="<?php echo ($sucursal_id == $suc['id']) ? 'true' : 'false'; ?>">
                            <?php echo htmlspecialchars($suc['nombre']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </form>
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
                <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Mostrar u ocultar menu"><span></span><span></span><span></span></button>
            </div>
            <div class="topbar-right">
                <div class="topbar-meta">
                    <form action="../../cambiar_sucursal.php" method="POST" class="topbar-sucursal-form">
                        <span class="topbar-sucursal-label">Sucursal</span>
                        <div class="topbar-sucursal-dropdown" id="sucursalDropdown">
                            <input type="hidden" name="sucursal_id" id="sucursal_id" value="<?php echo (int)$sucursal_id; ?>">
                            <button type="button" class="topbar-sucursal-trigger" id="sucursalTrigger" aria-haspopup="listbox" aria-expanded="false">
                                <span class="topbar-sucursal-value" id="sucursalValue"><?php echo htmlspecialchars($nombreSucursalActiva); ?></span>
                                <span class="topbar-sucursal-caret" aria-hidden="true">&#9662;</span>
                            </button>
                            <div class="topbar-sucursal-menu" id="sucursalMenu" role="listbox">
                                <?php foreach ($sucursalesHeader as $suc): ?>
                                    <button type="button" class="topbar-sucursal-option <?php echo ($sucursal_id == $suc['id']) ? 'active' : ''; ?>" data-value="<?php echo (int)$suc['id']; ?>" role="option" aria-selected="<?php echo ($sucursal_id == $suc['id']) ? 'true' : 'false'; ?>">
                                        <?php echo htmlspecialchars($suc['nombre']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </form>
                </div>
                <button class="theme-toggle" id="themeToggle" type="button" aria-label="Cambiar tema"><span class="theme-icon theme-sun"></span><span class="theme-icon theme-moon"></span></button>
                <div class="user-menu" id="userMenu">
                    <button class="user-menu-trigger" id="userMenuTrigger" type="button" aria-haspopup="true" aria-expanded="false">
                        <span class="user-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 12a4.25 4.25 0 1 0-4.25-4.25A4.25 4.25 0 0 0 12 12Zm0 2c-4.21 0-7.5 2.19-7.5 5a.75.75 0 0 0 .75.75h13.5A.75.75 0 0 0 19.5 19c0-2.81-3.29-5-7.5-5Z" fill="currentColor"/></svg></span>
                        <span class="user-menu-name"><?php echo htmlspecialchars($username); ?></span>
                        <span class="user-menu-caret" aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <div class="user-menu-header">
                            <span class="user-avatar user-avatar-large" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 12a4.25 4.25 0 1 0-4.25-4.25A4.25 4.25 0 0 0 12 12Zm0 2c-4.21 0-7.5 2.19-7.5 5a.75.75 0 0 0 .75.75h13.5A.75.75 0 0 0 19.5 19c0-2.81-3.29-5-7.5-5Z" fill="currentColor"/></svg></span>
                            <div class="user-menu-copy"><strong><?php echo htmlspecialchars($username); ?></strong><small>Sesion activa</small></div>
                        </div>
                        <a href="../../logout.php" class="user-menu-item user-menu-logout">Cerrar sesion</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="dashboard-main sucursal-form-main">
            <section class="view-header">
                <div class="view-header-copy">
                    <h1>Editar Sucursal</h1>
                    <p>Actualiza el nombre y la ubicacion de la sede seleccionada sin afectar su historial operativo.</p>
                </div>
            </section>

            <section class="panel-card sucursal-form-panel">
                <?php if ($error_message): ?>
                    <div class="form-alert form-alert-error"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <form id="sucursalForm" action="editar_sucursal.php?id=<?php echo $id; ?>" method="post" class="sucursal-form">
                    <div class="form-row">
                        <label for="nombre">Nombre de la sucursal</label>
                        <div class="field-stack">
                            <input type="text" id="nombre" name="nombre" required autocomplete="off" value="<?php echo htmlspecialchars($_POST['nombre'] ?? $sucursal['nombre']); ?>">
                            <span class="field-message field-error" id="nombre-error">Este nombre ya esta registrado.</span>
                            <span class="field-message field-valid" id="nombre-valid">Nombre disponible.</span>
                        </div>
                    </div>

                    <div class="form-row">
                        <label for="ubicacion">Ubicacion / direccion</label>
                        <textarea id="ubicacion" name="ubicacion" required rows="4"><?php echo htmlspecialchars($_POST['ubicacion'] ?? $sucursal['ubicacion']); ?></textarea>
                    </div>

                    <div class="form-actions">
                        <a href="sucursales.php" class="toolbar-button toolbar-button-secondary">
                            <span>Volver a sucursales</span>
                        </a>
                        <button type="submit" class="toolbar-button toolbar-button-primary">
                            <img src="../../imagenes/Save.png" alt="" aria-hidden="true">
                            <span>Guardar cambios</span>
                        </button>
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
const mobileQuery = window.matchMedia('(max-width: 960px)');

function openSidebar() { document.body.classList.add('sidebar-open'); document.body.classList.remove('sidebar-collapsed'); }
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
            document.body.classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
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

const nombreOriginal = "<?php echo addslashes($sucursal['nombre']); ?>";
const nombreInput = document.getElementById('nombre');
const errorSpan = document.getElementById('nombre-error');
const validSpan = document.getElementById('nombre-valid');

nombreInput.addEventListener('input', function () {
    const nombre = this.value.trim();
    this.classList.remove('error', 'valid');
    errorSpan.classList.remove('show');
    validSpan.classList.remove('show', 'fade-out');
    if (!nombre || nombre === nombreOriginal) return;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'verificar_sucursal.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        if (xhr.status === 200) {
            if (xhr.responseText === 'exists') {
                nombreInput.classList.add('error');
                errorSpan.classList.add('show');
            } else if (xhr.responseText === 'available') {
                nombreInput.classList.add('valid');
                validSpan.classList.add('show');
                setTimeout(() => validSpan.classList.add('fade-out'), 1800);
                setTimeout(() => validSpan.classList.remove('show', 'fade-out'), 2800);
            }
        }
    };
    xhr.send('nombre=' + encodeURIComponent(nombre) + '&id=<?php echo $id; ?>');
});
</script>
</body>
</html>
