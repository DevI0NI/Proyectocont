<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
    
}
date_default_timezone_set('America/Guatemala');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../config/database.php';

// En la sección de obtención de datos del contenedor
if (isset($_GET['nombre'])) {
    $nombreContenedor = $_GET['nombre'];
    $stmt = $pdo->prepare('
        SELECT 
            c.*,
            t.id as tipo_unidad_id,
            t.nombre as tipo_unidad_nombre,
            m.id as modelo_id,
            m.nombre as modelo_nombre,
            b.id as bloque_id,
            b.numero as bloque_numero
        FROM contenedores c
        LEFT JOIN tipos_unidad t ON c.tipo_unidad_id = t.id
        LEFT JOIN modelos m ON c.modelo_id = m.id
        LEFT JOIN bloques b ON c.bloque_id = b.id
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
}
// AGREGA ESTO:
if (!empty($contenedor['tipo_unidad_id'])) {
    $stmtModelos = $pdo->prepare('SELECT id, nombre FROM modelos WHERE tipo_unidad_id = :tipo_unidad_id');
    $stmtModelos->execute(['tipo_unidad_id' => $contenedor['tipo_unidad_id']]);
    $modelos = $stmtModelos->fetchAll();
} else {
    $modelos = [];
}
// Obtener los datos para los selects
$stmtTiposUnidad = $pdo->query('SELECT id, nombre FROM tipos_unidad');
$tiposUnidad = $stmtTiposUnidad->fetchAll();

$stmtEstadosUnidad = $pdo->query('SELECT id, nombre FROM estatus_contenedores WHERE tipo = "Unidad"');
$estadosUnidad = $stmtEstadosUnidad->fetchAll();

$stmtStatusBox = $pdo->query('SELECT id, nombre FROM estatus_contenedores WHERE tipo = "Estructura"');
$statusBox = $stmtStatusBox->fetchAll();

$stmtPatios = $pdo->query('SELECT id, nombre FROM patios');
$patios = $stmtPatios->fetchAll();

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nuevoNombre = $_POST['codigo_contenedor'];
    $tipoUnidad = $_POST['tipo_unidad'];
    $modelo = $_POST['modelo'];
    $estadoUnidad = $_POST['estado_unidad'];
    $observacionesEstado = $_POST['observaciones_estado'];
    $statusBoxId = $_POST['status_box'];
    $observacionesBox = $_POST['observaciones_box'];
    $bloque = $_POST['bloque'];
    $posicion = $_POST['posicion'];

    try {
        // Verificar duplicidad solo si el nombre ha cambiado
        if ($nuevoNombre !== $nombreContenedor) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE nombre = :nombre AND nombre != :nombre_actual');
            $stmt->execute([
                'nombre' => $nuevoNombre,
                'nombre_actual' => $nombreContenedor
            ]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['toast_message'] = 'Ya existe un contenedor con ese nombre';
                $_SESSION['toast_type'] = 'error';
                header("Location: modificar_contenedor.php?nombre=$nombreContenedor");
                exit;
            }
        }

        // Obtener el nombre del Status de Box
        $stmt = $pdo->prepare('SELECT nombre FROM estatus_contenedores WHERE id = :id');
        $stmt->execute(['id' => $statusBoxId]);
        $nombreStatusBox = $stmt->fetchColumn();

        // Calcular el estatus_ipsa
        if ($estadoUnidad === 'Pendiente PTI' || $nombreStatusBox === 'Pendiente Revisión') {
            $estatusIpsa = 'Pendiente';
        } elseif ($estadoUnidad === 'DMG' || $nombreStatusBox === 'DMG') {
            $estatusIpsa = 'OOS';
        } elseif ($estadoUnidad === 'OK' && ($nombreStatusBox === 'OK' || $nombreStatusBox === 'OK - Reparación Mínima')) {
            $estatusIpsa = 'Disponible';
        } else {
            $estatusIpsa = 'Pendiente';
        }

        // Actualizar el contenedor
        $stmt = $pdo->prepare('
            UPDATE contenedores SET 
                nombre = :nombre,
                bloque_id = :bloque_id,
                posicion = :posicion,
                estatus_unidad = :estatus_unidad,
                observaciones_unidad = :observaciones_unidad,
                estatus_box = :estatus_box,
                observaciones_box = :observaciones_box,
                tipo_unidad_id = :tipo_unidad_id,
                modelo_id = :modelo_id,
                estatus_ipsa = :estatus_ipsa
            WHERE nombre = :nombre_actual
        ');

        $stmt->execute([
            'nombre' => $nuevoNombre,
            'bloque_id' => $bloque,
            'posicion' => $posicion,
            'estatus_unidad' => $estadoUnidad,
            'observaciones_unidad' => $observacionesEstado,
            'estatus_box' => $nombreStatusBox,
            'observaciones_box' => $observacionesBox,
            'tipo_unidad_id' => $tipoUnidad,
            'modelo_id' => $modelo,
            'estatus_ipsa' => $estatusIpsa,
            'nombre_actual' => $nombreContenedor
        ]);

        $_SESSION['toast_message'] = 'Contenedor actualizado exitosamente';
        $_SESSION['toast_type'] = 'success';
        header('Location: bloques.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['toast_message'] = 'Error al actualizar el contenedor: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header("Location: modificar_contenedor.php?nombre=$nombreContenedor");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modificar Contenedor</title>
    <link rel="stylesheet" href="../styles/parametros/bloques/modificar_contenedor.css">
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
    <h1>Modificar Contenedor</h1>
    <form action="" method="post">
        <div class="form-group">
            <label for="codigo_contenedor">Código del Contenedor:</label>
            <input type="text" id="codigo_contenedor" name="codigo_contenedor" 
                   value="<?php echo htmlspecialchars($contenedor['nombre']); ?>" required>
            <span class="error-message" id="contenedor-error">Este contenedor ya existe.</span>
            <span class="valid-message" id="contenedor-valid">Nombre de contenedor disponible.</span>
        </div>

        <div class="form-group">
            <label for="tipo_unidad">Tipo de Unidad:</label>
            <select id="tipo_unidad" name="tipo_unidad" required>
                <option value="">Seleccione un tipo</option>
                <?php foreach ($tiposUnidad as $tipo): ?>
                    <option value="<?php echo $tipo['id']; ?>" 
                            <?php echo ($tipo['id'] == $contenedor['tipo_unidad_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($tipo['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="modelo">Modelo:</label>
           <select id="modelo" name="modelo" required>
    <option value="">Seleccione un modelo</option>
    <?php foreach ($modelos as $modelo): ?>
        <option value="<?php echo $modelo['id']; ?>"
            <?php echo ($modelo['id'] == $contenedor['modelo_id']) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($modelo['nombre']); ?>
        </option>
    <?php endforeach; ?>
</select>
        </div>

        <div class="form-group">
            <label for="estado_unidad">Estado de la Unidad:</label>
            <select id="estado_unidad" name="estado_unidad" required>
                <option value="">Seleccione un estado</option>
                <?php foreach ($estadosUnidad as $estado): ?>
                    <option value="<?php echo $estado['nombre']; ?>"
                            <?php echo ($estado['nombre'] == $contenedor['estatus_unidad']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($estado['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="observaciones_estado">Observaciones del Estado:</label>
            <textarea id="observaciones_estado" name="observaciones_estado"><?php echo htmlspecialchars($contenedor['observaciones_unidad']); ?></textarea>
        </div>

        <div class="form-group">
            <label for="status_box">Status Box:</label>
            <select id="status_box" name="status_box" required>
                <option value="">Seleccione un estado</option>
                <?php foreach ($statusBox as $status): ?>
                    <option value="<?php echo $status['id']; ?>"
                            <?php echo ($status['nombre'] == $contenedor['estatus_box']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($status['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="observaciones_box">Observaciones Box:</label>
            <textarea id="observaciones_box" name="observaciones_box"><?php echo htmlspecialchars($contenedor['observaciones_box']); ?></textarea>
        </div>

        <!-- Campo oculto para mantener la ubicación actual -->
        <input type="hidden" name="bloque" value="<?php echo $contenedor['bloque_id']; ?>">
        <input type="hidden" name="posicion" value="<?php echo $contenedor['posicion']; ?>">

        <div class="buttons">
            <a href="bloques.php" class="regresar-button">
                <img src="../imagenes/regresar.png" alt="Regresar">
                <span>Regresar</span>
            </a>
            <button type="submit" class="guardar-button">
                <img src="../imagenes/Save.png" alt="Guardar">
                <span>Guardar</span>
            </button>
        </div>
    </form>
</main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>

    <script>
    // En el <script> de modificar_contenedor.php
document.getElementById('codigo_contenedor').addEventListener('input', function() {
    const nombre = this.value;
    const nombreOriginal = '<?php echo $nombreContenedor; ?>';
    
    if (nombre === nombreOriginal) {
        document.getElementById('codigo_contenedor').classList.remove('error', 'valid');
        document.getElementById('contenedor-error').style.display = 'none';
        document.getElementById('contenedor-valid').style.display = 'none';
        return;
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'verificar_contenedor_mod.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            if (xhr.responseText === 'exists') {
                document.getElementById('codigo_contenedor').classList.add('error');
                document.getElementById('codigo_contenedor').classList.remove('valid');
                document.getElementById('contenedor-error').style.display = 'inline';
                document.getElementById('contenedor-valid').style.display = 'none';
            } else {
                document.getElementById('codigo_contenedor').classList.add('valid');
                document.getElementById('codigo_contenedor').classList.remove('error');
                document.getElementById('contenedor-error').style.display = 'none';
                document.getElementById('contenedor-valid').style.display = 'inline';
                setTimeout(function() {
                    document.getElementById('contenedor-valid').classList.add('fade-out');
                }, 2000);
                setTimeout(function() {
                    document.getElementById('contenedor-valid').style.display = 'none';
                    document.getElementById('contenedor-valid').classList.remove('fade-out');
                }, 3000);
            }
        }
    };
    xhr.send('nombre=' + encodeURIComponent(nombre) + '&nombre_original=' + encodeURIComponent(nombreOriginal));
});

    // Cargar modelos al cambiar tipo de unidad
    document.getElementById('tipo_unidad').addEventListener('change', function() {
        const tipoUnidadId = this.value;
        const modeloSelect = document.getElementById('modelo');
        
        if (!tipoUnidadId) {
            modeloSelect.innerHTML = '<option value="">Seleccione un modelo</option>';
            return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('GET', `obtener_modelos.php?tipo_unidad_id=${tipoUnidadId}`, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                const modelos = JSON.parse(xhr.responseText);
                let options = '<option value="">Seleccione un modelo</option>';
                modelos.forEach(modelo => {
                    const selected = modelo.id == <?php echo $contenedor['modelo_id'] ?? 'null'; ?> ? 'selected' : '';
                    options += `<option value="${modelo.id}" ${selected}>${modelo.nombre}</option>`;
                });
                modeloSelect.innerHTML = options;
            }
        };
        xhr.send();
    });

    // Disparar el evento change para cargar los modelos iniciales
    document.getElementById('tipo_unidad').dispatchEvent(new Event('change'));
</script>
</body>
</html>