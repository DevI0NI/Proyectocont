<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio</title>
    <link rel="stylesheet" href="styles/index.css">
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.remove('preload');
            document.body.classList.add('fade-in');
        });
    </script>
</head>
<body class="preload">
<header>
  <div class="header-left">
    <a href="index.php">
      <img src="imagenes/IPSA.png" alt="Logo" class="logo">
      <span class="brand-name">IPROPORTSA</span>
    </a>
  </div>
  <div class="header-right">
    <span class="welcome-label">Bienvenido,</span>
    <span class="username"><?php echo $_SESSION['username']; ?></span>
    <a href="logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>
    <main>
        <h1>Menú Principal</h1>
        <div class="menu">
            <div class="menu-item">
                <a href="ingresos/ingresos.php">
                    <img src="imagenes/ingreso.png" alt="Igrresos">
                    <span>Ingresos</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="bloques/bloques.php">
                    <img src="imagenes/bloques.png" alt="Bloques">
                    <span>Bloques</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="traslados/traslados.php">
                    <img src="imagenes/traslados.png" alt="Inventario">
                    <span>Traslados</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="reportes/reportes.php">
                    <img src="imagenes/reportes.png" alt="Reportes">
                    <span>Reportes</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="parametros/parametros.php">
                    <img src="imagenes/parametros.png" alt="Parámetros">
                    <span>Parámetros</span>
                </a>
            </div>
        </div>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>
</body>
</html>