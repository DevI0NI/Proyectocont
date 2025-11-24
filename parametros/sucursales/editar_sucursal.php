<?php
session_start();

// 1. Seguridad Básica
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// 2. BLINDAJE: SOLO SUPER ADMIN (Rol 1)
if ($_SESSION['rol_id'] != 1) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'Acceso denegado. Solo el Super Admin puede editar sucursales.'];
    header('Location: ../../index.php');
    exit;
}

// Configuración de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

$error_message = '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 3. Cargar datos (Solo activos)
$stmt = $pdo->prepare('SELECT * FROM sucursales WHERE id = :id AND estado_id = 1');
$stmt->execute(['id' => $id]);
$sucursal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sucursal) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'La sucursal no existe o está inactiva.'];
    header('Location: sucursales.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre']);
    $ubicacion = trim($_POST['ubicacion']);

    try {
        $pdo->beginTransaction();

        // 4. VALIDACIÓN ESTRICTA (Nombre único global, excluyendo el actual)
        // Buscamos si existe el nombre en CUALQUIER estado, pero que NO sea este mismo registro (id != :id)
        $stmtCheck = $pdo->prepare('SELECT id FROM sucursales WHERE nombre = :nombre AND id != :id');
        $stmtCheck->execute(['nombre' => $nombre, 'id' => $id]);
        
        if ($stmtCheck->fetchColumn()) {
            throw new Exception('Este nombre ya existe en el historial del sistema. Por favor, elija otro.');
        }

        // 5. ACTUALIZAR SUCURSAL
        $stmtUpdate = $pdo->prepare('UPDATE sucursales SET nombre = :nombre, ubicacion = :ubicacion WHERE id = :id');
        $stmtUpdate->execute([
            'nombre' => $nombre,
            'ubicacion' => $ubicacion,
            'id' => $id
        ]);

        $pdo->commit();

        $_SESSION['toast'] = ['type' => 'success', 'text' => 'Sucursal actualizada correctamente.'];
        header('Location: sucursales.php');
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
    <title>Editar Sucursal</title>
    <!-- Usamos el mismo CSS corregido -->
    <link rel="stylesheet" href="../../styles/parametros/sucursales/nueva_sucursal.css">
    <style>
        /* Estilos locales para validación */
        .error { border: 2px solid #ff4d4d !important; }
        .valid { border: 2px solid #4CAF50 !important; }
        
        .alert-error { 
            background-color: #f8d7da; color: #721c24; padding: 15px; 
            border-radius: 5px; margin-bottom: 20px; text-align: center; 
            border: 1px solid #f5c6cb; font-weight: bold; width: 60%; margin: 0 auto 20px auto;
        }

        .error-message { color: #ff4d4d; display: none; margin-top: 5px; font-size: 0.9em; font-weight: bold; text-align: right; }
        .valid-message { color: #4CAF50; display: none; margin-top: 5px; font-size: 0.9em; font-weight: bold; text-align: right; }
        .valid-message.fade-out { opacity: 0; transition: opacity 1s ease-out; }
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
    <h1>Editar Sucursal</h1>
    
    <?php if ($error_message): ?>
        <div class="alert-error">
            ⚠️ <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>
    
    <form id="sucursalForm" action="editar_sucursal.php?id=<?php echo $id; ?>" method="post">
        <div class="form-group">
            <label for="nombre">Nombre de la Sucursal:</label>
            <div style="flex: 1; display: flex; flex-direction: column;">
                <!-- Value carga el nombre actual -->
                <input type="text" id="nombre" name="nombre" required 
                       value="<?php echo htmlspecialchars($_POST['nombre'] ?? $sucursal['nombre']); ?>"
                       autocomplete="off">
                
                <span class="error-message" id="nombre-error">Este nombre ya está registrado.</span>
                <span class="valid-message" id="nombre-valid">Nombre disponible.</span>
            </div>
        </div>

        <div class="form-group">
            <label for="ubicacion">Ubicación / Dirección:</label>
            <!-- Textarea carga la ubicación actual -->
            <textarea id="ubicacion" name="ubicacion" required rows="3"><?php echo htmlspecialchars($_POST['ubicacion'] ?? $sucursal['ubicacion']); ?></textarea>
        </div>

        <div class="buttons">
            <a href="sucursales.php" class="regresar-button">
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

<script>
    // Guardamos el nombre original para evitar validar si no cambia
    const nombreOriginal = "<?php echo addslashes($sucursal['nombre']); ?>";
    const nombreInput = document.getElementById('nombre');
    const errorSpan = document.getElementById('nombre-error');
    const validSpan = document.getElementById('nombre-valid');

    nombreInput.addEventListener('input', function() {
        const nombre = this.value.trim();
        
        // Reset visuales
        this.classList.remove('error', 'valid');
        errorSpan.style.display = 'none';
        validSpan.style.display = 'none';

        if (nombre.length === 0) return;
        
        // Si el nombre es igual al original, es válido y no consultamos
        if (nombre === nombreOriginal) return;

        // Llamada AJAX al validador
        const xhr = new XMLHttpRequest();
        // Usamos el mismo verificador, pero enviamos el ID para excluirnos
        xhr.open('POST', 'verificar_sucursal.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                if (xhr.responseText === 'exists') {
                    nombreInput.classList.add('error');
                    errorSpan.style.display = 'block';
                } else {
                    nombreInput.classList.add('valid');
                    validSpan.style.display = 'block';
                    
                    validSpan.classList.remove('fade-out');
                    setTimeout(() => validSpan.classList.add('fade-out'), 2000);
                    setTimeout(() => validSpan.style.display = 'none', 3000);
                }
            }
        };
        // Enviamos ID para excluirnos a nosotros mismos de la validación
        xhr.send('nombre=' + encodeURIComponent(nombre) + '&id=<?php echo $id; ?>');
    });
</script>
</body>
</html>