<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// BLINDAJE: Solo Super Admin (1) y Admin (2) pueden entrar aquí
if (!in_array($_SESSION['rol_id'], [1, 2])) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'Acceso denegado.'];
    header('Location: ../../index.php');
    exit;
}

// Configuración de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

$error_message = '';
$rol_usuario_actual = $_SESSION['rol_id'];
$sucursal_usuario_actual = $_SESSION['sucursal_usuario'];

// --- 1. CARGAR ROLES PERMITIDOS ---
$minRol = ($rol_usuario_actual == 1) ? 2 : 3;

$stmtRoles = $pdo->prepare("SELECT id, nombre FROM roles WHERE id >= :minRol AND estado_id = 1 ORDER BY id ASC");
$stmtRoles->execute(['minRol' => $minRol]);
$rolesDisponibles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

// --- 2. CARGAR SUCURSALES PERMITIDAS ---
if ($rol_usuario_actual == 1) {
    $stmtSuc = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursalesDisponibles = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmtSuc = $pdo->prepare("SELECT id, nombre FROM sucursales WHERE id = :id AND estado_id = 1");
    $stmtSuc->execute(['id' => $sucursal_usuario_actual]);
    $sucursalesDisponibles = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
}

// --- 3. PROCESAR FORMULARIO (Backend Validation) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username   = trim($_POST['username']);
    $email      = trim($_POST['email']);
    $password   = $_POST['password'];
    $rol_id     = (int)$_POST['rol_id'];
    $sucursal_id = (int)$_POST['sucursal_id'];

    // VALIDACIÓN DE REGEX EN PHP (Doble defensa si el JS falla)
    if (!preg_match('/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+$/', $username)) {
        $error_message = 'El nombre de usuario solo debe contener letras.';
    } elseif (!preg_match('/^[A-Za-z0-9]{6,}$/', $password)) {
        $error_message = 'La contraseña debe tener al menos 6 caracteres y solo contener letras y números.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'El formato del correo electrónico es incorrecto.';
    }

    if (empty($error_message)) {
        try {
            $pdo->beginTransaction();

            // Validación de Duplicados (Nombre de usuario único)
            $stmtCheckUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
            $stmtCheckUser->execute(['username' => $username]);
            if ($stmtCheckUser->fetchColumn() > 0) {
                throw new Exception('El nombre de usuario ya está ocupado.');
            }

            // Validación de Duplicados (Email único)
            $stmtCheckEmail = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
            $stmtCheckEmail->execute(['email' => $email]);
            if ($stmtCheckEmail->fetchColumn() > 0) {
                throw new Exception('El correo electrónico ya está registrado.');
            }

            // Validación de seguridad: Evitar que un Admin cree un rol no permitido
            if ($rol_id < $minRol) {
                throw new Exception('No tiene permisos para crear este tipo de rol.');
            }

            // Hashing de contraseña
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // Insertar Usuario (estado_id = 1 por defecto)
            $stmtIns = $pdo->prepare('
                INSERT INTO users (username, password, email, rol_id, sucursal_id, estado_id) 
                VALUES (:username, :password, :email, :rol, :sucursal, 1)
            ');
            $stmtIns->execute([
                'username' => $username,
                'password' => $passwordHash,
                'email'    => $email,
                'rol'      => $rol_id,
                'sucursal' => $sucursal_id
            ]);

            $pdo->commit();

            $_SESSION['toast'] = ['type' => 'success', 'text' => 'Usuario creado exitosamente.'];
            header('Location: usuarios.php');
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_message = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo Usuario</title>
    <link rel="stylesheet" href="../../styles/parametros/nuevo_usuario.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Estilos de errores */
        .alert-error { 
            background-color: #f8d7da; color: #721c24; padding: 15px; 
            border-radius: 5px; margin-bottom: 20px; text-align: center; 
            border: 1px solid #f5c6cb; font-weight: bold; width: 60%; margin: 0 auto 20px auto;
        }
        /* Estilos para mensajes de validación en tiempo real */
        .validation-message { 
            display: none; font-size: 0.9em; margin-top: 5px; font-weight: bold; 
            text-align: right; width: 100%; 
        }
        .validation-message.error { color: #ff4d4d; }
        .validation-message.valid { color: #4CAF50; }
        .validation-message.fade-out { opacity: 0; transition: opacity 1s ease-out; }

        /* Estilos visuales de validación JS */
        input.error, select.error { border-color: #ff4d4d !important; }
        input.valid, select.valid { border-color: #4CAF50 !important; }

        /* === NUEVO: ESTILO PARA EL OJO DE CONTRASEÑA === */
        .input-container { position: relative; }
        
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            font-size: 1.2rem;
            color: #777;
            user-select: none; /* Evita que se seleccione al doble clic */
        }
        .toggle-password:hover { color: #333; }
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
    <h1>Registrar Nuevo Usuario</h1>
    
    <?php if ($error_message): ?>
        <div class="alert-error">
            ⚠️ <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>
    
    <form action="nuevo_usuario.php" method="post" autocomplete="off">
        
        <div class="form-group">
            <label for="username">Usuario:</label>
            <div class="input-container">
                <input type="text" id="username" name="username" required 
                       placeholder="Ej: JuanPerez"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                       pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" 
                       title="Solo se permiten letras y espacios.">
                
                <span class="validation-message error" id="user-error">El usuario ya está en uso.</span>
                <span class="validation-message valid" id="user-valid">Usuario disponible.</span>
            </div>
        </div>

        <div class="form-group">
            <label for="email">Correo:</label>
            <div class="input-container">
                <input type="email" id="email" name="email" required 
                       placeholder="correo@ejemplo.com"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                       pattern="[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,4}"
                       title="Debe ser un correo válido (ej: nombre@dominio.com).">
                
                <span class="validation-message error" id="email-error">Este correo ya está registrado.</span>
                <span class="validation-message valid" id="email-valid">Correo disponible.</span>
            </div>
        </div>

        <div class="form-group">
            <label for="password">Contraseña:</label>
            <div class="input-container">
                <input type="password" id="password" name="password" required 
                       placeholder="Mínimo 6 caracteres" 
                       minlength="6"
                       pattern="[A-Za-z0-9]+"
                       title="Debe tener al menos 6 caracteres y solo contener letras y números."
                       autocomplete="new-password"
                       style="padding-right: 40px;"> <i class="fa-solid fa-eye toggle-password" onclick="togglePassword()" id="togglePasswordIcon"></i>
            </div>
        </div>

        <div class="form-group">
            <label for="rol_id">Rol:</label>
            <select id="rol_id" name="rol_id" required>
                <option value="">Seleccione un Rol</option>
                <?php foreach ($rolesDisponibles as $rol): ?>
                    <option value="<?php echo $rol['id']; ?>" <?php echo (isset($_POST['rol_id']) && $_POST['rol_id'] == $rol['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($rol['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="sucursal_id">Sucursal:</label>
            <select id="sucursal_id" name="sucursal_id" required>
                <?php if (count($sucursalesDisponibles) > 1): ?>
                    <option value="">Seleccione una Sucursal</option>
                <?php endif; ?>

                <?php foreach ($sucursalesDisponibles as $suc): ?>
                    <option value="<?php echo $suc['id']; ?>" 
                        <?php 
                            // Autoseleccionar si solo hay una opción o si viene del POST
                            echo (count($sucursalesDisponibles) == 1 || (isset($_POST['sucursal_id']) && $_POST['sucursal_id'] == $suc['id'])) ? 'selected' : ''; 
                        ?>>
                        <?php echo htmlspecialchars($suc['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="buttons">
            <a href="usuarios.php" class="regresar-button">
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
    const VALIDATOR_URL = 'verificar_usuario.php';

    // === FUNCIÓN TOGGLE PASSWORD (NUEVO) ===
function togglePassword() {
        const passwordInput = document.getElementById('password');
        const icon = document.getElementById('togglePasswordIcon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            // Cambiar icono a "Ojo Tachado" (Slash)
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            // Volver a "Ojo Normal"
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    // Función principal para checkear duplicidad vía AJAX
    function checkDuplicidad(field, value, errorElement, validElement) {
        if (value.length === 0) return;

        // Verificar el patrón RegEx antes de enviar al servidor (optimización)
        let patternValid = true;
        const inputField = document.getElementById(field);
        
        // HACK: Usar el checkValidity del navegador para probar el pattern="[...]"
        if (inputField.checkValidity() === false) {
             // Si el patrón falla (ej. usuario mete un número en el nombre), no se hace check de duplicidad
             inputField.classList.add('error');
             inputField.classList.remove('valid');
             errorElement.textContent = inputField.title; // Muestra el error de RegEx
             errorElement.style.display = 'block';
             return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', VALIDATOR_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            // Reset visual
            errorElement.style.display = 'none';
            validElement.style.display = 'none';
            inputField.classList.remove('error', 'valid');
            
            if (xhr.status === 200) {
                if (xhr.responseText === 'exists') {
                    errorElement.textContent = (field === 'username') ? 'El usuario ya está en uso.' : 'El correo ya está registrado.';
                    errorElement.style.display = 'block';
                    inputField.classList.add('error');
                } else {
                    validElement.style.display = 'block';
                    inputField.classList.add('valid');
                    
                    validElement.classList.remove('fade-out');
                    setTimeout(() => validElement.classList.add('fade-out'), 2000);
                    setTimeout(() => validElement.style.display = 'none', 3000);
                }
            }
        };
        // Enviamos el campo y su valor para que el backend sepa qué buscar
        xhr.send(`${field}=` + encodeURIComponent(value));
    }

    // --- Listeners para Validación ---

    // 1. USUARIO
    document.getElementById('username').addEventListener('input', function() {
        const value = this.value.trim();
        checkDuplicidad('username', value, 
            document.getElementById('user-error'), 
            document.getElementById('user-valid'));
    });

    // 2. CORREO
    document.getElementById('email').addEventListener('input', function() {
        const value = this.value.trim();
        checkDuplicidad('email', value, 
            document.getElementById('email-error'), 
            document.getElementById('email-valid'));
    });

</script>
</body>
</html>