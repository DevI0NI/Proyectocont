<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

require '../config/database.php';

// Obtener los datos del contenedor
if (isset($_GET['nombre'])) {
    $nombreContenedor = $_GET['nombre'];
    $stmt = $pdo->prepare('
        SELECT 
            c.*,
            t.nombre as tipo_unidad_nombre,
            m.nombre as modelo_nombre,
            b.numero as bloque_numero,
            p.nombre as patio_nombre
        FROM contenedores c
        LEFT JOIN tipos_unidad t ON c.tipo_unidad_id = t.id
        LEFT JOIN modelos m ON c.modelo_id = m.id
        LEFT JOIN bloques b ON c.bloque_id = b.id
        LEFT JOIN patios p ON b.patio_id = p.id
        WHERE c.nombre = :nombre
    ');
    $stmt->execute(['nombre' => $nombreContenedor]);
    $contenedor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contenedor) {
        $_SESSION['toast_message'] = 'Contenedor no encontrado';
        $_SESSION['toast_type'] = 'error';
        header('Location: bloques.php');
        exit;
    }
} else {
    $_SESSION['toast_message'] = 'No se especificó un contenedor';
    $_SESSION['toast_type'] = 'error';
    header('Location: bloques.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Información del Contenedor</title>
    <link rel="stylesheet" href="../styles/parametros/bloques/informacion_contenedor.css">
</head>
<body>
    <header>
        <div id="toast-container" class="toast-container"></div>
        <div class="header-left">
            <a href="../index.php">
                <img src="../imagenes/IPSA.png" alt="Logo" class="logo">
                <span>IPROPORTSA</span>
            </a>
        </div>
        <div class="header-right">
            <span>Bienvenido, <?php echo $_SESSION['username']; ?></span>
            <a href="../logout.php" class="logout-button">Cerrar sesión</a>
        </div>
    </header>
    <main>
        <h1>Información del Contenedor</h1>
        <form>
            <div class="form-group">
                <label for="codigo_contenedor">Código del Contenedor:</label>
                <input type="text" id="codigo_contenedor" value="<?php echo htmlspecialchars($contenedor['nombre']); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="tipo_unidad">Tipo de Unidad:</label>
                <input type="text" id="tipo_unidad" value="<?php echo htmlspecialchars($contenedor['tipo_unidad_nombre']); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="modelo">Modelo:</label>
                <input type="text" id="modelo" value="<?php echo htmlspecialchars($contenedor['modelo_nombre']); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="estado_unidad">Estado de la Unidad:</label>
                <input type="text" id="estado_unidad" value="<?php echo htmlspecialchars($contenedor['estatus_unidad']); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="observaciones_estado">Observaciones del Estado:</label>
                <textarea id="observaciones_estado" readonly><?php echo htmlspecialchars($contenedor['observaciones_unidad']); ?></textarea>
            </div>

            <div class="form-group">
                <label for="status_box">Status Box:</label>
                <input type="text" id="status_box" value="<?php echo htmlspecialchars($contenedor['estatus_box']); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="observaciones_box">Observaciones Box:</label>
                <textarea id="observaciones_box" readonly><?php echo htmlspecialchars($contenedor['observaciones_box']); ?></textarea>
            </div>

            <div class="form-group">
                <label for="ubicacion">Ubicación:</label>
                <input type="text" id="ubicacion" value="Patio: <?php echo htmlspecialchars($contenedor['patio_nombre']); ?>, Bloque: <?php echo htmlspecialchars($contenedor['bloque_numero']); ?>" readonly>
            </div>

            <div class="buttons">
                <a href="bloques.php" class="regresar-button">
                    <img src="../imagenes/regresar.png" alt="Regresar">
                    <span>Regresar</span>
                </a>
            <div class="buttons">
                <a href="modificar_contenedor.php?nombre=<?php echo urlencode($contenedor['nombre']); ?>" class="guardar-button">
                    <img src="../imagenes/modificar.png" alt="Modificar">
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