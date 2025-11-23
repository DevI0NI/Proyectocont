<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Habilitar errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

// --- Helpers ---
function toast($msg, $type='success'){
    $_SESSION['toast_message'] = $msg;
    $_SESSION['toast_type']    = $type;
}
function back_to_self($id){
    header('Location: editarunidad.php?id='.urlencode($id));
    exit;
}

// 1. Obtener ID y Cargar Datos
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Cargar Tipo de Unidad (Solo Activos)
// Si intentan editar uno borrado, los mandamos fuera.
$stmt = $pdo->prepare('SELECT * FROM tipos_unidad WHERE id = :id AND estado_id = 1');
$stmt->execute(['id' => $id]);
$tipoUnidad = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tipoUnidad) {
    // Usamos variables de sesión directas para el toast si la función helper no está disponible en el scope global de include
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'El tipo de unidad no existe o está inactivo.'];
    header('Location: tipos_unidad.php'); 
    exit;
}

// Cargar Modelos Asociados (Solo Activos)
// Esto asegura que la lista cargue lo que se ve en el sistema, ignorando lo "borrado"
$stmtModelos = $pdo->prepare('SELECT id, nombre FROM modelos WHERE tipo_unidad_id = :id AND estado_id = 1 ORDER BY nombre');
$stmtModelos->execute(['id' => $id]);
$modelosDB = $stmtModelos->fetchAll(PDO::FETCH_ASSOC);

// --- Procesar Formulario (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    // Arrays paralelos del formulario
    $modelos_ids     = $_POST['modelos_ids'] ?? [];     // IDs (0 para nuevos)
    $modelos_nombres = $_POST['modelos_nombres'] ?? []; // Nombres

    try {
        if ($nombre === '') throw new Exception('El nombre no puede estar vacío.');

        $pdo->beginTransaction();

        // 1. Validar Duplicado de Nombre (Excluyendo el actual)
        // Buscamos en todo el historial para ser estrictos
        $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM tipos_unidad WHERE nombre = :nombre AND id != :id');
        $stmtCheck->execute(['nombre' => $nombre, 'id' => $id]);
        if ($stmtCheck->fetchColumn() > 0) {
            throw new Exception('El nombre "' . htmlspecialchars($nombre) . '" ya está en uso por otro registro.');
        }

        // 2. Actualizar Tipo de Unidad
        $stmtUpdate = $pdo->prepare('UPDATE tipos_unidad SET nombre = :nombre, descripcion = :desc WHERE id = :id');
        $stmtUpdate->execute(['nombre' => $nombre, 'desc' => $observaciones, 'id' => $id]);

        // 3. Sincronizar Modelos
        // a) Identificar qué IDs existían en la BD para detectar borrados
        $idsEnBD = array_column($modelosDB, 'id');
        $idsRecibidos = [];

        // b) Procesar la lista del formulario
        foreach ($modelos_nombres as $index => $mNombre) {
            $mNombre = trim($mNombre);
            if ($mNombre === '') continue; // Ignorar vacíos

            $mID = isset($modelos_ids[$index]) ? (int)$modelos_ids[$index] : 0;

            if ($mID > 0) {
                // Modelo Existente: Actualizar Nombre
                $stmtUpdMod = $pdo->prepare('UPDATE modelos SET nombre = :nombre WHERE id = :id');
                $stmtUpdMod->execute(['nombre' => $mNombre, 'id' => $mID]);
                $idsRecibidos[] = $mID;
            } else {
                // Modelo Nuevo: Insertar (estado_id = 1)
                $stmtInsMod = $pdo->prepare('INSERT INTO modelos (nombre, tipo_unidad_id, estado_id) VALUES (:nombre, :tid, 1)');
                $stmtInsMod->execute(['nombre' => $mNombre, 'tid' => $id]);
            }
        }

        // c) Soft Delete para los que faltan
        // Si un ID estaba en la BD pero no vino en el formulario, significa que el usuario lo quitó.
        // Lo pasamos a estado_id = 2 en lugar de borrarlo.
        $idsParaBorrar = array_diff($idsEnBD, $idsRecibidos);
        
        if (!empty($idsParaBorrar)) {
            $stmtSoftDelete = $pdo->prepare('UPDATE modelos SET estado_id = 2 WHERE id = :id');
            foreach ($idsParaBorrar as $delID) {
                $stmtSoftDelete->execute(['id' => $delID]);
            }
        }

        $pdo->commit();
        
        // Éxito
        $_SESSION['toast'] = ['type' => 'success', 'text' => 'Cambios guardados correctamente.'];
        header('Location: tipos_unidad.php');
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Tipo de Unidad</title>
    <link rel="stylesheet" href="../../styles/parametros/tiposunidad/nuevaunidad.css">
    <style>
        .error { border: 2px solid red; }
        .valid { border: 2px solid green; }
        .error-message { color: red; display: none; margin-top:5px; }
        .valid-message { color: green; display: none; margin-top:5px; }
        
        /* Estilos simples para alinear los inputs de modelos */
        .modelo-row { display: flex; gap: 10px; margin-bottom: 10px; align-items: center; }
        .modelo-row input { flex: 1; }
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

<main>
    <h1>Modificar Tipo de Unidad</h1>

    <?php if (isset($error_message)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center;">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <form id="tipoUnidadForm" action="editarunidad.php?id=<?php echo $id; ?>" method="post">
        <div class="form-group">
            <label for="nombre">Nombre:</label>
            <input type="text" id="nombre" name="nombre" 
                   value="<?php echo htmlspecialchars($tipoUnidad['nombre']); ?>" 
                   required pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ0-9\s]+" 
                   title="Solo letras, números y espacios.">
            <span class="error-message" id="nombre-error">Este nombre ya está en uso.</span>
            <span class="valid-message" id="nombre-valid">Nombre disponible.</span>
        </div>

        <div class="form-group">
            <label for="observaciones">Observaciones:</label>
            <textarea id="observaciones" name="observaciones"><?php echo htmlspecialchars($tipoUnidad['descripcion']); ?></textarea>
        </div>

        <div class="form-group">
            <label>Modelos:</label>
            <div id="modelos-container">
                <?php foreach ($modelosDB as $modelo): ?>
                    <div class="modelo-row">
                        <!-- Input oculto con el ID real para identificarlo al guardar -->
                        <input type="hidden" name="modelos_ids[]" value="<?php echo $modelo['id']; ?>">
                        <input type="text" name="modelos_nombres[]" value="<?php echo htmlspecialchars($modelo['nombre']); ?>">
                    </div>
                <?php endforeach; ?>
                
                <!-- Si no hay modelos, mostramos uno vacío por defecto -->
                <?php if (empty($modelosDB)): ?>
                    <div class="modelo-row">
                        <input type="hidden" name="modelos_ids[]" value="0">
                        <input type="text" name="modelos_nombres[]" placeholder="Nuevo Modelo">
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <!-- BOTONES ORIGINALES RESTAURADOS -->
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

<!-- Toasts (Mismo script que ya usas) -->
<div id="toast-container" aria-live="polite" aria-atomic="true"></div>
<script>
  // ... (Aquí iría tu función showToast si la tienes global, o la incluimos abajo) ...
  function showToast(message, type = 'success', duration = 4200){
    const container = document.getElementById('toast-container') || (() => {
      const c = document.createElement('div'); c.id = 'toast-container'; document.body.appendChild(c); return c;
    })();
    const el = document.createElement('div');
    el.className = `phone-toast ${type}`; // Asegúrate de tener CSS para .phone-toast o .toast
    // ... (resto de lógica de toast visual) ...
    // Simplificado para no alargar, asumo que tienes el CSS/JS de toast cargado
  }
</script>

<script>
    // VALIDACIÓN AJAX
    // Nombre original para evitar validar si no cambia
    const nombreOriginal = "<?php echo addslashes($tipoUnidad['nombre']); ?>";
    
    const nombreInput = document.getElementById('nombre');
    const errorSpan = document.getElementById('nombre-error');
    const validSpan = document.getElementById('nombre-valid');

    nombreInput.addEventListener('input', function() {
        const nombre = this.value.trim();
        
        // Limpiar estados
        this.classList.remove('error', 'valid');
        errorSpan.style.display = 'none';
        validSpan.style.display = 'none';

        if (nombre.length === 0) return;
        
        // Si el nombre es igual al original, es válido y no consultamos
        if (nombre === nombreOriginal) return;

        const xhr = new XMLHttpRequest();
        // Usamos verificar_nombre.php
        xhr.open('POST', 'verificar_nombre.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                if (xhr.responseText === 'exists') {
                    nombreInput.classList.add('error');
                    errorSpan.style.display = 'inline';
                } else {
                    nombreInput.classList.add('valid');
                    validSpan.style.display = 'inline';
                    setTimeout(() => validSpan.style.display = 'none', 3000);
                }
            }
        };
        xhr.send('nombre=' + encodeURIComponent(nombre));
    });

    // AGREGAR MODELO
    document.getElementById('agregarModelo').addEventListener('click', () => {
        const container = document.getElementById('modelos-container');
        const div = document.createElement('div');
        div.className = 'modelo-row';
        // ID 0 indica que es nuevo para el backend
        div.innerHTML = `
            <input type="hidden" name="modelos_ids[]" value="0">
            <input type="text" name="modelos_nombres[]" placeholder="Nuevo Modelo">
        `;
        container.appendChild(div);
        div.querySelector('input[type="text"]').focus();
    });

    // QUITAR ÚLTIMO MODELO (Visualmente, el backend hará soft delete si tenía ID)
    document.getElementById('eliminarModelo').addEventListener('click', () => {
        const container = document.getElementById('modelos-container');
        // Permitimos vaciar si así lo desea el usuario, o puedes poner > 1
        if (container.children.length > 0) {
            container.removeChild(container.lastElementChild);
        }
    });
</script>
</body>
</html>