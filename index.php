<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require 'config/database.php';

// --- LÓGICA DE CONTEXTO Y ROLES ---
$rol_id = (int)$_SESSION['rol_id'];
$sucursales = [];
$nombreSucursalActiva = "Cargando...";

// 1. Lógica para Super Admin (Rol 1): Cargar lista para el selector
if ($rol_id == 1) {
    $stmtSuc = $pdo->query("SELECT id, nombre FROM sucursales WHERE estado_id = 1 ORDER BY nombre ASC");
    $sucursales = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);

    // Si no hay sucursal activa, poner la primera por defecto
    if (!isset($_SESSION['sucursal_activa']) && count($sucursales) > 0) {
        $_SESSION['sucursal_activa'] = $sucursales[0]['id'];
    }
}

// 2. Obtener el nombre de la sucursal actual
if (isset($_SESSION['sucursal_activa'])) {
    $stmtNom = $pdo->prepare("SELECT nombre FROM sucursales WHERE id = :id");
    $stmtNom->execute(['id' => $_SESSION['sucursal_activa']]);
    $nombreSucursalActiva = $stmtNom->fetchColumn() ?: "Desconocida";
} else {
    $nombreSucursalActiva = "Sin Asignar";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio - IPROPORTSA</title>
    <link rel="stylesheet" href="styles/index.css">
    <style>
        /* Estilos para el contenedor de sucursal centralizado */
        .sucursal-container {
            text-align: center;
            margin-bottom: 30px;
            font-size: 1.2rem;
            color: #555;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        /* Estilo del Select para Super Admin */
        .sucursal-select {
            font-size: 1.1rem;
            padding: 5px 15px;
            border: 2px solid #80CBC4;
            border-radius: 25px;
            background-color: #fff;
            color: #00695C;
            font-weight: bold;
            cursor: pointer;
            outline: none;
            transition: all 0.3s ease;
        }
        .sucursal-select:hover {
            background-color: #E0F2F1;
            border-color: #00695C;
        }

        /* Estilo del Texto Fijo para Usuarios Normales */
        .sucursal-fija {
            font-weight: bold;
            color: #00695C;
            font-size: 1.2rem;
            padding: 5px 15px;
            background: #E0F2F1;
            border-radius: 25px;
        }
    </style>
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
    <!-- Header limpio: Solo usuario y salir -->
    <span class="welcome-label">Bienvenido,</span>
    <span class="username"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
    <a href="logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

    <main>
        <h1>Menú Principal</h1>
        
        <!-- Selector o Indicador de Sucursal Centralizado -->
        <div class="sucursal-container">
            <span>Sucursal:</span>
            
            <?php if ($rol_id == 1): // Super Admin: Muestra Select ?>
                <form action="cambiar_sucursal.php" method="POST" style="margin:0;">
                    <select name="sucursal_id" class="sucursal-select" onchange="this.form.submit()">
                        <?php foreach ($sucursales as $suc): ?>
                            <option value="<?php echo $suc['id']; ?>" 
                                <?php echo ($_SESSION['sucursal_activa'] == $suc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($suc['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php else: // Usuario Normal: Muestra Texto Fijo ?>
                <span class="sucursal-fija">
                    <?php echo htmlspecialchars($nombreSucursalActiva); ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="menu">
            <!-- 1. INGRESOS -->
             <?php if ($rol_id == 1 || $rol_id == 2 || $rol_id == 3): ?>
            <div class="menu-item">
                <a href="ingresos/ingresos.php">
                    <img src="imagenes/ingreso.png" alt="Ingresos">
                    <span>Ingresos</span>
                </a>
            </div>
            <?php endif; ?>

            <!-- 2. BLOQUES -->
            <div class="menu-item">
                <a href="bloques/bloques.php">
                    <img src="imagenes/bloques.png" alt="Bloques">
                    <span>Bloques</span>
                </a>
            </div>

                        <!-- 2. BLOQUES -->
            <div class="menu-item">
                <a href="bloques/bloques.php">
                    <img src="imagenes/bloques.png" alt="Bloques">
                    <span>Contenedores</span>
                </a>
            </div>

            <!-- 3. TRASLADOS -->
            <?php if ($rol_id == 1 || $rol_id == 2 || $rol_id == 3): ?>
            <div class="menu-item">
                <a href="traslados/traslados.php">
                    <img src="imagenes/traslados.png" alt="Traslados">
                    <span>Traslados</span>
                </a>
            </div>
            <?php endif; ?>

            <!-- 4. REPORTES (Rol 1 y 2) -->
            <?php if ($rol_id == 1 || $rol_id == 2): ?>
            <div class="menu-item">
                <a href="reportes/reportes.php">
                    <img src="imagenes/reportes.png" alt="Reportes">
                    <span>Reportes</span>
                </a>
            </div>
            <?php endif; ?>

            <!-- 5. PARÁMETROS (Rol 1 y 2) -->
            <?php if ($rol_id == 1 || $rol_id == 2): ?>
            <div class="menu-item">
                <a href="parametros/parametros.php">
                    <img src="imagenes/parametros.png" alt="Parámetros">
                    <span>Parámetros</span>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>
</body>
</html>