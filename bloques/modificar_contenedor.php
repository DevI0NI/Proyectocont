<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Contexto de Sucursal (Seguridad)
if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../../index.php');
    exit;
}
$sucursal_id = $_SESSION['sucursal_activa'];

date_default_timezone_set('America/Guatemala');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../config/database.php';

// =======================================================
// 1. CARGAR DATOS DEL CONTENEDOR
// =======================================================
$contenedor = null;
$modelos = [];

if (isset($_GET['nombre'])) {
    $nombreContenedor = $_GET['nombre'];
    
    // Seleccionamos los IDs de los estatus actuales para pre-seleccionarlos en el HTML
    $stmt = $pdo->prepare('
        SELECT 
            c.*,
            t.nombre as tipo_unidad_nombre,
            m.nombre as modelo_nombre,
            b.numero as bloque_numero
        FROM contenedores c
        LEFT JOIN tipos_unidad t ON c.tipo_unidad_id = t.id
        LEFT JOIN modelos m ON c.modelo_id = m.id
        LEFT JOIN bloques b ON c.bloque_id = b.id
        LEFT JOIN patios p ON b.patio_id = p.id
        WHERE c.nombre = :nombre 
          AND p.sucursal_id = :sucursal -- Seguridad: Solo de esta sucursal
          AND c.estado_id = 1
    ');
    $stmt->execute(['nombre' => $nombreContenedor, 'sucursal' => $sucursal_id]);
    $contenedor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contenedor) {
        $_SESSION['toast_message'] = 'Contenedor no encontrado.';
        $_SESSION['toast_type'] = 'error';
        header('Location: bloques.php');
        exit;
    }

    // Cargar modelos del tipo actual
    if (!empty($contenedor['tipo_unidad_id'])) {
        $stmtModelos = $pdo->prepare('SELECT id, nombre FROM modelos WHERE tipo_unidad_id = :id AND estado_id = 1');
        $stmtModelos->execute(['id' => $contenedor['tipo_unidad_id']]);
        $modelos = $stmtModelos->fetchAll();
    }
}

// =======================================================
// 2. CARGAR CATALOGOS (Para los Selects)
// =======================================================

// Tipos de Unidad
$stmtTiposUnidad = $pdo->query('SELECT id, nombre FROM tipos_unidad WHERE estado_id = 1 ORDER BY nombre');
$tiposUnidad = $stmtTiposUnidad->fetchAll();

// Buscar IDs de tipos de estatus (Unidad vs Estructura)
$stmtCat = $pdo->prepare('SELECT id FROM tipos_estatus WHERE nombre = :nombre');

// Estatus Unidad
$stmtCat->execute(['nombre' => 'Unidad']);
$idCatUnidad = $stmtCat->fetchColumn();
$stmtEstadosUnidad = $pdo->prepare('SELECT id, nombre FROM estatus_contenedores WHERE tipo_estatus_id = :id AND estado_id = 1 ORDER BY nombre');
$stmtEstadosUnidad->execute(['id' => $idCatUnidad]);
$estadosUnidad = $stmtEstadosUnidad->fetchAll();

// Estatus Box (Estructura)
$stmtCat->execute(['nombre' => 'Estructura']);
$idCatBox = $stmtCat->fetchColumn();
$stmtStatusBox = $pdo->prepare('SELECT id, nombre FROM estatus_contenedores WHERE tipo_estatus_id = :id AND estado_id = 1 ORDER BY nombre');
$stmtStatusBox->execute(['id' => $idCatBox]);
$statusBox = $stmtStatusBox->fetchAll();

// NUEVO: Estatus IPSA (Manual)
$stmtIpsa = $pdo->query('SELECT id, nombre FROM estatus_ipsa WHERE estado_id = 1 ORDER BY nombre');
$estatusIpsaList = $stmtIpsa->fetchAll();

// =======================================================
// 3. PROCESAR FORMULARIO
// =======================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nuevoNombre         = trim(strtoupper($_POST['codigo_contenedor']));
    $tipoUnidad          = (int)$_POST['tipo_unidad'];
    $modelo              = (int)$_POST['modelo'];
    $estadoUnidadId      = (int)$_POST['estado_unidad']; 
    $observacionesEstado = $_POST['observaciones_estado'];
    $statusBoxId         = (int)$_POST['status_box'];
    $observacionesBox    = $_POST['observaciones_box'];
    $estatusIpsaId       = (int)$_POST['estatus_ipsa']; // Valor manual
    
    // Mantenemos ubicación actual
    $bloqueId            = (int)$_POST['bloque'];
    $posicion            = (int)$_POST['posicion'];

    $idContenedor = $contenedor['id'];

    try {
        $pdo->beginTransaction();

        // Verificar duplicidad (si cambió el nombre)
        if ($nuevoNombre !== $nombreContenedor) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE nombre = :nombre AND id != :id');
            $stmt->execute(['nombre' => $nuevoNombre, 'id' => $idContenedor]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('Ya existe otro contenedor con ese número.');
            }
        }

        // Actualizar (Usando los nombres de columna CORRECTOS de la nueva BD: _id)
        $stmt = $pdo->prepare('
            UPDATE contenedores SET 
                nombre = :nombre,
                tipo_unidad_id = :tipo_id,
                modelo_id = :modelo_id,
                estatus_unidad_id = :e_unidad,
                observaciones_unidad = :obs_unidad,
                estatus_box_id = :e_box,
                observaciones_box = :obs_box,
                estatus_ipsa_id = :e_ipsa
            WHERE id = :id
        ');

        $stmt->execute([
            'nombre'      => $nuevoNombre,
            'tipo_id'     => $tipoUnidad,
            'modelo_id'   => $modelo,
            'e_unidad'    => $estadoUnidadId,
            'obs_unidad'  => $observacionesEstado,
            'e_box'       => $statusBoxId,
            'obs_box'     => $observacionesBox,
            'e_ipsa'      => $estatusIpsaId, // Se guarda lo que el usuario eligió
            'id'          => $idContenedor
        ]);

        $pdo->commit();

        $_SESSION['toast_message'] = 'Contenedor actualizado exitosamente';
        $_SESSION['toast_type'] = 'success';
        header('Location: bloques.php');
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['toast_message'] = 'Error: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header("Location: modificar_contenedor.php?nombre=" . urlencode($nombreContenedor));
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
            <span>Bienvenido, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
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
            <span class="valid-message" id="contenedor-valid">Nombre disponible.</span>
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
                    <option value="<?php echo $estado['id']; ?>"
                        <?php echo ($estado['id'] == $contenedor['estatus_unidad_id']) ? 'selected' : ''; ?>>
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
                        <?php echo ($status['id'] == $contenedor['estatus_box_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($status['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="observaciones_box">Observaciones Box:</label>
            <textarea id="observaciones_box" name="observaciones_box"><?php echo htmlspecialchars($contenedor['observaciones_box']); ?></textarea>
        </div>
        
        <!-- NUEVO: Campo Manual Estatus IPSA -->
        <div class="form-group" style="border: 2px solid #00695C; padding: 10px; border-radius: 5px; background-color: #f9f9f9;">
            <label for="estatus_ipsa" style="color: #00695C;"><strong>Estatus IPSA (Manual):</strong></label>
            <select id="estatus_ipsa" name="estatus_ipsa" required>
                <option value="">Seleccione estatus final</option>
                <?php foreach ($estatusIpsaList as $ipsa): ?>
                    <option value="<?php echo $ipsa['id']; ?>"
                        <?php echo ($ipsa['id'] == $contenedor['estatus_ipsa_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ipsa['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Campos ocultos -->
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
    // Validación AJAX de nombre
    document.getElementById('codigo_contenedor').addEventListener('input', function() {
        const nombre = this.value.toUpperCase();
        const nombreOriginal = '<?php echo $nombreContenedor; ?>';
        
        if (nombre === nombreOriginal) {
            document.getElementById('codigo_contenedor').classList.remove('error', 'valid');
            document.getElementById('contenedor-error').style.display = 'none';
            document.getElementById('contenedor-valid').style.display = 'none';
            return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '../ingresos/verificar_contenedor.php', true); // Reusa el verificador de ingresos
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                if (xhr.responseText === 'exists') {
                    document.getElementById('codigo_contenedor').classList.add('error');
                    document.getElementById('contenedor-error').style.display = 'inline';
                } else {
                    document.getElementById('codigo_contenedor').classList.add('valid');
                    const validMsg = document.getElementById('contenedor-valid');
                    validMsg.style.display = 'inline';
                    
                    // Efecto fade
                    validMsg.classList.remove('fade-out');
                    setTimeout(() => validMsg.classList.add('fade-out'), 2000);
                    setTimeout(() => validMsg.style.display = 'none', 3000);
                }
            }
        };
        xhr.send('nombre=' + encodeURIComponent(nombre));
    });

    // Carga dinámica de modelos
    document.getElementById('tipo_unidad').addEventListener('change', function() {
        const tipoUnidadId = this.value;
        const modeloSelect = document.getElementById('modelo');
        
        // Intentamos mantener el modelo seleccionado si es compatible
        const modeloActual = <?php echo $contenedor['modelo_id'] ?? '0'; ?>;

        if (!tipoUnidadId) {
            modeloSelect.innerHTML = '<option value="">Seleccione un modelo</option>';
            return;
        }

        // Ruta a la API de modelos (asumiendo que está en ../ingresos/)
        const xhr = new XMLHttpRequest();
        xhr.open('GET', `../ingresos/obtener_modelos.php?tipo_unidad_id=${tipoUnidadId}`, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                const modelos = JSON.parse(xhr.responseText);
                let options = '<option value="">Seleccione un modelo</option>';
                modelos.forEach(modelo => {
                    const selected = (modelo.id == modeloActual) ? 'selected' : '';
                    options += `<option value="${modelo.id}" ${selected}>${modelo.nombre}</option>`;
                });
                modeloSelect.innerHTML = options;
            }
        };
        xhr.send();
    });

    // Disparar evento inicial si no hay modelo cargado en HTML (fallback)
    // Pero como PHP ya pre-cargó el select de modelos, esto es solo por seguridad si cambia el tipo.
    </script>
</body>
</html>