<?php
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

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tipo = $_POST['tipo'];
    $nombre = trim($_POST['nombre']); // Eliminar espacios en blanco

    try {
        // Verificar si el estatus ya existe para el tipo seleccionado
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM estatus_contenedores WHERE nombre = :nombre AND tipo = :tipo');
        $stmt->execute(['nombre' => $nombre, 'tipo' => $tipo]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            throw new Exception('Ese estatus ya existe para el tipo seleccionado.');
        }

        // Insertar el nuevo estatus en la base de datos
        $stmt = $pdo->prepare('INSERT INTO estatus_contenedores (nombre, tipo) VALUES (:nombre, :tipo)');
        $stmt->execute([
            'nombre' => $nombre,
            'tipo' => $tipo
        ]);

        // Redirigir a la página de estatus
        header('Location: estados.php');
        exit;
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nuevo Estatus</title>
    <link rel="stylesheet" href="../../styles/parametros/estados/nuevo_estatus.css">
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
        <h1>Nuevo Estatus</h1>
        <?php if ($error_message): ?>
            <p style="color: red;"><?php echo $error_message; ?></p>
        <?php endif; ?>
        <form action="nuevo_estatus.php" method="post">
            <div class="form-group">
                <label for="tipo">Tipo de Estatus:</label>
                <select id="tipo" name="tipo" required>
                    <option value="">Seleccione un tipo</option>
                    <option value="Unidad">Unidad</option>
                    <option value="Estructura">Estructura</option>
                </select>
            </div>
            <div class="form-group">
                <label for="nombre">Nombre del Estatus:</label>
                <input type="text" id="nombre" name="nombre" required pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" title="Solo se permiten letras y espacios.">
                <span class="error-message" id="nombre-error" style="color: red; display: none;">Ese estatus ya existe.</span>
                <span class="valid-message" id="nombre-valid" style="color: green; display: none;">Nombre disponible.</span>
            </div>
            <div class="buttons">
                <a href="estados.php" class="regresar-button">
                    <img src="../../imagenes/regresar.png" alt="Regresar">
                    <span>Regresar</span>
                </a>
                <button type="submit" class="guardar-button">
                    <img src="../../imagenes/Save.png" alt="Guardar">
                    <span>Guardar</span>
                </button>
            </div>
        </form>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>
</body>
</html>
<script>
    document.getElementById('nombre').addEventListener('input', function () {
        var nombre = document.getElementById('nombre').value;
        var tipo = document.getElementById('tipo').value;

        if (tipo === '') {
            document.getElementById('nombre-error').style.display = 'none';
            document.getElementById('nombre-valid').style.display = 'none';
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'verificar_nombre_estatus.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                if (xhr.responseText === 'exists') {
                    document.getElementById('nombre').classList.add('error');
                    document.getElementById('nombre').classList.remove('valid');
                    document.getElementById('nombre-error').style.display = 'inline';
                    document.getElementById('nombre-valid').style.display = 'none';
                } else if (xhr.responseText === 'available') {
                    document.getElementById('nombre').classList.add('valid');
                    document.getElementById('nombre').classList.remove('error');
                    document.getElementById('nombre-error').style.display = 'none';
                    document.getElementById('nombre-valid').style.display = 'inline';
                }
            }
        };
        xhr.send('nombre=' + encodeURIComponent(nombre) + '&tipo=' + encodeURIComponent(tipo));
    });
</script>