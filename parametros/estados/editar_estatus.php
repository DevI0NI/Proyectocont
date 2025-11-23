<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

$error_message = '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 1. Cargar datos
$stmt = $pdo->prepare('
    SELECT ec.*, te.nombre as nombre_tipo 
    FROM estatus_contenedores ec
    JOIN tipos_estatus te ON ec.tipo_estatus_id = te.id
    WHERE ec.id = :id AND ec.estado_id = 1
');
$stmt->execute(['id' => $id]);
$estatus = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$estatus) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'El estatus no existe o está inactivo.'];
    header('Location: estados.php');
    exit;
}

$stmtTipos = $pdo->query("SELECT id, nombre FROM tipos_estatus WHERE estado_id = 1");
$listaTipos = $stmtTipos->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tipoId = (int)$_POST['tipo_estatus_id'];
    $nombre = trim($_POST['nombre']);

    try {
        $pdo->beginTransaction();

        // 2. Validación de Duplicados
        $stmtCheck = $pdo->prepare('SELECT id FROM estatus_contenedores WHERE nombre = :nombre AND tipo_estatus_id = :tipo_id AND id != :id');
        $stmtCheck->execute(['nombre' => $nombre, 'tipo_id' => $tipoId, 'id' => $id]);
        
        if ($stmtCheck->fetchColumn()) {
            throw new Exception('Este nombre ya existe en el historial para esta categoría.');
        }

        // 3. Actualizar
        $stmtUpdate = $pdo->prepare('UPDATE estatus_contenedores SET nombre = :nombre, tipo_estatus_id = :tipo_id WHERE id = :id');
        $stmtUpdate->execute([
            'nombre' => $nombre,
            'tipo_id' => $tipoId,
            'id' => $id
        ]);

        $pdo->commit();

        $stmtNombreTipo = $pdo->prepare("SELECT nombre FROM tipos_estatus WHERE id = :id");
        $stmtNombreTipo->execute(['id' => $tipoId]);
        $nombreRedireccion = $stmtNombreTipo->fetchColumn();

        $_SESSION['toast'] = ['type' => 'success', 'text' => 'Estatus actualizado correctamente.'];
        header('Location: estados.php?tipo=' . urlencode($nombreRedireccion));
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
    <title>Editar Estatus</title>
    <link rel="stylesheet" href="../../styles/parametros/estados/nuevo_estatus.css">
    <style>
        .error { border: 2px solid red !important; }
        .valid { border: 2px solid green !important; }
        
        .error-message { color: red; display: none; font-size: 0.9em; margin-left: 10px; white-space: nowrap; }
        .valid-message { color: green; display: none; font-size: 0.9em; margin-left: 10px; white-space: nowrap; }
        .valid-message.fade-out { opacity: 0; transition: opacity 1s; }

        /* Estilos para Toast (copiados para asegurar que funcionen localmente si no cargan de externo) */
        #toast-container { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; }
        .phone-toast { padding: 15px 25px; border-radius: 50px; color: #fff; font-weight: bold; box-shadow: 0 5px 15px rgba(0,0,0,0.3); opacity: 0; transform: translateY(-20px); transition: all 0.3s ease; display: flex; align-items: center; gap: 10px; }
        .phone-toast.show { opacity: 1; transform: translateY(0); }
        .phone-toast.error { background-color: #d32f2f; }
        .phone-toast.success { background-color: #388e3c; }

        /* FLEXBOX PARA INPUT Y MENSAJE */
        .input-wrapper {
            display: flex;
            align-items: center;
            width: 100%;
        }
        .input-wrapper input {
            flex: 1; /* Toma todo el espacio disponible */
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
    <span class="username"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></span>
    <a href="../../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

    <main>
        <h1>Editar Estatus</h1>

        <form action="editar_estatus.php?id=<?php echo $id; ?>" method="post">
            <div class="form-group">
                <label for="tipo_estatus_id">Tipo de Estatus:</label>
                <select id="tipo_estatus_id" name="tipo_estatus_id" required>
                    <option value="">Seleccione un tipo</option>
                    <?php foreach ($listaTipos as $t): ?>
                        <?php 
                            $selected = '';
                            if (isset($_POST['tipo_estatus_id']) && $_POST['tipo_estatus_id'] == $t['id']) {
                                $selected = 'selected';
                            } elseif (!isset($_POST['tipo_estatus_id']) && $estatus['tipo_estatus_id'] == $t['id']) {
                                $selected = 'selected';
                            }
                        ?>
                        <option value="<?php echo $t['id']; ?>" <?php echo $selected; ?>>
                            <?php echo htmlspecialchars($t['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="nombre">Nombre del Estatus:</label>
                
                <!-- CONTENEDOR FLEX PARA ALINEAR INPUT Y MENSAJE -->
                <div class="input-wrapper">
                    <input type="text" id="nombre" name="nombre" 
                           value="<?php echo htmlspecialchars($_POST['nombre'] ?? $estatus['nombre']); ?>" 
                           required pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ0-9\s\-\.]+" 
                           title="Solo letras, números, espacios, guiones y puntos."
                           autocomplete="off">
                    
                    <span class="error-message" id="nombre-error">⚠️ Ya existe</span>
                    <span class="valid-message" id="nombre-valid">✅ Disponible</span>
                </div>
            </div>

            <div class="buttons">
                <a href="estados.php?tipo=<?php echo urlencode($estatus['nombre_tipo']); ?>" class="regresar-button">
                    <img src="../../imagenes/regresar.png" alt="Regresar">
                    <span>Regresar</span>
                </a>
                <button type="submit" class="guardar-button">
                    <img src="../../imagenes/Save.png" alt="Guardar">
                    <span>Guardar Cambios</span>
                </button>
            </div>
        </form>
    </main>

    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>

    <div id="toast-container"></div>

    <script>
        // Función Toast
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `phone-toast ${type}`;
            toast.innerHTML = `<span>${message}</span>`;
            container.appendChild(toast);
            
            // Forzar reflow
            void toast.offsetWidth; 
            toast.classList.add('show');

            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // Si hay error PHP, mostrarlo como Toast al cargar
        <?php if ($error_message): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showToast("<?php echo addslashes($error_message); ?>", 'error');
            });
        <?php endif; ?>

        // Lógica de Validación AJAX
        const nombreOriginal = "<?php echo addslashes($estatus['nombre']); ?>";
        const tipoOriginalId = "<?php echo $estatus['tipo_estatus_id']; ?>";
        const nombreInput = document.getElementById('nombre');
        const tipoSelect = document.getElementById('tipo_estatus_id');
        const errorSpan = document.getElementById('nombre-error');
        const validSpan = document.getElementById('nombre-valid');

        function validarNombre() {
            const nombre = nombreInput.value.trim();
            const tipoId = tipoSelect.value;
            const tipoTexto = tipoSelect.options[tipoSelect.selectedIndex]?.text || '';

            nombreInput.classList.remove('error', 'valid');
            errorSpan.style.display = 'none';
            validSpan.style.display = 'none';

            if (nombre.length === 0 || tipoId === "") return;
            if (nombre === nombreOriginal && tipoId === tipoOriginalId) return;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'verificar_nombre_estatus.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    if (xhr.responseText === 'exists') {
                        nombreInput.classList.add('error');
                        errorSpan.style.display = 'inline'; // Ahora aparecerá al lado
                    } else {
                        nombreInput.classList.add('valid');
                        validSpan.style.display = 'inline'; // Ahora aparecerá al lado
                        validSpan.classList.remove('fade-out');
                        setTimeout(() => validSpan.classList.add('fade-out'), 2000);
                        setTimeout(() => validSpan.style.display = 'none', 3000);
                    }
                }
            };
            xhr.send('nombre=' + encodeURIComponent(nombre) + '&tipo=' + encodeURIComponent(tipoTexto) + '&id=<?php echo $id; ?>');
        }

        nombreInput.addEventListener('input', validarNombre);
        tipoSelect.addEventListener('change', validarNombre);
    </script>
</body>
</html>