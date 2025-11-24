<?php
session_start();

// 1. Seguridad de Sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// 2. Seguridad de Rol (Solo Super Admin y Admin)
if (!in_array($_SESSION['rol_id'], [1, 2])) {
    header('Location: ../../index.php');
    exit;
}

// Habilitar errores para desarrollo
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

// --- Configuración de Paginación ---
$itemsPorPagina = 10;
$paginaActual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$inicio = ($paginaActual > 1) ? ($paginaActual * $itemsPorPagina) - $itemsPorPagina : 0;

// --- LÓGICA DE SEGURIDAD Y FILTROS ---
$rol_usuario_actual = $_SESSION['rol_id'];
$sucursal_usuario_actual = $_SESSION['sucursal_usuario'];

// Filtro seleccionado por el usuario (si existe)
$rolFiltro = isset($_GET['rol_filtro']) ? (int)$_GET['rol_filtro'] : 0;

// Clausula base: Solo activos
$whereClause = "WHERE u.estado_id = 1"; 
$params = [];

// 1. Aplicar Restricciones de Jerarquía (Seguridad)
if ($rol_usuario_actual == 2) {
    // Admin: Solo su sucursal y roles inferiores (>2)
    $whereClause .= " AND u.sucursal_id = :mi_sucursal AND u.rol_id > 2";
    $params['mi_sucursal'] = $sucursal_usuario_actual;
}

// 2. Aplicar Filtro de Botón (Navegación)
if ($rolFiltro > 0) {
    $whereClause .= " AND u.rol_id = :rol_filtro";
    $params['rol_filtro'] = $rolFiltro;
}

// --- CONSULTAS ---

// A. Total para Paginación
$sqlTotal = "SELECT COUNT(*) FROM users u $whereClause";
$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute($params);
$totalItems = $stmtTotal->fetchColumn();
$totalPaginas = ceil($totalItems / $itemsPorPagina);

// B. Listado Paginado (Para vista normal)
$sql = "
    SELECT u.id, u.username, u.email, r.nombre as rol, s.nombre as sucursal
    FROM users u
    JOIN roles r ON u.rol_id = r.id
    LEFT JOIN sucursales s ON u.sucursal_id = s.id
    $whereClause
    ORDER BY u.username ASC 
    LIMIT $inicio, $itemsPorPagina
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// C. DATOS PARA BÚSQUEDA JS (Cargamos TODOS los visibles sin paginación)
// Quitamos el límite para que la búsqueda JS encuentre cualquier usuario permitido
// pero mantenemos los filtros de seguridad ($whereClause)
$sqlJS = "
    SELECT u.id, u.username, u.email, r.nombre as rol, s.nombre as sucursal
    FROM users u
    JOIN roles r ON u.rol_id = r.id
    LEFT JOIN sucursales s ON u.sucursal_id = s.id
    $whereClause
    ORDER BY u.username ASC
";
$stmtJS = $pdo->prepare($sqlJS);
// Usamos los mismos parámetros (seguridad y filtro actual)
$stmtJS->execute($params); 
$todosUsuariosJS = $stmtJS->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios</title>
    <link rel="stylesheet" href="../../styles/parametros/usuarios.css">
    <style>
        /* Estilos Toast */
        #toast-container { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; width: 90%; max-width: 400px; pointer-events: none; }
        .toast { padding: 15px 20px; border-radius: 8px; color: #fff; font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,0.15); opacity: 0; transform: translateY(-20px); transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 10px; }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.success { background-color: #28a745; border-left: 5px solid #1e7e34; }
        .toast.error { background-color: #dc3545; border-left: 5px solid #bd2130; }

        /* Badges de Rol */
        .rol-badge { padding: 4px 8px; border-radius: 12px; font-size: 0.85em; font-weight: bold; color: white; white-space: nowrap; }
        .rol-super { background-color: #FFD700; color: black; }
        .rol-admin { background-color: #2196F3; }
        .rol-user  { background-color: #4CAF50; }
        .rol-mech  { background-color: #FF9800; }
        .rol-ext   { background-color: #9C27B0; }

       
    </style>
</head>
<body>
<header>
  <div class="header-left">
    <a href="../../index.php">
      <img src="../../imagenes/IPSA.png" alt="Logo" class="logo">
      <span class="brand-name">IPROPORTSA</span>
    </a>
  </div>
  <div class="header-right">
    <span class="welcome-label">Bienvenido,</span>
    <span class="username"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></span>
    <a href="../../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

<div id="toast-container"></div>

<main>
    <div class="title-container">
        <h1>Usuarios del Sistema</h1>
        <a href="nuevo_usuario.php" class="nuevo-button">
            <div class="nuevo-content">
                <img src="../../imagenes/nuevo.png" alt="Nuevo" class="nuevo-icon">
                <span>Nuevo</span>
            </div>
        </a>
    </div>

    <!-- NUEVO: BARRA DE FILTROS Y BÚSQUEDA -->
    <div class="filters-container">
        <div class="filter-group">
            <!-- Botón 'Todos' limpia el filtro -->
            <a href="usuarios.php" class="btn-filter <?php echo ($rolFiltro == 0) ? 'active' : ''; ?>">Todos</a>
            
            <!-- Botones dinámicos (puedes agregar más según los IDs de tu BD) -->
            <?php if($rol_usuario_actual == 1): ?>
                <a href="?rol_filtro=2" class="btn-filter <?php echo ($rolFiltro == 2) ? 'active' : ''; ?>">Admins</a>
            <?php endif; ?>
            
            <a href="?rol_filtro=3" class="btn-filter <?php echo ($rolFiltro == 3) ? 'active' : ''; ?>">Usuarios</a>
            <a href="?rol_filtro=4" class="btn-filter <?php echo ($rolFiltro == 4) ? 'active' : ''; ?>">Mecánicos</a>
            <a href="?rol_filtro=5" class="btn-filter <?php echo ($rolFiltro == 5) ? 'active' : ''; ?>">Externos</a>
        </div>

        <div class="search-box">
            <span class="search-icon">🔍</span>
            <input type="text" id="searchInput" class="search-input" placeholder="Buscar usuario...">
        </div>
    </div>

    <div class="table-container">
        <div class="table-scroll">
            <table class="responsive">
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
                    <!-- El contenido inicial es cargado por PHP -->
                    <?php foreach ($usuarios as $usr): ?>
                    <tr>
                        <td data-label="Usuario"><strong><?php echo htmlspecialchars($usr['username']); ?></strong></td>
                        <td data-label="Email"><?php echo htmlspecialchars($usr['email']); ?></td>
                        <td data-label="Rol">
                            <?php 
                                $badgeClass = 'rol-user';
                                if (stripos($usr['rol'], 'Super') !== false) $badgeClass = 'rol-super';
                                elseif (stripos($usr['rol'], 'Admin') !== false) $badgeClass = 'rol-admin';
                                elseif (stripos($usr['rol'], 'Mec') !== false) $badgeClass = 'rol-mech';
                                elseif (stripos($usr['rol'], 'Extern') !== false) $badgeClass = 'rol-ext';
                            ?>
                            <span class="rol-badge <?php echo $badgeClass; ?>">
                                <?php echo htmlspecialchars($usr['rol']); ?>
                            </span>
                        </td>
                        <td data-label="Sucursal">
                            <?php echo htmlspecialchars($usr['sucursal'] ?? 'Global / Todas'); ?>
                        </td>
                        <td data-label="Acciones" class="acciones">
                            <a href="editar_usuario.php?id=<?php echo $usr['id']; ?>" class="modificar">Editar</a> |
                            <a href="eliminar_usuario.php?id=<?php echo $usr['id']; ?>" class="eliminar" onclick="return confirm('¿Está seguro de eliminar a este usuario?')">Eliminar</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (count($usuarios) === 0): ?>
                    <tr><td colspan="5" style="text-align:center;">No se encontraron usuarios.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Paginación (Se oculta cuando se usa la búsqueda JS) -->
    <div class="pagination-container" id="paginacion">
        <a href="../parametros.php" class="regresar-button">
            <div class="regresar-content">
                <img src="../../imagenes/regresar.png" alt="Regresar" class="regresar-icon">
                <span>Regresar</span>
            </div>
        </a>

        <?php if ($totalPaginas > 1): ?>
        <div class="pagination">
            <?php 
                // Mantener el filtro de rol en los enlaces de paginación
                $urlBase = "?";
                if($rolFiltro > 0) $urlBase .= "rol_filtro=$rolFiltro&";
            ?>
            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                <a href="<?php echo $urlBase; ?>pagina=<?php echo $i; ?>" class="pagination-button <?php echo ($i == $paginaActual) ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<footer>
    <p>&copy; IPSA Web - 2025</p>
</footer>

<script>
    // 1. Datos completos para búsqueda en tiempo real (JSON generado por PHP)
    const todosUsuarios = <?php echo json_encode($todosUsuariosJS); ?>;
    
    const searchInput = document.getElementById('searchInput');
    const tablaCuerpo = document.getElementById('tablaUsuarios');
    const paginacion = document.getElementById('paginacion');

    // 2. Función de Búsqueda JS
    searchInput.addEventListener('input', function() {
        const texto = this.value.toLowerCase().trim();

        if (texto === '') {
            // Si no hay búsqueda, recargar la página para volver al estado PHP paginado original
            // (O podríamos guardar el HTML original en una variable, pero recargar es más limpio para resets completos)
            if(this.dataset.searching === "true") {
                 window.location.reload();
            }
            return;
        }

        this.dataset.searching = "true"; // Marcar que estamos buscando
        paginacion.style.display = 'none'; // Ocultar paginación durante búsqueda

        // Filtrar array JS
        const filtrados = todosUsuarios.filter(user => 
            user.username.toLowerCase().includes(texto) || 
            user.email.toLowerCase().includes(texto) ||
            (user.rol && user.rol.toLowerCase().includes(texto)) ||
            (user.sucursal && user.sucursal.toLowerCase().includes(texto))
        );

        renderizarTabla(filtrados);
    });

    // 3. Renderizar Tabla
    function renderizarTabla(datos) {
        tablaCuerpo.innerHTML = '';

        if (datos.length === 0) {
            tablaCuerpo.innerHTML = '<tr><td colspan="5" style="text-align:center;">No se encontraron coincidencias.</td></tr>';
            return;
        }

        datos.forEach(user => {
            // Lógica de colores de badge (replicada de PHP a JS)
            let badgeClass = 'rol-user';
            const r = user.rol ? user.rol.toLowerCase() : '';
            if (r.includes('super')) badgeClass = 'rol-super';
            else if (r.includes('admin')) badgeClass = 'rol-admin';
            else if (r.includes('mec')) badgeClass = 'rol-mech';
            else if (r.includes('extern')) badgeClass = 'rol-ext';

            const sucursal = user.sucursal ? user.sucursal : 'Global / Todas';

            const fila = `
                <tr>
                    <td data-label="Usuario"><strong>${escapeHtml(user.username)}</strong></td>
                    <td data-label="Email">${escapeHtml(user.email)}</td>
                    <td data-label="Rol"><span class="rol-badge ${badgeClass}">${escapeHtml(user.rol)}</span></td>
                    <td data-label="Sucursal">${escapeHtml(sucursal)}</td>
                    <td data-label="Acciones" class="acciones">
                        <a href="editar_usuario.php?id=${user.id}" class="modificar">Editar</a> |
                        <a href="eliminar_usuario.php?id=${user.id}" class="eliminar" onclick="return confirm('¿Está seguro de eliminar a este usuario?')">Eliminar</a>
                    </td>
                </tr>
            `;
            tablaCuerpo.innerHTML += fila;
        });
    }

    // Helper para escapar HTML (Seguridad XSS en JS)
    function escapeHtml(text) {
        if (!text) return "";
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

  // Toast Logic
  function showToast(message, type) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    void toast.offsetWidth; toast.classList.add('show');
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 4000);
  }
  <?php if (isset($_SESSION['toast'])): ?>
    showToast('<?php echo $_SESSION['toast']['text']; ?>', '<?php echo $_SESSION['toast']['type']; ?>');
    <?php unset($_SESSION['toast']); ?>
  <?php endif; ?>
</script>
</body>
</html>