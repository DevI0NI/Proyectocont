<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// BLINDAJE DE ROL
if (!in_array($_SESSION['rol_id'], [1, 2])) {
    header('Location: ../../index.php');
    exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

$error_message = '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Variables de contexto
$mi_id = $_SESSION['user_id'];
$mi_rol = $_SESSION['rol_id'];
$es_mi_perfil = ($id == $mi_id); // ¿Me estoy editando a mí mismo?

// --- 1. CARGAR USUARIO A EDITAR ---
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND estado_id = 1");
$stmt->execute(['id' => $id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'Usuario no encontrado.'];
    header('Location: usuarios.php');
    exit;
}

// --- 2. SEGURIDAD JERÁRQUICA ---
// Un Admin (2) no puede editar a un Super Admin (1) ni a otros Admins (2) que no sean él mismo.
if ($mi_rol == 2) {
    if ($usuario['rol_id'] == 1) { // Intentando editar Super Admin
        $_SESSION['toast'] = ['type' => 'error', 'text' => 'No tienes permisos para editar a un Super Admin.'];
        header('Location: usuarios.php');
        exit;
    }
    if ($usuario['rol_id'] == 2 && !$es_mi_perfil) { // Intentando editar otro Admin
        $_SESSION['toast'] = ['type' => 'error', 'text' => 'No tienes permisos para editar a otros Administradores.'];
        header('Location: usuarios.php');
        exit;
    }
}

// --- 3. CARGAR SELECTORES ---
// Roles permitidos para asignar
$minRol = ($mi_rol == 1) ? 2 : 3; 
// NOTA: Si soy Super Admin editándome a mí mismo, el rol 1 no sale en la lista, 
// pero lo manejaremos visualmente bloqueando el select.

$stmtRoles = $pdo->prepare("SELECT id, nombre FROM roles WHERE id >= :minRol AND estado_id = 1 ORDER BY id ASC");
$stmtRoles->execute(['minRol' => $minRol]);
$rolesDisponibles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

// Sucursales permitidas
if ($mi_rol == 1) {
    $stmtSuc = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursalesDisponibles = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmtSuc = $pdo->prepare("SELECT id, nombre FROM sucursales WHERE id = :id AND estado_id = 1");
    $stmtSuc->execute(['id' => $_SESSION['sucursal_usuario']]);
    $sucursalesDisponibles = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);
}

// --- 4. PROCESAR FORMULARIO (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $sucursal_id = (int)$_POST['sucursal_id'];
    
    // Lógica especial para el ROL
    if ($es_mi_perfil && $mi_rol == 1) {
        // Si soy Super Admin editándome: ¡FORZAR ROL 1!
        // Ignoramos lo que venga del formulario para evitar degradación accidental.
        $rol_id = 1; 
    } else {
        // Si no, tomamos lo que se eligió
        $rol_id = (int)$_POST['rol_id'];
    }

    // Validaciones RegEx (Igual que en nuevo_usuario)
    if (!preg_match('/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+$/', $username)) {
        $error_message = 'El nombre de usuario solo debe contener letras.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'El formato del correo electrónico es incorrecto.';
    } elseif (!empty($password) && !preg_match('/^[A-Za-z0-9]{6,}$/', $password)) {
        $error_message = 'La contraseña nueva debe tener al menos 6 caracteres (letras y números).';
    }

    if (empty($error_message)) {
        try {
            $pdo->beginTransaction();

            // Validación de Jerarquía (Backend)
            // Si NO soy yo mismo (Super Admin), valido que no esté creando un rol prohibido
            if (!($es_mi_perfil && $mi_rol == 1)) {
                 if ($rol_id < $minRol && $mi_rol != 1) { // Doble check
                     throw new Exception('Permisos insuficientes para asignar este rol.');
                 }
            }

            // Duplicados
            $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u AND id != :id');
            $stmtCheck->execute(['u' => $username, 'id' => $id]);
            if ($stmtCheck->fetchColumn() > 0) throw new Exception('El nombre de usuario ya está en uso.');

            $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :e AND id != :id');
            $stmtCheck->execute(['e' => $email, 'id' => $id]);
            if ($stmtCheck->fetchColumn() > 0) throw new Exception('El correo ya está registrado.');

            // Construir Update
            if (!empty($password)) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $sql = 'UPDATE users SET username = :u, email = :e, password = :p, rol_id = :r, sucursal_id = :s WHERE id = :id';
                $params = ['u'=>$username, 'e'=>$email, 'p'=>$passwordHash, 'r'=>$rol_id, 's'=>$sucursal_id, 'id'=>$id];
            } else {
                $sql = 'UPDATE users SET username = :u, email = :e, rol_id = :r, sucursal_id = :s WHERE id = :id';
                $params = ['u'=>$username, 'e'=>$email, 'r'=>$rol_id, 's'=>$sucursal_id, 'id'=>$id];
            }

            $stmtUpd = $pdo->prepare($sql);
            $stmtUpd->execute($params);

            $pdo->commit();
            $_SESSION['toast'] = ['type' => 'success', 'text' => 'Usuario actualizado correctamente.'];
            
            // Si me edité a mí mismo y cambié mi sucursal base, actualizo la sesión
            if ($es_mi_perfil) {
                $_SESSION['username'] = $username; // Por si cambió el nombre
                $_SESSION['sucursal_usuario'] = $sucursal_id; // Nueva sucursal base
            }

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
    <title>Editar Usuario</title>
    <!-- Usamos el mismo CSS que nuevo_usuario para mantener la simetría -->
    <link rel="stylesheet" href="../../styles/parametros/sucursales/nueva_sucursal.css">
    <style>
        /* ESTILOS DE ALINEACIÓN IDÉNTICOS A NUEVO_USUARIO */
        
        /* Centrar el título */
        h1 {
            text-align: center;
            width: 100%;
            margin-bottom: 30px;
            color: #333;
        }

        /* Contenedor del formulario centrado */
        form {
            width: 100%;
            max-width: 600px; /* Ancho máximo controlado */
            margin: 0 auto;   /* Centrado horizontal */
            display: flex;
            flex-direction: column;
            gap: 20px;        /* Espacio vertical uniforme */
        }

        /* Filas del formulario */
        .form-group {
            display: flex;
            align-items: flex-start; /* Alinear arriba para manejar mensajes de error debajo */
            gap: 15px;
        }

        /* Etiquetas alineadas */
        label {
            flex: 0 0 140px; /* Ancho fijo para todas las etiquetas */
            text-align: center;
            background-color: #80CBC4;
            padding: 10px;
            border-radius: 5px;
            color: #000;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 42px; /* Altura fija igual al input */
            margin: 0;
        }

        /* Contenedor de input + mensajes (Ocupa el resto) */
        .input-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* Inputs y Selects */
        input[type="text"], 
        input[type="email"], 
        input[type="password"],
        select {
            width: 100%;
            padding: 10px; 
            border: 1px solid #ccc; 
            border-radius: 4px; 
            font-size: 15px;
            height: 42px; /* Altura consistente */
            box-sizing: border-box;
        }

        /* Estados de validación visual */
        input.error { border-color: #ff4d4d !important; background-color: #fff0f0; }
        input.valid { border-color: #4CAF50 !important; background-color: #f0fff4; }

        /* Mensajes de validación */
        .validation-msg {
            font-size: 0.85rem;
            margin-top: 4px;
            display: none;
            font-weight: bold;
        }
        .error-text { color: #ff4d4d; }
        .valid-text { color: #4CAF50; }
        .info-pass { font-size: 0.8rem; color: #666; margin-top: 5px; display: block; }

        /* Botones centrados */
        .buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        /* Alerta PHP */
        .alert-error { 
            background-color: #f8d7da; color: #721c24; padding: 15px; 
            border-radius: 5px; margin: 0 auto 20px auto; text-align: center; 
            border: 1px solid #f5c6cb; font-weight: bold; max-width: 600px;
        }

        /* Estilo para inputs deshabilitados */
        .input-locked { background-color: #e9ecef !important; color: #495057; cursor: not-allowed; }

        /* Responsive para móviles */
        @media (max-width: 768px) {
            .form-group {
                flex-direction: column;
                align-items: stretch;
                gap: 5px;
            }
            label {
                width: 100%;
                flex: none;
                text-align: left;
                justify-content: flex-start;
                padding-left: 15px;
            }
            .buttons {
                flex-direction: column-reverse;
            }
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
    <span class="username"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
    <a href="../../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

<main>
    <h1>Editar Usuario</h1>
    
    <?php if ($error_message): ?>
        <div class="alert-error">⚠️ <?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    
    <form action="editar_usuario.php?id=<?php echo $id; ?>" method="post" autocomplete="off">
        
        <!-- USUARIO -->
        <div class="form-group">
            <label for="username">Usuario:</label>
            <div class="input-wrapper">
                <input type="text" id="username" name="username" required 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? $usuario['username']); ?>"
                       pattern="[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+" 
                       title="Solo se permiten letras y espacios.">
                <span id="user-msg" class="validation-msg"></span>
            </div>
        </div>

        <!-- CORREO -->
        <div class="form-group">
            <label for="email">Correo:</label>
            <div class="input-wrapper">
                <input type="email" id="email" name="email" required 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? $usuario['email']); ?>"
                       pattern="[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,4}"
                       title="Debe ser un correo válido.">
                <span id="email-msg" class="validation-msg"></span>
            </div>
        </div>

        <!-- CONTRASEÑA (Opcional en edición) -->
        <div class="form-group">
            <label for="password">Contraseña:</label>
            <div class="input-wrapper">
                <input type="password" id="password" name="password" 
                       placeholder="Dejar en blanco para no cambiar" 
                       minlength="6"
                       pattern="[A-Za-z0-9]+"
                       title="Debe tener al menos 6 caracteres y solo contener letras y números."
                       autocomplete="new-password">
            </div>
        </div>

        <!-- ROL -->
        <div class="form-group">
            <label for="rol_id">Rol:</label>
            <div class="input-wrapper">
            <?php if ($es_mi_perfil && $mi_rol == 1): ?>
                <!-- CASO ESPECIAL: Super Admin editándose a sí mismo. -->
                <input type="text" value="Super Admin" class="input-locked" disabled>
                <input type="hidden" name="rol_id" value="1">
            <?php else: ?>
                <!-- Caso Normal -->
                <select id="rol_id" name="rol_id" required>
                    <option value="">Seleccione un Rol</option>
                    <?php foreach ($rolesDisponibles as $rol): ?>
                        <?php 
                            $selected = '';
                            if (isset($_POST['rol_id']) && $_POST['rol_id'] == $rol['id']) $selected = 'selected';
                            elseif (!isset($_POST['rol_id']) && $usuario['rol_id'] == $rol['id']) $selected = 'selected';
                        ?>
                        <option value="<?php echo $rol['id']; ?>" <?php echo $selected; ?>>
                            <?php echo htmlspecialchars($rol['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            </div>
        </div>

        <!-- SUCURSAL -->
        <div class="form-group">
            <label for="sucursal_id">Sucursal:</label>
            <div class="input-wrapper">
            <select id="sucursal_id" name="sucursal_id" required>
                <?php foreach ($sucursalesDisponibles as $suc): ?>
                    <?php 
                        $selected = '';
                        if (isset($_POST['sucursal_id']) && $_POST['sucursal_id'] == $suc['id']) $selected = 'selected';
                        elseif (!isset($_POST['sucursal_id']) && $usuario['sucursal_id'] == $suc['id']) $selected = 'selected';
                    ?>
                    <option value="<?php echo $suc['id']; ?>" <?php echo $selected; ?>>
                        <?php echo htmlspecialchars($suc['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            </div>
        </div>

        <div class="buttons">
            <a href="usuarios.php" class="regresar-button">
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
    const VALIDATOR_URL = 'verificar_usuario.php';
    
    // Datos originales para evitar validar si no cambian
    const originalUser = "<?php echo addslashes($usuario['username']); ?>";
    const originalEmail = "<?php echo addslashes($usuario['email']); ?>";
    // ID para excluirse a sí mismo en la validación
    // NOTA: verificar_usuario.php debe soportar ID para que esto funcione perfecto. 
    // Si no, solo validará duplicados nuevos.
    const currentId = <?php echo $id; ?>; 

    function checkDuplicidad(field, value, errorElement, inputElement, originalValue) {
        if (value.length === 0) return;
        
        // Si es igual al original, no validamos en servidor
        if (value === originalValue) {
            errorElement.style.display = 'none';
            inputElement.classList.remove('error', 'valid');
            return;
        }

        if (!inputElement.checkValidity()) {
             inputElement.classList.add('error');
             inputElement.classList.remove('valid');
             return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', VALIDATOR_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            errorElement.style.display = 'none';
            inputElement.classList.remove('error', 'valid');
            
            if (xhr.status === 200) {
                if (xhr.responseText === 'exists') {
                    errorElement.style.display = 'block';
                    errorElement.className = 'validation-msg error-text';
                    errorElement.textContent = '⚠️ Ya registrado';
                    inputElement.classList.add('error');
                } else {
                    errorElement.style.display = 'block';
                    errorElement.className = 'validation-msg valid-text';
                    errorElement.textContent = '✅ Disponible';
                    inputElement.classList.add('valid');
                    setTimeout(() => { errorElement.style.display = 'none'; }, 3000);
                }
            }
        };
        xhr.send(`${field}=` + encodeURIComponent(value) + `&id=${currentId}`);
    }

    const userIn = document.getElementById('username');
    const userMsg = document.getElementById('user-msg');
    userIn.addEventListener('input', () => checkDuplicidad('username', userIn.value.trim(), userMsg, userIn, originalUser));

    const emailIn = document.getElementById('email');
    const emailMsg = document.getElementById('email-msg');
    emailIn.addEventListener('input', () => checkDuplicidad('email', emailIn.value.trim(), emailMsg, emailIn, originalEmail));

    // Validación visual de pass
    document.getElementById('password').addEventListener('input', function() {
        if(this.value.length > 0 && (this.value.length < 6 || !this.checkValidity())) {
            this.classList.add('error');
        } else {
            this.classList.remove('error');
        }
    });
</script>
</body>
</html>