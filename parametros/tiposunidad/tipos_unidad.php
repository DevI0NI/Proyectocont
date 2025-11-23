<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Habilitar la visualización de errores (en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

// Configuración de la paginación
$tiposPorPagina = 10;
$paginaActual   = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$inicio         = ($paginaActual > 1) ? ($paginaActual * $tiposPorPagina) - $tiposPorPagina : 0;

// Total de tipos (SOLO ACTIVOS)
// CAMBIO: Filtramos por estado_id = 1
$sqlTotal   = 'SELECT COUNT(*) FROM tipos_unidad WHERE estado_id = 1';
$stmtTotal  = $pdo->prepare($sqlTotal);
$stmtTotal->execute();
$totalTipos   = $stmtTotal->fetchColumn();
$totalPaginas = ceil($totalTipos / $tiposPorPagina);

// Tipos con # de modelos (SOLO ACTIVOS)
// CAMBIO: Filtramos la tabla principal (tu) y la unión (m) por estado_id = 1
$sql = '
  SELECT tu.id, tu.nombre, tu.descripcion, COUNT(m.id) AS cantidad_modelos
  FROM tipos_unidad tu
  LEFT JOIN modelos m ON tu.id = m.tipo_unidad_id AND m.estado_id = 1
  WHERE tu.estado_id = 1
  GROUP BY tu.id
  LIMIT :inicio, :tiposPorPagina
';
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':inicio', $inicio, PDO::PARAM_INT);
$stmt->bindParam(':tiposPorPagina', $tiposPorPagina, PDO::PARAM_INT);
$stmt->execute();
$tipos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tipos de Unidad</title>
  <link rel="stylesheet" href="../../styles/parametros/tiposunidad/tiposunidad.css">
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

<?php if (!empty($_SESSION['toast'])): ?>
  <!-- Notificación -->
  <div class="toast-container">
    <div class="toast <?php echo htmlspecialchars($_SESSION['toast']['type']); ?>">
      <?php echo htmlspecialchars($_SESSION['toast']['text']); ?>
    </div>
  </div>
  <?php unset($_SESSION['toast']); ?>
<?php endif; ?>

<main>
  <div class="title-container">
    <h1>Tipos de Unidad</h1>
    <a href="nuevaunidad.php" class="nuevo-button">
      <div class="nuevo-content">
        <img src="../../imagenes/nuevo.png" alt="Nuevo" class="nuevo-icon">
        <span>Nuevo</span>
      </div>
    </a>
  </div>

  <div class="table-container">
    <div class="table-scroll"><!-- wrapper para scroll en pantallas pequeñas -->
      <table class="responsive">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>Descripción</th>
            <th>Modelos</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tipos as $tipo): ?>
            <tr>
              <td data-label="Nombre"><?php echo htmlspecialchars($tipo['nombre']); ?></td>
              <td data-label="Descripción" class="descripcion"><?php echo htmlspecialchars($tipo['descripcion']); ?></td>
              <td data-label="Modelos"><?php echo (int)$tipo['cantidad_modelos']; ?></td>
              <td data-label="Acciones" class="acciones">
                <a href="infounidad.php?id=<?php echo (int)$tipo['id']; ?>" class="modificar">Información</a> |
                <!-- Nota: El archivo eliminar_tipo_unidad.php es el que hará el soft delete -->
                <a href="eliminar_tipo_unidad.php?id=<?php echo (int)$tipo['id']; ?>" class="eliminar" onclick="return confirm('¿Está seguro de eliminar este tipo de unidad?')">Eliminar</a>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (count($tipos) === 0): ?>
            <tr>
              <td colspan="4" style="text-align:center;">No hay tipos de unidad registrados</td>
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
  </div>
</main>

<footer>
  <p>&copy; IPSA Web - 2025</p>
</footer>
</body>
</html>