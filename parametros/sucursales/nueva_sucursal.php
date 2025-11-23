<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Configuración de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre']);
    $ubicacion = trim($_POST['ubicacion']);

    try {
        $pdo->beginTransaction();

        // 1. VALIDACIÓN ESTRICTA (Nombre único global)
        // Buscamos si el nombre ya existe en el historial (estado 1 o 2)
        $stmtCheck = $pdo->prepare('SELECT id, estado_id FROM sucursales WHERE nombre = :nombre');
        $stmtCheck->execute(['nombre' => $nombre]);
        $existente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            if ($existente['estado_id'] == 1) {
                throw new Exception('Ya existe una sucursal activa con este nombre.');
            } else {
                throw new Exception('Este nombre de sucursal existe en el historial pero está deshabilitado. Por integridad, use otro nombre o contacte al administrador.');
            }
        }

        // 2. INSERTAR NUEVA SUCURSAL
        $stmtIns = $pdo->prepare('INSERT INTO sucursales (nombre, ubicacion, estado_id) VALUES (:nombre, :ubicacion, 1)');
        $stmtIns->execute([
            'nombre' => $nombre,
            'ubicacion' => $ubicacion
        ]);

        $pdo->commit();

        $_SESSION['toast'] = ['type' => 'success', 'text' => 'Sucursal creada correctamente.'];
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
    <title>Nueva Sucursal</title>
    <!-- Reutilizamos estilos existentes para mantener la línea gráfica -->
    <link rel="stylesheet" href="../../styles/parametros/sucursales/nueva_sucursal.css">
    <style>
        /* Estilos específicos para validación */
        .error { border: 2px solid red !important; }
        .valid { border: 2px solid green !important; }
        
        /* Estilo para la alerta PHP */
        .alert-error { 
            background-color: #f8d7da; color: #721c24; padding: 15px; 
            border-radius: 5px; margin-bottom: 20px; text-align: center; 
            border: 1px solid #f5c6cb; font-weight: bold;
        }

        /* Mensajes de validación JS */
        .error-message { color: #dc3545; display: none; margin-top: 5px; font-size: 0.9em; font-weight: bold; }
        .valid-message { color: #28a745; display: none; margin-top: 5px; font-size: 0.9em; font-weight: bold; }
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
    <h1>Nueva Sucursal</h1>
    
    <?php if ($error_message): ?>
        <div class="alert-error">
            ⚠️ <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>
    
    <form id="sucursalForm" action="nueva_sucursal.php" method="post">
        <div class="form-group">
            <label for="nombre">Nombre de la Sucursal:</label>
            <input type="text" id="nombre" name="nombre" required 
                   value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                   autocomplete="off">
            
            <span class="error-message" id="nombre-error">Este nombre ya está registrado.</span>
            <span class="valid-message" id="nombre-valid">Nombre disponible.</span>
        </div>

        <div class="form-group">
            <label for="ubicacion">Dirección:</label>
            <textarea id="ubicacion" name="ubicacion" required rows="3"
                      ><?php echo htmlspecialchars($_POST['ubicacion'] ?? ''); ?></textarea>
        </div>

        <div class="buttons">
            <a href="sucursales.php" class="regresar-button">
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
    // Validación AJAX en tiempo real
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

        // Llamada AJAX al validador
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'verificar_sucursal.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                if (xhr.responseText === 'exists') {
                    nombreInput.classList.add('error');
                    errorSpan.style.display = 'inline';
                } else {
                    nombreInput.classList.add('valid');
                    validSpan.style.display = 'inline';
                    
                    // Efecto fade-out para el mensaje de éxito
                    validSpan.classList.remove('fade-out');
                    setTimeout(() => validSpan.classList.add('fade-out'), 2000);
                    setTimeout(() => validSpan.style.display = 'none', 3000);
                }
            }
        };
        xhr.send('nombre=' + encodeURIComponent(nombre));
    });
</script>
</body>
</html>