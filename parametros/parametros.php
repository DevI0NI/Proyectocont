<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parámetros del Sistema</title>
    <link rel="stylesheet" href="../styles/parametros/parametros.css">
</head>
<body>
<header>
  <div class="header-left">
    <a href="../index.php">
      <img src="../imagenes/IPSA.png" alt="Logo" class="logo">
      <span class="brand-name">IPROPORTSA</span>
    </a>
  </div>
  <div class="header-right">
    <span class="welcome-label">Bienvenido,</span>
    <span class="username"><?php echo $_SESSION['username']; ?></span>
    <a href="../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>
    <main>
        <h1>Parámetros del Sistema</h1>
        <div class="menu">
            <div class="menu-item">
                <a href="tiposunidad/tipos_unidad.php">
                    <img src="../imagenes/tipos_unidad.png" alt="Tipos de Unidad">
                    <span>Tipos de Unidad</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="estados/estados.php">
                    <img src="../imagenes/estados.png" alt="Estados de Contenedores">
                    <span>Estatus de Contenedores</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="bloques/configuracion.php">
                    <img src="../imagenes/bloquesconfig.png" alt="Configuración de Bloques">
                    <span>Configuración de Bloques</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="../index.php">
                    <img src="../imagenes/regresar.png" alt="Regresar">
                    <span>Regresar</span>
                </a>
            </div>
        </div>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>
</body>
</html>