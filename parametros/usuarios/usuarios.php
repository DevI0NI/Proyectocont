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

$itemsPorPagina = 10;
$paginaActual = max(1, (int)($_GET['pagina'] ?? 1));
$inicio = ($paginaActual - 1) * $itemsPorPagina;

$rolUsuarioActual = (int)($_SESSION['rol_id'] ?? 0);
$miId = (int)($_SESSION['user_id'] ?? 0);
$sucursalUsuarioActual = (int)($_SESSION['sucursal_usuario'] ?? 0);
$sucursalId = (int)($_SESSION['sucursal_activa'] ?? $sucursalUsuarioActual);
$username = $_SESSION['username'] ?? 'Usuario';
$rolFiltro = isset($_GET['rol_filtro']) ? (int)($_GET['rol_filtro']) : 0;
$mostrarInactivos = (($_GET['estado'] ?? '') === 'inactivos');
$minRol = ($rolUsuarioActual === 1) ? 2 : 3;

if ($mostrarInactivos && $rolUsuarioActual !== 1) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'Solo el Super Admin puede acceder a usuarios inactivos.'];
    header('Location: usuarios.php');
    exit;
}

$sucursalesHeader = [];
$nombreSucursalActiva = 'Desconocida';

if ($rolUsuarioActual === 1) {
    $stmtSuc = $pdo->query('SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC');
    $sucursalesHeader = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
}

if ($sucursalId > 0) {
    $stmtNomSucursal = $pdo->prepare('SELECT nombre FROM sucursales WHERE id = :id');
    $stmtNomSucursal->execute(['id' => $sucursalId]);
    $nombreSucursalActiva = $stmtNomSucursal->fetchColumn() ?: 'Desconocida';
}

$stmtRoles = $pdo->prepare('SELECT id, nombre FROM roles WHERE id >= :minRol AND estado_id = 1 ORDER BY id ASC');
$stmtRoles->execute(['minRol' => $minRol]);
$rolesDisponibles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

if ($rolUsuarioActual === 1) {
    $stmtSucDisponibles = $pdo->query('SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC');
    $sucursalesDisponibles = $stmtSucDisponibles->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmtSucDisponibles = $pdo->prepare('SELECT id, nombre FROM sucursales WHERE id = :id AND estado_id = 1');
    $stmtSucDisponibles->execute(['id' => $sucursalUsuarioActual]);
    $sucursalesDisponibles = $stmtSucDisponibles->fetchAll(PDO::FETCH_ASSOC);
}

$modalMode = 'create';
$modalOpen = false;
$formError = '';
$formInfo = '';
$formData = [
    'id' => 0,
    'username' => '',
    'email' => '',
    'password' => '',
    'rol_id' => '',
    'sucursal_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $modalMode = $action === 'update_user' ? 'edit' : ($action === 'reactivate_user' ? 'reactivate' : 'create');
    $modalOpen = true;

    $formData['id'] = (int)($_POST['id'] ?? 0);
    $formData['username'] = trim($_POST['username'] ?? '');
    $formData['email'] = trim($_POST['email'] ?? '');
    $formData['password'] = (string)($_POST['password'] ?? '');
    $formData['rol_id'] = (string)($_POST['rol_id'] ?? '');
    $formData['sucursal_id'] = (string)($_POST['sucursal_id'] ?? '');

    try {
        if (!preg_match('/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+$/u', $formData['username'])) {
            throw new Exception('El nombre de usuario solo debe contener letras.');
        }

        if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('El formato del correo electronico es incorrecto.');
        }

        $rolObjetivo = (int)$formData['rol_id'];
        $sucursalObjetivo = (int)$formData['sucursal_id'];

        if ($rolObjetivo <= 0 || $sucursalObjetivo <= 0) {
            throw new Exception('Selecciona un rol y una sucursal validos.');
        }

        if ($rolUsuarioActual !== 1) {
            if ($rolObjetivo < $minRol) {
                throw new Exception('No tienes permisos para asignar este rol.');
            }
            if ($sucursalObjetivo !== $sucursalUsuarioActual) {
                throw new Exception('No puedes asignar usuarios a otra sucursal.');
            }
        }

        $stmtValidRol = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE id = :id AND estado_id = 1 AND id >= :minRol');
        $stmtValidRol->execute([
            'id' => $rolObjetivo,
            'minRol' => $minRol,
        ]);
        if (!(int)$stmtValidRol->fetchColumn()) {
            throw new Exception('El rol seleccionado no esta disponible para tu perfil.');
        }

        $stmtValidSucursal = $pdo->prepare('SELECT COUNT(*) FROM sucursales WHERE id = :id AND estado_id = 1');
        $stmtValidSucursal->execute(['id' => $sucursalObjetivo]);
        if (!(int)$stmtValidSucursal->fetchColumn()) {
            throw new Exception('La sucursal seleccionada no esta disponible.');
        }

        if ($action === 'create_user') {
            if (!preg_match('/^[A-Za-z0-9]{6,}$/', $formData['password'])) {
                throw new Exception('La contrasena debe tener al menos 6 caracteres y solo contener letras y numeros.');
            }

            $stmtCheckUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username AND estado_id = 1');
            $stmtCheckUser->execute(['username' => $formData['username']]);
            if ((int)$stmtCheckUser->fetchColumn() > 0) {
                throw new Exception('El nombre de usuario ya esta ocupado.');
            }

            $stmtCheckEmail = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND estado_id = 1');
            $stmtCheckEmail->execute(['email' => $formData['email']]);
            if ((int)$stmtCheckEmail->fetchColumn() > 0) {
                throw new Exception('El correo electronico ya esta registrado.');
            }

            $stmtInactiveUsername = $pdo->prepare('SELECT id, username, email, rol_id, sucursal_id FROM users WHERE username = :username AND estado_id = 2 ORDER BY id DESC LIMIT 1');
            $stmtInactiveUsername->execute(['username' => $formData['username']]);
            $inactiveByUsername = $stmtInactiveUsername->fetch(PDO::FETCH_ASSOC) ?: null;

            $stmtInactiveEmail = $pdo->prepare('SELECT id, username, email, rol_id, sucursal_id FROM users WHERE email = :email AND estado_id = 2 ORDER BY id DESC LIMIT 1');
            $stmtInactiveEmail->execute(['email' => $formData['email']]);
            $inactiveByEmail = $stmtInactiveEmail->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($inactiveByUsername || $inactiveByEmail) {
                if ($rolUsuarioActual !== 1) {
                    throw new Exception('Ya existe un usuario inactivo con estos datos. Solo un Super Admin puede reactivarlo desde usuarios inactivos.');
                }

                if ($inactiveByUsername && $inactiveByEmail && (int)$inactiveByUsername['id'] !== (int)$inactiveByEmail['id']) {
                    throw new Exception('El usuario y el correo pertenecen a cuentas inactivas distintas. Reactivalas desde usuarios inactivos.');
                }

                $reactivateCandidate = $inactiveByUsername ?: $inactiveByEmail;
                $modalMode = 'reactivate';
                $formData['id'] = (int)$reactivateCandidate['id'];
                $formInfo = 'Se encontro un usuario inactivo con estos datos. Revisa rol, sucursal y define una contrasena nueva para reactivarlo.';
                throw new Exception('');
            }

            $stmtIns = $pdo->prepare('
                INSERT INTO users (username, password, email, rol_id, sucursal_id, estado_id)
                VALUES (:username, :password, :email, :rol_id, :sucursal_id, 1)
            ');
            $stmtIns->execute([
                'username' => $formData['username'],
                'password' => password_hash($formData['password'], PASSWORD_DEFAULT),
                'email' => $formData['email'],
                'rol_id' => $rolObjetivo,
                'sucursal_id' => $sucursalObjetivo,
            ]);

            $_SESSION['toast'] = ['type' => 'success', 'text' => 'Usuario creado correctamente.'];
            header('Location: ' . ($mostrarInactivos ? 'usuarios_inactivos.php' : 'usuarios.php') . ($rolFiltro > 0 ? '?rol_filtro=' . $rolFiltro : ''));
            exit;
        }

        if ($action === 'reactivate_user') {
            if ($rolUsuarioActual !== 1) {
                throw new Exception('Solo un Super Admin puede reactivar usuarios.');
            }

            if ($formData['id'] <= 0) {
                throw new Exception('No se encontro el usuario inactivo que intentas reactivar.');
            }

            if (!preg_match('/^[A-Za-z0-9]{6,}$/', $formData['password'])) {
                throw new Exception('La contrasena debe tener al menos 6 caracteres y solo contener letras y numeros.');
            }

            $stmtTarget = $pdo->prepare('SELECT id FROM users WHERE id = :id AND estado_id = 2');
            $stmtTarget->execute(['id' => $formData['id']]);
            if (!$stmtTarget->fetchColumn()) {
                throw new Exception('El usuario inactivo ya no esta disponible para reactivacion.');
            }

            $stmtCheckUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username AND id != :id');
            $stmtCheckUser->execute([
                'username' => $formData['username'],
                'id' => $formData['id'],
            ]);
            if ((int)$stmtCheckUser->fetchColumn() > 0) {
                throw new Exception('El nombre de usuario ya existe en otra cuenta del sistema.');
            }

            $stmtCheckEmail = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND id != :id');
            $stmtCheckEmail->execute([
                'email' => $formData['email'],
                'id' => $formData['id'],
            ]);
            if ((int)$stmtCheckEmail->fetchColumn() > 0) {
                throw new Exception('El correo electronico ya existe en otra cuenta del sistema.');
            }

            $stmtReactivate = $pdo->prepare('
                UPDATE users
                SET username = :username, email = :email, password = :password, rol_id = :rol_id, sucursal_id = :sucursal_id, estado_id = 1
                WHERE id = :id
            ');
            $stmtReactivate->execute([
                'username' => $formData['username'],
                'email' => $formData['email'],
                'password' => password_hash($formData['password'], PASSWORD_DEFAULT),
                'rol_id' => $rolObjetivo,
                'sucursal_id' => $sucursalObjetivo,
                'id' => $formData['id'],
            ]);

            $_SESSION['toast'] = ['type' => 'success', 'text' => 'Usuario reactivado correctamente.'];
            header('Location: ' . ($mostrarInactivos ? 'usuarios_inactivos.php' : 'usuarios.php') . ($rolFiltro > 0 ? '?rol_filtro=' . $rolFiltro : ''));
            exit;
        }

        if ($action === 'update_user') {
            if ($formData['id'] <= 0) {
                throw new Exception('No se encontro el usuario que intentas editar.');
            }

            $stmtTarget = $pdo->prepare('SELECT id, rol_id FROM users WHERE id = :id AND estado_id = 1');
            $stmtTarget->execute(['id' => $formData['id']]);
            $usuarioActual = $stmtTarget->fetch(PDO::FETCH_ASSOC);

            if (!$usuarioActual) {
                throw new Exception('El usuario que intentas editar no existe o esta inactivo.');
            }

            $esMiPerfil = (int)$usuarioActual['id'] === $miId;
            if ($rolUsuarioActual === 2) {
                if ((int)$usuarioActual['rol_id'] === 1) {
                    throw new Exception('No tienes permisos para editar a un Super Admin.');
                }
                if ((int)$usuarioActual['rol_id'] === 2 && !$esMiPerfil) {
                    throw new Exception('No tienes permisos para editar a otros administradores.');
                }
            }

            $stmtCheckUser = $pdo->prepare('SELECT estado_id FROM users WHERE username = :username AND id != :id ORDER BY estado_id ASC, id DESC LIMIT 1');
            $stmtCheckUser->execute([
                'username' => $formData['username'],
                'id' => $formData['id'],
            ]);
            $usernameEstado = $stmtCheckUser->fetchColumn();
            if ($usernameEstado !== false) {
                throw new Exception((int)$usernameEstado === 1 ? 'El nombre de usuario ya esta ocupado.' : 'El nombre de usuario pertenece a una cuenta inactiva. Reactivala o usa otro.');
            }

            $stmtCheckEmail = $pdo->prepare('SELECT estado_id FROM users WHERE email = :email AND id != :id ORDER BY estado_id ASC, id DESC LIMIT 1');
            $stmtCheckEmail->execute([
                'email' => $formData['email'],
                'id' => $formData['id'],
            ]);
            $emailEstado = $stmtCheckEmail->fetchColumn();
            if ($emailEstado !== false) {
                throw new Exception((int)$emailEstado === 1 ? 'El correo electronico ya esta registrado.' : 'El correo pertenece a una cuenta inactiva. Reactivala o usa otro.');
            }

            if ($formData['password'] !== '' && !preg_match('/^[A-Za-z0-9]{6,}$/', $formData['password'])) {
                throw new Exception('La contrasena nueva debe tener al menos 6 caracteres y solo contener letras y numeros.');
            }

            if ($formData['password'] !== '') {
                $stmtUpd = $pdo->prepare('
                    UPDATE users
                    SET username = :username, email = :email, password = :password, rol_id = :rol_id, sucursal_id = :sucursal_id
                    WHERE id = :id
                ');
                $stmtUpd->execute([
                    'username' => $formData['username'],
                    'email' => $formData['email'],
                    'password' => password_hash($formData['password'], PASSWORD_DEFAULT),
                    'rol_id' => $rolObjetivo,
                    'sucursal_id' => $sucursalObjetivo,
                    'id' => $formData['id'],
                ]);
            } else {
                $stmtUpd = $pdo->prepare('
                    UPDATE users
                    SET username = :username, email = :email, rol_id = :rol_id, sucursal_id = :sucursal_id
                    WHERE id = :id
                ');
                $stmtUpd->execute([
                    'username' => $formData['username'],
                    'email' => $formData['email'],
                    'rol_id' => $rolObjetivo,
                    'sucursal_id' => $sucursalObjetivo,
                    'id' => $formData['id'],
                ]);
            }

            if ($esMiPerfil) {
                $_SESSION['username'] = $formData['username'];
                $_SESSION['sucursal_usuario'] = $sucursalObjetivo;
            }

            $_SESSION['toast'] = ['type' => 'success', 'text' => 'Usuario actualizado correctamente.'];
            header('Location: ' . ($mostrarInactivos ? 'usuarios_inactivos.php' : 'usuarios.php') . ($rolFiltro > 0 ? '?rol_filtro=' . $rolFiltro : ''));
            exit;
        }
    } catch (Exception $e) {
        $formError = $e->getMessage();
    }
}

$whereClause = 'WHERE u.estado_id = ' . ($mostrarInactivos ? '2' : '1');
$params = [];

if ($rolUsuarioActual === 2) {
    $whereClause .= ' AND u.sucursal_id = :mi_sucursal AND u.rol_id > 2';
    $params['mi_sucursal'] = $sucursalUsuarioActual;
}

if ($rolFiltro > 0) {
    $whereClause .= ' AND u.rol_id = :rol_filtro';
    $params['rol_filtro'] = $rolFiltro;
}

$sqlTotal = "SELECT COUNT(*) FROM users u $whereClause";
$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute($params);
$totalItems = (int)$stmtTotal->fetchColumn();
$totalPaginas = max(1, (int)ceil($totalItems / $itemsPorPagina));

$sql = "
    SELECT u.id, u.username, u.email, u.rol_id, u.sucursal_id, r.nombre AS rol, s.nombre AS sucursal
    FROM users u
    JOIN roles r ON u.rol_id = r.id
    LEFT JOIN sucursales s ON u.sucursal_id = s.id
    $whereClause
    ORDER BY u.username ASC
    LIMIT :inicio, :items
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
}
$stmt->bindValue(':inicio', $inicio, PDO::PARAM_INT);
$stmt->bindValue(':items', $itemsPorPagina, PDO::PARAM_INT);
$stmt->execute();
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sqlJS = "
    SELECT u.id, u.username, u.email, u.rol_id, u.sucursal_id, r.nombre AS rol, s.nombre AS sucursal
    FROM users u
    JOIN roles r ON u.rol_id = r.id
    LEFT JOIN sucursales s ON u.sucursal_id = s.id
    $whereClause
    ORDER BY u.username ASC
";
$stmtJS = $pdo->prepare($sqlJS);
$stmtJS->execute($params);
$todosUsuariosJS = $stmtJS->fetchAll(PDO::FETCH_ASSOC);

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

function roleBadgeClass(string $rol): string
{
    $rol = strtolower($rol);
    if (str_contains($rol, 'super')) {
        return 'rol-super';
    }
    if (str_contains($rol, 'admin')) {
        return 'rol-admin';
    }
    if (str_contains($rol, 'mec')) {
        return 'rol-mech';
    }
    if (str_contains($rol, 'extern')) {
        return 'rol-ext';
    }
    return 'rol-user';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Usuarios - Proportsa</title>
    <link rel="stylesheet" href="../../styles/index.css">
    <link rel="stylesheet" href="../../styles/parametros/bloques/bloques.css">
    <link rel="stylesheet" href="../../styles/parametros/usuarios.css">
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

            <?php if ($rolUsuarioActual === 1): ?>
                <form action="../../cambiar_sucursal.php" method="POST" class="sidebar-mobile-sucursal-form">
                    <input type="hidden" name="sucursal_id" id="sidebar_mobile_sucursal_id" value="<?php echo (int)$sucursalId; ?>">
                    <button type="button" class="sidebar-mobile-sucursal-trigger" id="sidebarMobileSucursalTrigger" aria-haspopup="listbox" aria-expanded="false">
                        <span class="sidebar-mobile-sucursal-copy">
                            <small>Sucursal</small>
                            <strong id="sidebarMobileSucursalValue"><?php echo htmlspecialchars($nombreSucursalActiva); ?></strong>
                        </span>
                        <span class="sidebar-mobile-sucursal-caret" aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="sidebar-mobile-sucursal-menu" id="sidebarMobileSucursalMenu" role="listbox">
                        <?php foreach ($sucursalesHeader as $suc): ?>
                            <button type="button" class="sidebar-mobile-sucursal-option <?php echo ($sucursalId == $suc['id']) ? 'active' : ''; ?>" data-value="<?php echo (int)$suc['id']; ?>" role="option" aria-selected="<?php echo ($sucursalId == $suc['id']) ? 'true' : 'false'; ?>">
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
                    <?php if ($rolUsuarioActual === 1): ?>
                        <form action="../../cambiar_sucursal.php" method="POST" class="topbar-sucursal-form">
                            <span class="topbar-sucursal-label">Sucursal</span>
                            <div class="topbar-sucursal-dropdown" id="sucursalDropdown">
                                <input type="hidden" name="sucursal_id" id="sucursal_id" value="<?php echo (int)$sucursalId; ?>">
                                <button type="button" class="topbar-sucursal-trigger" id="sucursalTrigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="topbar-sucursal-value" id="sucursalValue"><?php echo htmlspecialchars($nombreSucursalActiva); ?></span>
                                    <span class="topbar-sucursal-caret" aria-hidden="true">&#9662;</span>
                                </button>
                                <div class="topbar-sucursal-menu" id="sucursalMenu" role="listbox">
                                    <?php foreach ($sucursalesHeader as $suc): ?>
                                        <button type="button" class="topbar-sucursal-option <?php echo ($sucursalId == $suc['id']) ? 'active' : ''; ?>" data-value="<?php echo (int)$suc['id']; ?>" role="option" aria-selected="<?php echo ($sucursalId == $suc['id']) ? 'true' : 'false'; ?>">
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

        <main class="dashboard-main bloques-main usuarios-main">
            <section class="panel-card bloques-module usuarios-module">
                <div class="module-head">
                    <div class="view-header-copy">
                        <h1><?php echo $mostrarInactivos ? 'Usuarios inactivos' : 'Usuarios del sistema'; ?></h1>
                        <p><?php echo $mostrarInactivos ? 'Consulta cuentas desactivadas y reactivalas con control de acceso.' : 'Gestiona accesos, perfiles y alcance operativo del personal administrativo y de patio.'; ?></p>
                    </div>
                </div>

                <div class="module-toolbar">
                    <div class="bloques-toolbar usuarios-toolbar">
                        <div class="usuarios-filters">
                            <a href="<?php echo $mostrarInactivos ? 'usuarios_inactivos.php' : 'usuarios.php'; ?>" class="filter-pill <?php echo ($rolFiltro === 0) ? 'active' : ''; ?>">Todos</a>
                            <?php if ($rolUsuarioActual === 1): ?>
                                <a href="?<?php echo $mostrarInactivos ? 'estado=inactivos&' : ''; ?>rol_filtro=2" class="filter-pill <?php echo ($rolFiltro === 2) ? 'active' : ''; ?>">Admins</a>
                            <?php endif; ?>
                            <a href="?<?php echo $mostrarInactivos ? 'estado=inactivos&' : ''; ?>rol_filtro=3" class="filter-pill <?php echo ($rolFiltro === 3) ? 'active' : ''; ?>">Usuarios</a>
                            <a href="?<?php echo $mostrarInactivos ? 'estado=inactivos&' : ''; ?>rol_filtro=4" class="filter-pill <?php echo ($rolFiltro === 4) ? 'active' : ''; ?>">Mecanicos</a>
                            <a href="?<?php echo $mostrarInactivos ? 'estado=inactivos&' : ''; ?>rol_filtro=5" class="filter-pill <?php echo ($rolFiltro === 5) ? 'active' : ''; ?>">Externos</a>
                        </div>

                        <div class="usuarios-toolbar-actions">
                            <div class="toolbar-search usuarios-search">
                                <input type="text" id="searchInput" placeholder="Buscar usuario..." autocomplete="off">
                            </div>
                            <?php if ($rolUsuarioActual === 1): ?>
                                <a href="<?php echo $mostrarInactivos ? 'usuarios.php' : 'usuarios_inactivos.php'; ?>" class="toolbar-button toolbar-button-secondary toolbar-link-button">
                                    <span><?php echo $mostrarInactivos ? 'Activos' : 'Inactivos'; ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if (!$mostrarInactivos): ?>
                                <button type="button" class="toolbar-button toolbar-button-primary" id="openCreateModal">
                                    <img src="../../imagenes/nuevo.png" alt="" aria-hidden="true" class="toolbar-icon">
                                    <span>Nuevo</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="bloques-table-panel usuarios-table-panel">
                    <div class="table-wrap bloques-table-wrap">
                        <table class="bloques-table usuarios-table data-table">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Email</th>
                                    <th>Rol</th>
                                    <th>Sucursal</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaUsuarios">
                                <?php if ($usuarios): ?>
                                    <?php foreach ($usuarios as $usr): ?>
                                        <tr>
                                            <td data-label="Usuario"><div class="table-primary"><?php echo htmlspecialchars($usr['username']); ?></div></td>
                                            <td data-label="Email"><span class="table-muted"><?php echo htmlspecialchars($usr['email']); ?></span></td>
                                            <td data-label="Rol">
                                                <span class="rol-badge <?php echo roleBadgeClass($usr['rol']); ?>">
                                                    <?php echo htmlspecialchars($usr['rol']); ?>
                                                </span>
                                            </td>
                                            <td data-label="Sucursal"><span class="table-muted"><?php echo htmlspecialchars($usr['sucursal'] ?? 'Global / Todas'); ?></span></td>
                                            <td data-label="Acciones">
                                                <div class="table-actions">
                                                    <?php if ($mostrarInactivos): ?>
                                                        <button
                                                            type="button"
                                                            class="action-pill action-pill-restore open-reactivate-modal"
                                                            data-id="<?php echo (int)$usr['id']; ?>"
                                                            data-username="<?php echo htmlspecialchars($usr['username'], ENT_QUOTES); ?>"
                                                            data-email="<?php echo htmlspecialchars($usr['email'], ENT_QUOTES); ?>"
                                                            data-rol-id="<?php echo (int)$usr['rol_id']; ?>"
                                                            data-sucursal-id="<?php echo (int)$usr['sucursal_id']; ?>"
                                                        >Reactivar</button>
                                                    <?php else: ?>
                                                        <button
                                                            type="button"
                                                            class="action-pill action-pill-edit open-edit-modal"
                                                            data-id="<?php echo (int)$usr['id']; ?>"
                                                            data-username="<?php echo htmlspecialchars($usr['username'], ENT_QUOTES); ?>"
                                                            data-email="<?php echo htmlspecialchars($usr['email'], ENT_QUOTES); ?>"
                                                            data-rol-id="<?php echo (int)$usr['rol_id']; ?>"
                                                            data-sucursal-id="<?php echo (int)$usr['sucursal_id']; ?>"
                                                        >Editar</button>
                                                        <button
                                                            type="button"
                                                            class="action-pill action-pill-delete open-delete-modal"
                                                            data-href="eliminar_usuario.php?id=<?php echo (int)$usr['id']; ?>"
                                                            data-username="<?php echo htmlspecialchars($usr['username'], ENT_QUOTES); ?>"
                                                        >Eliminar</button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5">
                                            <div class="empty-state table-empty">
                                                <h3><?php echo $mostrarInactivos ? 'Sin usuarios inactivos' : 'Sin usuarios registrados'; ?></h3>
                                                <p><?php echo $mostrarInactivos ? 'Cuando desactives usuarios, apareceran aqui para poder reactivarlos.' : 'Crea un usuario nuevo para empezar a gestionar accesos dentro del sistema.'; ?></p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPaginas > 1): ?>
                        <div class="usuarios-footer">
                            <div class="pagination">
                                <?php
                                $urlBase = '?';
                                if ($rolFiltro > 0) {
                                    $urlBase .= 'rol_filtro=' . $rolFiltro . '&';
                                }
                                ?>
                                <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                    <a href="<?php echo $urlBase; ?>pagina=<?php echo $i; ?>" class="pagination-button <?php echo ($i === $paginaActual) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<div class="modal-backdrop <?php echo $modalOpen ? 'show' : ''; ?>" id="usuarioModalBackdrop"></div>
<div class="modal-shell <?php echo $modalOpen ? 'show' : ''; ?>" id="usuarioModal" role="dialog" aria-modal="true" aria-labelledby="usuarioModalTitle">
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-head-copy">
                <h2 id="usuarioModalTitle"><?php echo $modalMode === 'edit' ? 'Editar usuario' : ($modalMode === 'reactivate' ? 'Reactivar usuario' : 'Nuevo usuario'); ?></h2>
                <p><?php echo $modalMode === 'edit' ? 'Actualiza el acceso y la configuracion del usuario seleccionado.' : ($modalMode === 'reactivate' ? 'Recupera una cuenta inactiva y asignale una contrasena nueva.' : 'Crea un usuario nuevo sin salir del listado.'); ?></p>
            </div>
            <button type="button" class="modal-close" id="closeUsuarioModal" aria-label="Cerrar">&times;</button>
        </div>

        <?php if ($formError): ?>
            <div class="form-alert form-alert-error"><?php echo htmlspecialchars($formError); ?></div>
        <?php endif; ?>
        <?php if ($formInfo): ?>
            <div class="form-alert form-alert-info"><?php echo htmlspecialchars($formInfo); ?></div>
        <?php endif; ?>

        <form id="usuarioModalForm" action="<?php echo $mostrarInactivos ? 'usuarios_inactivos.php' : 'usuarios.php'; ?><?php echo $rolFiltro > 0 ? '?rol_filtro=' . $rolFiltro : ''; ?>" method="post" class="usuario-form" autocomplete="off">
            <input type="hidden" name="action" id="modalAction" value="<?php echo $modalMode === 'edit' ? 'update_user' : ($modalMode === 'reactivate' ? 'reactivate_user' : 'create_user'); ?>">
            <input type="hidden" name="id" id="modalUserId" value="<?php echo (int)$formData['id']; ?>">

            <div class="form-row">
                <label for="modalUsername">Usuario</label>
                <div class="field-stack validation-stack">
                    <input type="text" id="modalUsername" name="username" required value="<?php echo htmlspecialchars($formData['username']); ?>">
                    <div class="field-message-slot">
                        <span class="field-message field-error" id="username-error">Este usuario ya esta en uso.</span>
                        <span class="field-message field-valid" id="username-valid">Usuario disponible.</span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <label for="modalEmail">Correo</label>
                <div class="field-stack validation-stack">
                    <input type="email" id="modalEmail" name="email" required value="<?php echo htmlspecialchars($formData['email']); ?>">
                    <div class="field-message-slot">
                        <span class="field-message field-error" id="email-error">Este correo ya esta registrado.</span>
                        <span class="field-message field-valid" id="email-valid">Correo disponible.</span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <label for="modalPassword">Contrasena</label>
                <div class="field-stack">
                    <div class="password-field">
                        <input type="password" id="modalPassword" name="password" value="">
                        <button type="button" class="password-toggle" id="togglePassword">Ver</button>
                    </div>
                    <span class="field-hint" id="passwordHint">Minimo 6 caracteres. Solo letras y numeros.</span>
                </div>
            </div>

            <div class="form-row">
                <label for="modalRol">Rol</label>
                <div class="select-shell">
                    <select id="modalRol" name="rol_id" class="js-custom-select-source" required>
                        <option value="">Selecciona un rol</option>
                        <?php foreach ($rolesDisponibles as $rol): ?>
                            <option value="<?php echo (int)$rol['id']; ?>" <?php echo ((string)$rol['id'] === (string)$formData['rol_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($rol['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <label for="modalSucursal">Sucursal</label>
                <div class="select-shell">
                    <select id="modalSucursal" name="sucursal_id" class="js-custom-select-source" required>
                        <option value="">Selecciona una sucursal</option>
                        <?php foreach ($sucursalesDisponibles as $suc): ?>
                            <option value="<?php echo (int)$suc['id']; ?>" <?php echo ((string)$suc['id'] === (string)$formData['sucursal_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($suc['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="toolbar-button toolbar-button-secondary" id="cancelUsuarioModal">
                    <span>Cancelar</span>
                </button>
                <button type="submit" class="toolbar-button toolbar-button-primary">
                    <img src="../../imagenes/Save.png" alt="" aria-hidden="true" class="toolbar-icon">
                    <span id="modalSubmitLabel"><?php echo $modalMode === 'edit' ? 'Guardar cambios' : ($modalMode === 'reactivate' ? 'Reactivar usuario' : 'Guardar'); ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="deleteModalBackdrop"></div>
<div class="modal-shell modal-shell-compact" id="deleteModal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="modal-card modal-card-compact">
        <div class="modal-head modal-head-compact">
            <div class="modal-head-copy">
                <h2 id="deleteModalTitle">Eliminar usuario</h2>
                <p id="deleteModalMessage">¿Seguro que quieres eliminar este usuario?</p>
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
const todosUsuarios = <?php echo json_encode($todosUsuariosJS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const toastPayload = <?php echo json_encode($toastPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const mostrarInactivos = <?php echo $mostrarInactivos ? 'true' : 'false'; ?>;
const searchInput = document.getElementById('searchInput');
const tablaCuerpo = document.getElementById('tablaUsuarios');
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

const usuarioModal = document.getElementById('usuarioModal');
const usuarioModalBackdrop = document.getElementById('usuarioModalBackdrop');
const openCreateModal = document.getElementById('openCreateModal');
const closeUsuarioModal = document.getElementById('closeUsuarioModal');
const cancelUsuarioModal = document.getElementById('cancelUsuarioModal');
const modalTitle = document.getElementById('usuarioModalTitle');
const modalAction = document.getElementById('modalAction');
const modalUserId = document.getElementById('modalUserId');
const modalUsername = document.getElementById('modalUsername');
const modalEmail = document.getElementById('modalEmail');
const modalPassword = document.getElementById('modalPassword');
const modalRol = document.getElementById('modalRol');
const modalSucursal = document.getElementById('modalSucursal');
const modalSubmitLabel = document.getElementById('modalSubmitLabel');
const passwordHint = document.getElementById('passwordHint');
const togglePassword = document.getElementById('togglePassword');
const usernameError = document.getElementById('username-error');
const usernameValid = document.getElementById('username-valid');
const emailError = document.getElementById('email-error');
const emailValid = document.getElementById('email-valid');
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

function roleBadgeClass(rol) {
    const value = (rol || '').toLowerCase();
    if (value.includes('super')) return 'rol-super';
    if (value.includes('admin')) return 'rol-admin';
    if (value.includes('mec')) return 'rol-mech';
    if (value.includes('extern')) return 'rol-ext';
    return 'rol-user';
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function bindEditButtons() {
    document.querySelectorAll('.open-edit-modal').forEach(function (button) {
        button.onclick = function () {
            openModal('edit', {
                id: button.dataset.id,
                username: button.dataset.username,
                email: button.dataset.email,
                rolId: button.dataset.rolId,
                sucursalId: button.dataset.sucursalId
            });
        };
    });

    document.querySelectorAll('.open-delete-modal').forEach(function (button) {
        button.onclick = function () {
            openDeleteModal(button.dataset.href, button.dataset.username);
        };
    });

    document.querySelectorAll('.open-reactivate-modal').forEach(function (button) {
        button.onclick = function () {
            openModal('reactivate', {
                id: button.dataset.id,
                username: button.dataset.username,
                email: button.dataset.email,
                rolId: button.dataset.rolId,
                sucursalId: button.dataset.sucursalId
            });
        };
    });
}

function renderizarTabla(datos) {
    tablaCuerpo.innerHTML = '';

    if (datos.length === 0) {
        tablaCuerpo.innerHTML = '<tr><td colspan="5" class="table-empty-cell">No se encontraron coincidencias.</td></tr>';
        return;
    }

    datos.forEach(function (user) {
        const sucursal = user.sucursal ? user.sucursal : 'Global / Todas';
        const actionMarkup = mostrarInactivos
            ? `
                        <button
                            type="button"
                            class="action-pill action-pill-restore open-reactivate-modal"
                            data-id="${user.id}"
                            data-username="${escapeHtml(user.username)}"
                            data-email="${escapeHtml(user.email)}"
                            data-rol-id="${user.rol_id}"
                            data-sucursal-id="${user.sucursal_id}"
                        >Reactivar</button>
            `
            : `
                        <button
                            type="button"
                            class="action-pill action-pill-edit open-edit-modal"
                            data-id="${user.id}"
                            data-username="${escapeHtml(user.username)}"
                            data-email="${escapeHtml(user.email)}"
                            data-rol-id="${user.rol_id}"
                            data-sucursal-id="${user.sucursal_id}"
                        >Editar</button>
                        <button
                            type="button"
                            class="action-pill action-pill-delete open-delete-modal"
                            data-href="eliminar_usuario.php?id=${user.id}"
                            data-username="${escapeHtml(user.username)}"
                        >Eliminar</button>
            `;
        const fila = `
            <tr>
                <td data-label="Usuario"><div class="table-primary">${escapeHtml(user.username)}</div></td>
                <td data-label="Email"><span class="table-muted">${escapeHtml(user.email)}</span></td>
                <td data-label="Rol"><span class="rol-badge ${roleBadgeClass(user.rol)}">${escapeHtml(user.rol)}</span></td>
                <td data-label="Sucursal"><span class="table-muted">${escapeHtml(sucursal)}</span></td>
                <td data-label="Acciones">
                    <div class="table-actions">
                        <button
                            type="button"
                            class="action-pill action-pill-edit open-edit-modal"
                            data-id="${user.id}"
                            data-username="${escapeHtml(user.username)}"
                            data-email="${escapeHtml(user.email)}"
                            data-rol-id="${user.rol_id}"
                            data-sucursal-id="${user.sucursal_id}"
                        >Editar</button>
                        <a href="eliminar_usuario.php?id=${user.id}" class="action-pill action-pill-delete" onclick="return confirm('¿Esta seguro de eliminar a este usuario?')">Eliminar</a>
                    </div>
                </td>
            </tr>
        `;
        tablaCuerpo.insertAdjacentHTML('beforeend', fila);
    });

    bindEditButtons();
}

function renderizarTabla(datos) {
    tablaCuerpo.innerHTML = '';

    if (datos.length === 0) {
        tablaCuerpo.innerHTML = '<tr><td colspan="5" class="table-empty-cell">No se encontraron coincidencias.</td></tr>';
        return;
    }

    datos.forEach(function (user) {
        const sucursal = user.sucursal ? user.sucursal : 'Global / Todas';
        const actionMarkup = mostrarInactivos
            ? `
                <button
                    type="button"
                    class="action-pill action-pill-restore open-reactivate-modal"
                    data-id="${user.id}"
                    data-username="${escapeHtml(user.username)}"
                    data-email="${escapeHtml(user.email)}"
                    data-rol-id="${user.rol_id}"
                    data-sucursal-id="${user.sucursal_id}"
                >Reactivar</button>
            `
            : `
                <button
                    type="button"
                    class="action-pill action-pill-edit open-edit-modal"
                    data-id="${user.id}"
                    data-username="${escapeHtml(user.username)}"
                    data-email="${escapeHtml(user.email)}"
                    data-rol-id="${user.rol_id}"
                    data-sucursal-id="${user.sucursal_id}"
                >Editar</button>
                <button
                    type="button"
                    class="action-pill action-pill-delete open-delete-modal"
                    data-href="eliminar_usuario.php?id=${user.id}"
                    data-username="${escapeHtml(user.username)}"
                >Eliminar</button>
            `;

        const fila = `
            <tr>
                <td data-label="Usuario"><div class="table-primary">${escapeHtml(user.username)}</div></td>
                <td data-label="Email"><span class="table-muted">${escapeHtml(user.email)}</span></td>
                <td data-label="Rol"><span class="rol-badge ${roleBadgeClass(user.rol)}">${escapeHtml(user.rol)}</span></td>
                <td data-label="Sucursal"><span class="table-muted">${escapeHtml(sucursal)}</span></td>
                <td data-label="Acciones">
                    <div class="table-actions">
                        ${actionMarkup}
                    </div>
                </td>
            </tr>
        `;

        tablaCuerpo.insertAdjacentHTML('beforeend', fila);
    });

    bindEditButtons();
}

if (searchInput) {
    searchInput.addEventListener('input', function () {
        const texto = this.value.toLowerCase().trim();
        if (texto === '') {
            window.location.href = '<?php echo $mostrarInactivos ? 'usuarios_inactivos.php' : 'usuarios.php'; ?><?php echo $rolFiltro > 0 ? '?rol_filtro=' . $rolFiltro : ''; ?>';
            return;
        }

        const filtrados = todosUsuarios.filter(function (user) {
            return (user.username && user.username.toLowerCase().includes(texto))
                || (user.email && user.email.toLowerCase().includes(texto))
                || (user.rol && user.rol.toLowerCase().includes(texto))
                || (user.sucursal && user.sucursal.toLowerCase().includes(texto));
        });

        renderizarTabla(filtrados);
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

function resetValidationState() {
    [modalUsername, modalEmail].forEach(function (input) {
        input.classList.remove('error', 'valid');
    });
    [usernameError, usernameValid, emailError, emailValid].forEach(function (element) {
        element.classList.remove('show', 'fade-out');
    });
    usernameError.textContent = 'Este usuario ya esta en uso.';
    usernameValid.textContent = 'Usuario disponible.';
    emailError.textContent = 'Este correo ya esta registrado.';
    emailValid.textContent = 'Correo disponible.';
}

function openModal(mode, payload = {}) {
    modalTitle.textContent = mode === 'edit' ? 'Editar usuario' : (mode === 'reactivate' ? 'Reactivar usuario' : 'Nuevo usuario');
    modalAction.value = mode === 'edit' ? 'update_user' : (mode === 'reactivate' ? 'reactivate_user' : 'create_user');
    modalSubmitLabel.textContent = mode === 'edit' ? 'Guardar cambios' : (mode === 'reactivate' ? 'Reactivar usuario' : 'Guardar');
    modalUserId.value = payload.id || '';
    modalUsername.value = payload.username || '';
    modalEmail.value = payload.email || '';
    modalPassword.value = '';
    modalRol.value = payload.rolId || '';
    modalSucursal.value = payload.sucursalId || '';
    if (typeof modalRol._refreshCustomSelect === 'function') modalRol._refreshCustomSelect();
    if (typeof modalSucursal._refreshCustomSelect === 'function') modalSucursal._refreshCustomSelect();
    passwordHint.textContent = mode === 'edit'
        ? 'Dejala vacia si no deseas cambiar la contrasena.'
        : (mode === 'reactivate'
            ? 'Define una contrasena nueva para volver a activar esta cuenta.'
            : 'Minimo 6 caracteres. Solo letras y numeros.');
    resetValidationState();
    usuarioModal.classList.add('show');
    usuarioModalBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
    setTimeout(() => modalUsername.focus(), 40);
}

function hideModal() {
    usuarioModal.classList.remove('show');
    usuarioModalBackdrop.classList.remove('show');
    document.body.classList.remove('modal-open');
}

function openDeleteModal(href, usernameValue) {
    deleteModalMessage.textContent = `¿Seguro que quieres eliminar a ${usernameValue}?`;
    confirmDeleteLink.href = href;
    deleteModal.classList.add('show');
    deleteModalBackdrop.classList.add('show');
    document.body.classList.add('modal-open');
}

function hideDeleteModal() {
    deleteModal.classList.remove('show');
    deleteModalBackdrop.classList.remove('show');
    confirmDeleteLink.href = '#';
    if (!usuarioModal.classList.contains('show')) {
        document.body.classList.remove('modal-open');
    }
}

function runAvailabilityCheck(field, input, errorElement, validElement) {
    const value = input.value.trim();
    const currentId = modalAction.value === 'update_user' ? (modalUserId.value || '0') : '0';

    input.classList.remove('error', 'valid');
    errorElement.classList.remove('show');
    validElement.classList.remove('show', 'fade-out');

    if (!value) return;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'verificar_usuario.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
    if (xhr.status !== 200) return;

        if (xhr.responseText === 'exists') {
            errorElement.textContent = field === 'username'
                ? 'Este usuario ya esta en uso.'
                : 'Este correo ya esta registrado.';
            input.classList.add('error');
            errorElement.classList.add('show');
        } else if (xhr.responseText === 'inactive') {
            validElement.textContent = field === 'username'
                ? 'Existe una cuenta inactiva. Podras reactivarla al guardar.'
                : 'Este correo pertenece a una cuenta inactiva. Podras reactivarla al guardar.';
            input.classList.add('valid');
            validElement.classList.add('show');
        } else if (xhr.responseText === 'available') {
            validElement.textContent = field === 'username' ? 'Usuario disponible.' : 'Correo disponible.';
            input.classList.add('valid');
            validElement.classList.add('show');
            setTimeout(() => validElement.classList.add('fade-out'), 1800);
            setTimeout(() => validElement.classList.remove('show', 'fade-out'), 2800);
        }
    };
    xhr.send(`${field}=` + encodeURIComponent(value) + '&id=' + encodeURIComponent(currentId));
}

document.addEventListener('click', function (event) {
    document.querySelectorAll('.custom-select.open').forEach(function (dropdown) {
        if (!dropdown.contains(event.target)) {
            dropdown.classList.remove('open');
            const trigger = dropdown.querySelector('.custom-select-trigger');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            }
        }
    });
});

function buildCustomSelect(select) {
    const wrapper = select.closest('.select-shell');
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

    function syncFromSelect() {
        menu.innerHTML = '';
        const selectedOption = select.options[select.selectedIndex];
        value.textContent = selectedOption ? selectedOption.textContent : 'Selecciona una opcion';

        Array.from(select.options).forEach(function (option) {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'custom-select-option' + (option.selected ? ' active' : '');
            item.textContent = option.textContent;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
            item.disabled = option.disabled;

            item.addEventListener('click', function () {
                if (option.disabled) {
                    return;
                }
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                syncFromSelect();
                dropdown.classList.remove('open');
                trigger.setAttribute('aria-expanded', 'false');
            });

            menu.appendChild(item);
        });

        dropdown.classList.toggle('is-disabled', !!select.disabled);
    }

    trigger.addEventListener('click', function () {
        if (select.disabled) {
            return;
        }
        const isOpen = dropdown.classList.toggle('open');
        trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    select._refreshCustomSelect = syncFromSelect;
    syncFromSelect();
}

if (openCreateModal) {
    openCreateModal.addEventListener('click', function () {
        openModal('create');
    });
}

if (closeUsuarioModal) closeUsuarioModal.addEventListener('click', hideModal);
if (cancelUsuarioModal) cancelUsuarioModal.addEventListener('click', hideModal);
if (usuarioModalBackdrop) usuarioModalBackdrop.addEventListener('click', hideModal);
if (closeDeleteModal) closeDeleteModal.addEventListener('click', hideDeleteModal);
if (cancelDeleteModal) cancelDeleteModal.addEventListener('click', hideDeleteModal);
if (deleteModalBackdrop) deleteModalBackdrop.addEventListener('click', hideDeleteModal);

if (togglePassword) {
    togglePassword.addEventListener('click', function () {
        const visible = modalPassword.type === 'text';
        modalPassword.type = visible ? 'password' : 'text';
        togglePassword.textContent = visible ? 'Ver' : 'Ocultar';
    });
}

modalUsername.addEventListener('input', function () {
    runAvailabilityCheck('username', modalUsername, usernameError, usernameValid);
});

modalEmail.addEventListener('input', function () {
    runAvailabilityCheck('email', modalEmail, emailError, emailValid);
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        userMenu.classList.remove('open');
        userMenuTrigger.setAttribute('aria-expanded', 'false');
        if (sucursalDropdown && sucursalTrigger) {
            sucursalDropdown.classList.remove('open');
            sucursalTrigger.setAttribute('aria-expanded', 'false');
        }
        hideModal();
        hideDeleteModal();
    }
});

syncThemeToggle();
bindEditButtons();
document.querySelectorAll('.js-custom-select-source').forEach(buildCustomSelect);

if (toastPayload && toastPayload.text) {
    document.addEventListener('DOMContentLoaded', function () {
        showToast(toastPayload.text, toastPayload.type || 'success');
    });
}

<?php if ($modalOpen): ?>
document.addEventListener('DOMContentLoaded', function () {
    openModal('<?php echo $modalMode; ?>', {
        id: '<?php echo (int)$formData['id']; ?>',
        username: <?php echo json_encode($formData['username'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        email: <?php echo json_encode($formData['email'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        rolId: <?php echo json_encode((string)$formData['rol_id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        sucursalId: <?php echo json_encode((string)$formData['sucursal_id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    });
});
<?php endif; ?>
</script>
</body>
</html>
