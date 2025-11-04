<?php
session_start();
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="styles/login.css">
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="imagenes/logo.gif" alt="Logo" class="logo">
        </div>
        <?php if ($error): ?>
            <div class="error-message">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        <form action="authenticate.php" method="post">
            <label for="email">Correo electrónico:</label>
            <input type="email" id="email" name="email" required>
            <label for="password">Contraseña:</label>
            <input type="password" id="password" name="password" required>
            <div class="remember-me">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Recordar cuenta</label>
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