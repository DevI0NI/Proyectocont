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
    $nombre = $_POST['nombre'];
    $observaciones = $_POST['observaciones'];
    $modelos = array_filter($_POST['modelos']); // Filtrar modelos vacíos

    try {
        // Iniciar una transacción
        $pdo->beginTransaction();

        // Verificar si el nombre ya existe en la base de datos
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tipos_unidad WHERE nombre = :nombre');
        $stmt->execute(['nombre' => $nombre]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            throw new Exception('Ese tipo de unidad ya existe.');
        }

        // Insertar el nuevo tipo de unidad en la base de datos
        $stmt = $pdo->prepare('INSERT INTO tipos_unidad (nombre, descripcion) VALUES (:nombre, :descripcion)');
        $stmt->execute([
            'nombre' => $nombre,
            'descripcion' => $observaciones
        ]);

        // Obtener el ID del tipo de unidad recién insertado
        $tipo_unidad_id = $pdo->lastInsertId();

        // Insertar los modelos asociados
        $stmtModelo = $pdo->prepare('INSERT INTO modelos (nombre, tipo_unidad_id) VALUES (:nombre, :tipo_unidad_id)');
        foreach ($modelos as $modelo) {
            $stmtModelo->execute([
                'nombre' => $modelo,
                'tipo_unidad_id' => $tipo_unidad_id
            ]);
        }

        // Confirmar la transacción
        $pdo->commit();

        // Redirigir a tipos_unidad.php
        header('Location: tipos_unidad.php');
        exit;
    } catch (Exception $e) {
        // Revertir la transacción en caso de error
        $pdo->rollBack();
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo Tipo de Unidad</title>
    <link rel="stylesheet" href="../../styles/parametros/tiposunidad/nuevaunidad.css">
    <style>
        .error {
            border: 2px solid red;
        }
        .valid {
            border: 2px solid green;
        }
        .error-message {
            color: red;
            display: none;
        }
        .valid-message {
            color: green;
            display: none;
            opacity: 1;
            transition: opacity 1s ease-out;
        }
        .valid-message.fade-out {
            opacity: 0;
        }
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
    <span class="username"><?php echo $_SESSION['username']; ?></span>
    <a href="../../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>
    <main>
        <h1>Nuevo Tipo de Unidad</h1>
        <?php if ($error_message): ?>
            <p style="color: red;"><?php echo $error_message; ?></p>
        <?php endif; ?>
        <form id="tipoUnidadForm" action="nuevaunidad.php" method="post">
            <div class="form-group">
                <label for="nombre">Nombre:</label>
                <input type="text" id="nombre" name="nombre" required pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" title="Solo se permiten letras y espacios.">
                <span class="error-message" id="nombre-error">Ese tipo de unidad ya existe.</span>
                <span class="valid-message" id="nombre-valid">Nombre disponible.</span>
            </div>
            <div class="form-group">
                <label for="observaciones">Observaciones:</label>
                <textarea id="observaciones" name="observaciones"></textarea>
            </div>
            <div class="form-group">
                <label for="modelos">Modelos:</label>
                <div id="modelos-container">
                    <input type="text" name="modelos[]" placeholder="Modelo 1">
                </div>
            </div>
            <div class="form-group">
                <div class="modelos-buttons">
                    <button type="button" id="agregarModelo" class="guardar-button">
                        <img src="../../imagenes/mas.png" alt="Agregar">
                        <span>Agregar Modelo</span>
                    </button>
                    <button type="button" id="eliminarModelo" class="eliminar-button">
                        <img src="../../imagenes/menos.png" alt="Eliminar">
                        <span>Eliminar Modelo</span>
                    </button>
                </div>
            </div>
            <div class="buttons">
                <a href="tipos_unidad.php" class="regresar-button">
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
    <script>
        // Verificar en tiempo real si el nombre ya existe
        document.getElementById('nombre').addEventListener('input', function() {
            var nombre = document.getElementById('nombre').value;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'verificar_nombre.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    if (xhr.responseText === 'exists') {
                        document.getElementById('nombre').classList.add('error');
                        document.getElementById('nombre').classList.remove('valid');
                        document.getElementById('nombre-error').style.display = 'inline';
                        document.getElementById('nombre-valid').style.display = 'none';
                    } else {
                        document.getElementById('nombre').classList.add('valid');
                        document.getElementById('nombre').classList.remove('error');
                        document.getElementById('nombre-error').style.display = 'none';
                        document.getElementById('nombre-valid').style.display = 'inline';
                        setTimeout(function() {
                            document.getElementById('nombre-valid').classList.add('fade-out');
                        }, 2000);
                        setTimeout(function() {
                            document.getElementById('nombre-valid').style.display = 'none';
                            document.getElementById('nombre-valid').classList.remove('fade-out');
                        }, 3000);
                    }
                }
            };
            xhr.send('nombre=' + encodeURIComponent(nombre));
        });

        // Agregar más campos de modelo dinámicamente
        document.getElementById('agregarModelo').addEventListener('click', function() {
            var container = document.getElementById('modelos-container');
            var input = document.createElement('input');
            input.type = 'text';
            input.name = 'modelos[]';
            input.placeholder = 'Modelo ' + (container.children.length + 1);
            container.appendChild(input);
        });

        // Eliminar el último campo de modelo agregado
        document.getElementById('eliminarModelo').addEventListener('click', function() {
            var container = document.getElementById('modelos-container');
            if (container.children.length > 1) { // Asegurarse de que al menos quede un campo
                container.removeChild(container.lastElementChild);
            }
        });
    </script>
</body>
</html>