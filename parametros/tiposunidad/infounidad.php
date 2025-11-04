<?php
// filepath: c:\xampp\htdocs\proyectocont\parametros\tiposunidad\informacion_tipo_unidad.php

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Habilitar la visualización de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

// Obtener el ID del tipo de unidad desde la URL
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Consultar los datos del tipo de unidad
$stmt = $pdo->prepare('SELECT * FROM tipos_unidad WHERE id = :id');
$stmt->execute(['id' => $id]);
$tipoUnidad = $stmt->fetch();

if (!$tipoUnidad) {
    die('El tipo de unidad no existe.');
}

// Consultar los modelos asociados
$stmtModelos = $pdo->prepare('SELECT nombre FROM modelos WHERE tipo_unidad_id = :id');
$stmtModelos->execute(['id' => $id]);
$modelos = $stmtModelos->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Información del Tipo de Unidad</title>
    <link rel="stylesheet" href="../../styles/parametros/tiposunidad/infounidad.css">
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
    <span class="username"><?php echo $_SESSION['username']; ?></span>
    <a href="../../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>
    <main>
        <h1>Información del Tipo de Unidad</h1>
        <form>
            <div class="form-group">
                <label for="nombre">Nombre:</label>
                <input type="text" id="nombre" name="nombre" value="<?php echo htmlspecialchars($tipoUnidad['nombre']); ?>" readonly>
            </div>
            <div class="form-group">
                <label for="observaciones">Observaciones:</label>
                <textarea id="observaciones" name="observaciones" readonly><?php echo htmlspecialchars($tipoUnidad['descripcion']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="modelos">Modelos:</label>
                <div id="modelos-container">
                    <?php foreach ($modelos as $modelo): ?>
                        <input type="text" value="<?php echo htmlspecialchars($modelo['nombre']); ?>" readonly>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="buttons">
    <a href="tipos_unidad.php" class="regresar-button">
        <img src="../../imagenes/regresar.png" alt="Regresar">
        <span>Regresar</span>
    </a>
    <a href="editarunidad.php?id=<?php echo $tipoUnidad['id']; ?>" class="guardar-button">
        <img src="../../imagenes/modificar.png" alt="Modificar">
        <span>Modificar</span>
    </a>
</div>
        </form>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>
</body>
</html>