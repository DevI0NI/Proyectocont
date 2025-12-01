<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// --- SEGURIDAD: CONTROL DE ACCESO POR ROL ---
// Solo Super Admin (1) y Admin (2) pueden ver esta página.
// Si el rol no es 1 ni 2, lo redirigimos al menú principal.
if (!in_array($_SESSION['rol_id'], [1, 2])) {
    header('Location: ../index.php');
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
    <style>
        /* Estilo simple para el nuevo ícono si no tienes imagen aún */
        .menu-item img { opacity: 0.9; transition: transform 0.2s; }
        .menu-item:hover img { transform: scale(1.1); opacity: 1; }
    </style>
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
    <span class="username"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></span>
    <a href="../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>
    <main>
        <h1>Parámetros del Sistema</h1>
        <div class="menu">
            
            <!-- Gestión de Sucursales (SOLO SUPER ADMIN - ROL 1) -->
            <?php if ($_SESSION['rol_id'] == 1): ?>
            <div class="menu-item">
                <a href="sucursales/sucursales.php">
                    <!-- Usa una imagen genérica si no tienes sucursales.png aún -->
                    <img src="../imagenes/sucursales.png" alt="Sucursales" onerror="this.src='../imagenes/bloquesconfig.png'">
                    <span>Sucursales</span>
                </a>
            </div>
            <?php endif; ?>

            <!-- Gestión de Usuarios (Disponible para Admin y Super Admin) -->
            <div class="menu-item">
                <a href="usuarios/usuarios.php">
                    <img src="../imagenes/usuarios.png" alt="Usuarios" onerror="this.src='../imagenes/tipos_unidad.png'">
                    <span>Usuarios</span>
                </a>
            </div>

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
                        <!-- Gestión de Sucursales (SOLO SUPER ADMIN - ROL 1) -->
            <?php if ($_SESSION['rol_id'] == 1): ?>
            <div class="menu-item">
                <a href="limpieza/limpieza.php">
                    <!-- Usa una imagen genérica si no tienes sucursales.png aún -->
                    <img src="../imagenes/limpieza.png" alt="Sucursales" onerror="this.src='../imagenes/bloquesconfig.png'">
                    <span>Limpieza de Datos</span>
                </a>
            </div>
            <?php endif; ?>
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