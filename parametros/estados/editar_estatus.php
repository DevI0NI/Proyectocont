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
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Obtener los datos del estatus
$stmt = $pdo->prepare('SELECT * FROM estatus_contenedores WHERE id = :id');
$stmt->execute(['id' => $id]);
$estatus = $stmt->fetch();

if (!$estatus) {
    die('El estatus no existe.');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tipo = $_POST['tipo'];
    $nombre = trim($_POST['nombre']); // Eliminar espacios en blanco

    try {
        // Verificar si el estatus ya existe para el tipo seleccionado (excluyendo el actual)
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM estatus_contenedores WHERE nombre = :nombre AND tipo = :tipo AND id != :id');
        $stmt->execute(['nombre' => $nombre, 'tipo' => $tipo, 'id' => $id]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            throw new Exception('Ese estatus ya existe para el tipo seleccionado.');
        }

        // Actualizar el estatus en la base de datos
        $stmt = $pdo->prepare('UPDATE estatus_contenedores SET nombre = :nombre, tipo = :tipo WHERE id = :id');
        $stmt->execute([
            'nombre' => $nombre,
            'tipo' => $tipo,
            'id' => $id
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Estatus</title>
    <link rel="stylesheet" href="../../styles/parametros/estados/nuevo_estatus.css">
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const nombreInput = document.getElementById('nombre');
            const tipoSelect = document.getElementById('tipo');
            const nombreError = document.getElementById('nombre-error');
            const nombreValid = document.getElementById('nombre-valid');

            nombreInput.addEventListener('input', function () {
                const nombre = nombreInput.value.trim();
                const tipo = tipoSelect.value;

                if (nombre === '<?php echo htmlspecialchars($estatus['nombre']); ?>') {
                    // Si el nombre no ha cambiado, no mostrar errores
                    nombreError.style.display = 'none';
                    nombreValid.style.display = 'inline';
                    nombreInput.classList.remove('error');
                    nombreInput.classList.add('valid');
                    return;
                }

                if (tipo === '') {
                    nombreError.style.display = 'none';
                    nombreValid.style.display = 'none';
                    return;
                }

                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'verificar_estatus.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        if (xhr.responseText === 'exists') {
                            nombreInput.classList.add('error');
                            nombreInput.classList.remove('valid');
                            nombreError.style.display = 'inline';
                            nombreValid.style.display = 'none';
                        } else if (xhr.responseText === 'available') {
                            nombreInput.classList.add('valid');
                            nombreInput.classList.remove('error');
                            nombreError.style.display = 'none';
                            nombreValid.style.display = 'inline';
                        }
                    }
                };
                xhr.send('nombre=' + encodeURIComponent(nombre) + '&tipo=' + encodeURIComponent(tipo) + '&id=<?php echo $id; ?>');
            });
        });
    </script>
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
        <h1>Editar Estatus</h1>
        <?php if ($error_message): ?>
            <p style="color: red;"><?php echo $error_message; ?></p>
        <?php endif; ?>
        <form action="editar_estatus.php?id=<?php echo $id; ?>" method="post">
            <div class="form-group">
                <label for="tipo">Tipo de Estatus:</label>
                <select id="tipo" name="tipo" required>
                    <option value="Unidad" <?php echo $estatus['tipo'] === 'Unidad' ? 'selected' : ''; ?>>Unidad</option>
                    <option value="Estructura" <?php echo $estatus['tipo'] === 'Estructura' ? 'selected' : ''; ?>>Estructura</option>
                </select>
            </div>
            <div class="form-group">
                <label for="nombre">Nombre del Estatus:</label>
                <input type="text" id="nombre" name="nombre" value="<?php echo htmlspecialchars($estatus['nombre']); ?>" required pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" title="Solo se permiten letras y espacios.">
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