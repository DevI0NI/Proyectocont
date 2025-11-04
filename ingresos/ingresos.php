<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}
date_default_timezone_set('America/Guatemala');
// Habilitar la visualización de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../config/database.php';

$error_message = '';

// Obtener los datos necesarios para los campos desplegables
$stmtTiposUnidad = $pdo->query('SELECT id, nombre FROM tipos_unidad');
$tiposUnidad = $stmtTiposUnidad->fetchAll();

$stmtEstadosUnidad = $pdo->query('SELECT id, nombre FROM estatus_contenedores WHERE tipo = "Unidad"');
$estadosUnidad = $stmtEstadosUnidad->fetchAll();

$stmtStatusBox = $pdo->query('SELECT id, nombre FROM estatus_contenedores WHERE tipo = "Estructura"');
$statusBox = $stmtStatusBox->fetchAll();

$stmtPatios = $pdo->query('SELECT id, nombre FROM patios');
$patios = $stmtPatios->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fechaIngreso = $_POST['fecha_ingreso'] . ' ' . date('H:i:s');
    $codigoContenedor = $_POST['codigo_contenedor'];
    $tipoUnidad = $_POST['tipo_unidad'];
    $modelo = $_POST['modelo'];
    $estadoUnidad = $_POST['estado_unidad'];
    $observacionesEstado = $_POST['observaciones_estado'];
    $statusBoxId = $_POST['status_box'];
    $observacionesBox = $_POST['observaciones_box'];
    $bloque = $_POST['bloque'];
    $posicion = $_POST['posicion'];

    try {
        // Verificar si el contenedor ya existe
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE nombre = :nombre');
        $stmt->execute(['nombre' => $codigoContenedor]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            $_SESSION['toast_message'] = 'El contenedor ya existe. Por favor, ingrese un nombre diferente.';
            $_SESSION['toast_type'] = 'error';
            header('Location: ingresos.php');
            exit;
        }

        // Obtener el nombre del Status de Box a partir de su ID
        $stmt = $pdo->prepare('SELECT nombre FROM estatus_contenedores WHERE id = :id');
        $stmt->execute(['id' => $statusBoxId]);
        $nombreStatusBox = $stmt->fetchColumn();

        // Calcular el estatus_ipsa
        if ($estadoUnidad === 'Pendiente PTI' || $nombreStatusBox === 'Pendiente Revisión') {
            $estatusIpsa = 'Pendiente';
        } elseif ($estadoUnidad === 'DMG' || $nombreStatusBox === 'DMG') {
            $estatusIpsa = 'Dañado';
        } elseif ($estadoUnidad === 'OK' && ($nombreStatusBox === 'OK' || $nombreStatusBox === 'OK - Reparación Mínima')) {
            $estatusIpsa = 'Disponible';
        } else {
            $estatusIpsa = 'Pendiente'; // Valor por defecto
        }

        // Insertar los datos en la tabla 'contenedores'
        $stmt = $pdo->prepare('
            INSERT INTO contenedores (nombre, bloque_id, posicion, estatus_unidad, observaciones_unidad, estatus_box, observaciones_box, tipo_unidad_id, modelo_id, fecha_ingreso, estatus_ipsa)
            VALUES (:nombre, :bloque_id, :posicion, :estatus_unidad, :observaciones_unidad, :estatus_box, :observaciones_box, :tipo_unidad_id, :modelo_id, :fecha_ingreso, :estatus_ipsa)
        ');
        $stmt->execute([
            'nombre' => $codigoContenedor,
            'bloque_id' => $bloque,
            'posicion' => $posicion,
            'estatus_unidad' => $estadoUnidad,
            'observaciones_unidad' => $observacionesEstado,
            'estatus_box' => $nombreStatusBox,
            'observaciones_box' => $observacionesBox,
            'tipo_unidad_id' => $tipoUnidad,
            'modelo_id' => $modelo,
            'fecha_ingreso' => $fechaIngreso,
            'estatus_ipsa' => $estatusIpsa
        ]);

        // Actualizar la capacidad actual del bloque
        $stmt = $pdo->prepare('UPDATE bloques SET capacidad_actual = capacidad_actual + 1 WHERE id = :bloque_id');
        $stmt->execute(['bloque_id' => $bloque]);

        // Guardar mensaje de éxito en la sesión
        $_SESSION['toast_message'] = 'El contenedor se guardó exitosamente.';
        $_SESSION['toast_type'] = 'success';

        header('Location: ingresos.php');
        exit;
    } catch (Exception $e) {
        // Guardar mensaje de error en la sesión
        $_SESSION['toast_message'] = 'Error al guardar el contenedor: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header('Location: ingresos.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo Ingreso</title>
    <link rel="stylesheet" href="../styles/ingresos.css/ingresos.css">
</head>
<body>
<!-- Contenedor de toasts (si no existe, el JS lo creará igual) -->
<div id="toast-container" class="toast-container" aria-live="polite" aria-atomic="true"></div>

<header>
  <div class="header-left">
    <a href="../index.php">
      <img src="../imagenes/IPSA.png" alt="Logo" class="logo">
      <span class="brand-name">IPROPORTSA</span>
    </a>
  </div>
  <div class="header-right">
    <span class="welcome-label">Bienvenido,</span>
    <span class="username"><?php echo $_SESSION['username']; ?></span>
    <a href="../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>
<main>
    <h1>Nuevo Ingreso</h1>
    <?php if ($error_message): ?>
        <p style="color: red;"><?php echo $error_message; ?></p>
    <?php endif; ?>
    <form action="ingresos.php" method="post">
        <div class="form-group">
            <label for="fecha_ingreso">Fecha de Ingreso:</label>
            <input type="date" id="fecha_ingreso" name="fecha_ingreso" required>
        </div>
        <div class="form-group">
            <label for="codigo_contenedor">Número de Equipo:</label>
            <input type="text" id="codigo_contenedor" name="codigo_contenedor" required maxlength="11" pattern="^[A-Z0-9]{11}$" title="Debe tener exactamente 11 caracteres, solo mayúsculas y números">
            <span class="error-message" id="contenedor-error">Este contenedor ya existe.</span>
            <span class="valid-message" id="contenedor-valid">Contenedor disponible.</span>
        </div>
        <div class="form-group">
            <label for="tipo_unidad">Tipo de Unidad:</label>
            <select id="tipo_unidad" name="tipo_unidad" required>
                <option value="">Seleccione un tipo</option>
                <?php foreach ($tiposUnidad as $tipo): ?>
                    <option value="<?php echo $tipo['id']; ?>"><?php echo htmlspecialchars($tipo['nombre']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="modelo">Modelo:</label>
            <select id="modelo" name="modelo" required>
                <option value="">Seleccione un modelo</option>
            </select>
        </div>
        <div class="form-group">
            <label for="estado_unidad">Estado de Unidad:</label>
            <select id="estado_unidad" name="estado_unidad" required>
                <option value="">Seleccione un estado</option>
                <?php foreach ($estadosUnidad as $estado): ?>
                    <option value="<?php echo $estado['nombre']; ?>"><?php echo htmlspecialchars($estado['nombre']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="observaciones_estado">Observaciones (Unidad):</label>
            <textarea id="observaciones_estado" name="observaciones_estado"></textarea>
        </div>
        <div class="form-group">
            <label for="status_box">Status de Box:</label>
            <select id="status_box" name="status_box" required>
                <option value="">Seleccione un status</option>
                <?php foreach ($statusBox as $status): ?>
                    <option value="<?php echo $status['id']; ?>"><?php echo htmlspecialchars($status['nombre']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="observaciones_box">Observaciones (Box):</label>
            <textarea id="observaciones_box" name="observaciones_box"></textarea>
        </div>

        <!-- Sección de Ubicación -->
        <div class="ubicacion-container">
            <h2>Ubicación</h2>
            <div class="ubicacion-fields">
                <select id="patio" name="patio" required>
                    <option value="">Seleccione un patio</option>
                    <?php foreach ($patios as $patio): ?>
                        <option value="<?php echo $patio['id']; ?>"><?php echo htmlspecialchars($patio['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="bloque" name="bloque" required disabled>
                    <option value="">Seleccione un bloque</option>
                </select>
                <input type="text" id="posicion" name="posicion" placeholder="Posición" readonly>
            </div>
        </div>
        <div class="buttons">
            <a href="../index.php" class="regresar-button">
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
// Establecer la fecha actual en el campo de fecha al cargar la página
document.addEventListener('DOMContentLoaded', function () {
    const fechaInput = document.getElementById('fecha_ingreso');
    if (fechaInput) {
        const hoy = new Date();
        const yyyy = hoy.getFullYear();
        const mm = String(hoy.getMonth() + 1).padStart(2, '0');
        const dd = String(hoy.getDate()).padStart(2, '0');
        fechaInput.value = `${yyyy}-${mm}-${dd}`;
    }
});
</script>

<script>
// Carga de modelos por tipo
document.getElementById('tipo_unidad').addEventListener('change', function () {
    const tipoUnidadId = this.value;
    const modeloSelect = document.getElementById('modelo');

    modeloSelect.innerHTML = '<option value="">Seleccione un modelo</option>';

    if (tipoUnidadId) {
        fetch(`obtener_modelos.php?tipo_unidad_id=${tipoUnidadId}`)
            .then(response => response.json())
            .then(data => {
                data.forEach(modelo => {
                    const option = document.createElement('option');
                    option.value = modelo.id;
                    option.textContent = modelo.nombre;
                    modeloSelect.appendChild(option);
                });
            })
            .catch(error => console.error('Error al cargar los modelos:', error));
    }
});

// Cascada patio -> bloque -> posición
document.getElementById('patio').addEventListener('change', function () {
    const patioId = this.value;
    const bloqueSelect = document.getElementById('bloque');
    const posicionInput = document.getElementById('posicion');

    bloqueSelect.innerHTML = '<option value="">Seleccione un bloque</option>';
    bloqueSelect.disabled = true;
    posicionInput.value = '';

    if (patioId) {
        fetch(`obtener_bloques.php?patio_id=${patioId}`)
            .then(response => response.json())
            .then(data => {
                data.forEach(bloque => {
                    const option = document.createElement('option');
                    option.value = bloque.id;
                    option.textContent = `Bloque ${bloque.numero}`;
                    bloqueSelect.appendChild(option);
                });
                bloqueSelect.disabled = false;
            })
            .catch(error => console.error('Error al cargar los bloques:', error));
    }
});

document.getElementById('bloque').addEventListener('change', function () {
    const bloqueId = this.value;
    const posicionInput = document.getElementById('posicion');

    posicionInput.value = '';

    if (bloqueId) {
        fetch(`obtener_posicion.php?bloque_id=${bloqueId}`)
            .then(response => response.json())
            .then(data => {
                posicionInput.value = data.posicion;
            })
            .catch(error => console.error('Error al cargar la posición:', error));
    }
});

// ------- Notificaciones tipo "toast" (versión robusta) -------
(function(){
  function getToastContainer(){
    let c = document.getElementById('toast-container');
    if(!c){
      c = document.createElement('div');
      c.id = 'toast-container';
      c.className = 'toast-container';
      c.setAttribute('aria-live','polite');
      c.setAttribute('aria-atomic','true');
      document.body.appendChild(c);
    }
    return c;
  }

  window.showToast = function(message, type='success'){
    const container = getToastContainer();
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(()=> toast.remove(), 4000);
  };
})();
</script>

<?php if (isset($_SESSION['toast_message'])): ?>
<script>
// Mostrar notificación si hay un mensaje en la sesión (con strings seguros)
document.addEventListener('DOMContentLoaded', function () {
  showToast(
    <?php echo json_encode($_SESSION['toast_message']); ?>,
    <?php echo json_encode($_SESSION['toast_type'] ?? 'success'); ?>
  );
});
</script>
<?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
<?php endif; ?>

<script>
// Verificación de existencia (AJAX) y formato del código de contenedor
document.getElementById('codigo_contenedor').addEventListener('input', function () {
    const nombre = this.value;
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'verificar_contenedor.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
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
                setTimeout(function () {
                    document.getElementById('contenedor-valid').classList.add('fade-out');
                }, 2000);
                setTimeout(function () {
                    document.getElementById('contenedor-valid').style.display = 'none';
                    document.getElementById('contenedor-valid').classList.remove('fade-out');
                }, 3000);
            }
        }
    };
    xhr.send('nombre=' + encodeURIComponent(nombre));
});

// Validación de formato: solo mayúsculas y números, máximo 11 caracteres
document.getElementById('codigo_contenedor').addEventListener('input', function () {
    // Forzar mayúsculas automáticamente
    this.value = this.value.toUpperCase();

    // Validar formato
    const regex = /^[A-Z0-9]{0,11}$/;
    if (!regex.test(this.value)) {
        this.classList.add('error');
        document.getElementById('contenedor-error').textContent = 'Solo letras mayúsculas y números, máximo 11 caracteres.';
        document.getElementById('contenedor-error').style.display = 'inline';
        document.getElementById('contenedor-valid').style.display = 'none';
    } else if (this.value.length === 11) {
        // Aquí puedes dejar tu validación AJAX para existencia
    } else {
        this.classList.remove('error');
        document.getElementById('contenedor-error').style.display = 'none';
    }
});
</script>

</body>
</html>
