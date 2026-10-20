<?php
session_start();
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio de sesion</title>
    <link rel="stylesheet" href="styles/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="login-page">
        <section class="hero-panel">
            <div class="hero-overlay"></div>
            <div class="hero-content">
                <img src="imagenes/logo.gif" alt="Logo IPSA Web" class="hero-logo">
                <h1>Proportsa</h1>
            </div>
            <div class="hero-curve" aria-hidden="true"></div>
        </section>

        <main class="login-shell">
            <div class="login-container">
                <div class="login-header">
                    <h2>Ingrese sus credenciales</h2>
                </div>

                <?php if ($error): ?>
                    <div class="error-message">
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form action="authenticate.php" method="post">
                    <label for="email">Correo electronico</label>
                    <input type="email" id="email" name="email" placeholder="Ingrese su correo" required>

                    <label for="password">Contrasena</label>
                    <div class="password-container">
                        <input type="password" id="password" name="password" placeholder="Ingrese su contrasena" required>
                        <i class="fa-solid fa-eye" id="togglePassword"></i>
                    </div>

                    <button type="submit">Iniciar sesion</button>
                </form>

                <footer>
                    <p>&copy; IPSA Web - 2026</p>
                </footer>
            </div>
        </main>
    </div>
    <script src="login.js"></script>
</body>
</html>
