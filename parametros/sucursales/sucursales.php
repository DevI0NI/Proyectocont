<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
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

// --- 1. Obtener Total de Sucursales Activas ---
$sqlTotal = 'SELECT COUNT(*) FROM sucursales WHERE estado_id = 1';
$stmtTotal = $pdo->query($sqlTotal);
$totalItems = $stmtTotal->fetchColumn();
$totalPaginas = ceil($totalItems / $itemsPorPagina);

// --- 2. Obtener Listado de Sucursales ---
$sql = '
    SELECT id, nombre, ubicacion 
    FROM sucursales 
    WHERE estado_id = 1 
    ORDER BY nombre ASC 
    LIMIT :inicio, :items
';
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':inicio', $inicio, PDO::PARAM_INT);
$stmt->bindParam(':items', $itemsPorPagina, PDO::PARAM_INT);
$stmt->execute();
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Sucursales</title>
    <!-- Reutilizamos el CSS de tiposunidad para mantener consistencia visual rápida -->
    <link rel="stylesheet" href="../../styles/parametros/tiposunidad/tiposunidad.css">
    <style>
        /* Estilos específicos para Toasts (si no están en el CSS global) */
        #toast-container { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; width: 90%; max-width: 400px; pointer-events: none; }
        .toast { padding: 15px 20px; border-radius: 8px; color: #fff; font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,0.15); opacity: 0; transform: translateY(-20px); transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 10px; }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.error { background-color: #dc3545; border-left: 5px solid #bd2130; }
        .toast.success { background-color: #28a745; border-left: 5px solid #1e7e34; }
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

<!-- Contenedor de Notificaciones -->
<div id="toast-container"></div>

<main>
    <div class="title-container">
        <h1>Sucursales</h1>
        <a href="nueva_sucursal.php" class="nuevo-button">
            <div class="nuevo-content">
                <img src="../../imagenes/nuevo.png" alt="Nuevo" class="nuevo-icon">
                <span>Nueva</span>
            </div>
        </a>
    </div>

    <div class="table-container">
        <div class="table-scroll">
            <table class="responsive">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Ubicación</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sucursales as $sucursal): ?>
                    <tr>
                        <td data-label="Nombre">
                            <?php echo htmlspecialchars($sucursal['nombre']); ?>
                        </td>
                        <td data-label="Ubicación">
                            <?php echo htmlspecialchars($sucursal['ubicacion']); ?>
                        </td>
                        <td data-label="Acciones" class="acciones">
                            <a href="editar_sucursal.php?id=<?php echo $sucursal['id']; ?>" class="modificar">Editar</a> |
                            <a href="eliminar_sucursal.php?id=<?php echo $sucursal['id']; ?>" class="eliminar" onclick="return confirm('¿Seguro que desea eliminar esta sucursal?')">Eliminar</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (count($sucursales) === 0): ?>
                    <tr>
                        <td colspan="3" style="text-align:center;">No hay sucursales registradas.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="pagination-container">
        <a href="../parametros.php" class="regresar-button">
            <div class="regresar-content">
                <img src="../../imagenes/regresar.png" alt="Regresar" class="regresar-icon">
                <span>Regresar</span>
            </div>
        </a>

        <!-- Paginación -->
        <?php if ($totalPaginas > 1): ?>
        <div class="pagination">
            <?php if ($paginaActual > 1): ?>
                <a href="?pagina=<?php echo $paginaActual - 1; ?>" class="pagination-button">Anterior</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                <a href="?pagina=<?php echo $i; ?>" class="pagination-button <?php echo ($i == $paginaActual) ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>

            <?php if ($paginaActual < $totalPaginas): ?>
                <a href="?pagina=<?php echo $paginaActual + 1; ?>" class="pagination-button">Siguiente</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<footer>
    <p>&copy; IPSA Web - 2025</p>
</footer>

<script>
  // Función para mostrar notificaciones
  function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    
    // Animación de entrada
    void toast.offsetWidth; 
    toast.classList.add('show');

    // Eliminar
    setTimeout(() => { 
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
  }

  // ESTA ES LA PARTE QUE FALTABA: Inyectar mensaje PHP si existe en la sesión
  <?php if (isset($_SESSION['toast'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        // Nota: En nueva_sucursal.php usamos $_SESSION['toast'] = ['type'=>..., 'text'=>...]
        // Asegúrate de usar las claves correctas ('text' y 'type')
        showToast(
            '<?php echo addslashes($_SESSION['toast']['text']); ?>', 
            '<?php echo $_SESSION['toast']['type'] ?? "success"; ?>'
        );
    });
    <?php unset($_SESSION['toast']); ?>
  <?php endif; ?>
</script>
</body>
</html>