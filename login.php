<?php
session_start();
// Optimización: Operador de fusión de null (más limpio y rápido que isset ? :)
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="styles/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="imagenes/logo.gif" alt="Logo" class="logo">
        </div>

        <?php if ($error): ?>
            <div class="error-message">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form action="authenticate.php" method="post">
            <label for="email">Correo electrónico:</label>
            <input type="email" id="email" name="email" required>

            <label for="password">Contraseña:</label>
            <div class="password-container">
                <input type="password" id="password" name="password" required>
                <i class="fa-solid fa-eye" id="togglePassword"></i>
            </div>

            <button type="submit">Iniciar sesión</button>
            
            <div class="forgot-password">
                <a href="#">¿Ha olvidado su contraseña?</a>
            </div>
        </form>

        <footer>
            <p>&copy; IPSA Web - 2025</p>
        </footer>
    </div>
    <script src="login.js"></script>
</body>
</html>