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
    <title>Contabilidad</title>
    <link rel="stylesheet" href="../styles/reportes/reportes.css">
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
            <a href="../index.php" class="overlay-link">
                <img src="../imagenes/IPSA.png" alt="Logo" class="logo">
                <span>IPROPORTSA</span>
            </a>
        </div>
        <div class="header-right">
            <span>Bienvenido, <?php echo $_SESSION['username']; ?></span>
            <a href="../logout.php" class="logout-button">Cerrar sesión</a>
        </div>
    </header>
    <main>
        <h1>Reportes</h1>
        <div class="menu">
            <div class="menu-item">
                <a href="Pagos recibidos/pagos_recibidos.php">
                    <img src="../imagenes/pendiente.png" alt="Pagos Recibidos y CxC">
                    <span>Contenedores</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="pagos_efectuados.php">
                    <img src="../imagenes/egresos.png" alt="Pagos Efectuados y CxP">
                    <span>Egresos</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="tipo_cambio.php">
                    <img src="../imagenes/estados.png" alt="Tipo de Cambio">
                    <span>Disponibles</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="tipo_cambio.php">
                    <img src="../imagenes/daño.png" alt="Tipo de Cambio">
                    <span>Dañados</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="tipo_cambio.php">
                    <img src="../imagenes/pend.png" alt="Tipo de Cambio">
                    <span>Pendientes</span>
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