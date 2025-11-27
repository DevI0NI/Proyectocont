<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// 1. CONTEXTO DE SUCURSAL (Seguridad)
if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../../index.php');
    exit;
}
$sucursal_id = $_SESSION['sucursal_activa'];

require '../config/database.php';

// Obtener los datos del contenedor
if (isset($_GET['nombre'])) {
    $nombreContenedor = $_GET['nombre'];
    
    // 2. CONSULTA ADAPTADA A NUEVA DB (IDs -> Nombres)
    // Usamos ALIAS (AS) para que tu HTML original siga funcionando sin cambios
    // Ejemplo: eu.nombre AS estatus_unidad hace que $contenedor['estatus_unidad'] tenga el texto correcto.
    $stmt = $pdo->prepare('
        SELECT 
            c.*,
            t.nombre as tipo_unidad_nombre,
            m.nombre as modelo_nombre,
            b.numero as bloque_numero,
            p.nombre as patio_nombre,
            eu.nombre as estatus_unidad, -- Alias clave para tu HTML
            eb.nombre as estatus_box     -- Alias clave para tu HTML
        FROM contenedores c
        LEFT JOIN tipos_unidad t ON c.tipo_unidad_id = t.id
        LEFT JOIN modelos m ON c.modelo_id = m.id
        LEFT JOIN bloques b ON c.bloque_id = b.id
        LEFT JOIN patios p ON b.patio_id = p.id
        -- Nuevos JOINs para traducir los IDs de estatus
        LEFT JOIN estatus_contenedores eu ON c.estatus_unidad_id = eu.id
        LEFT JOIN estatus_contenedores eb ON c.estatus_box_id = eb.id
        WHERE c.nombre = :nombre
          AND p.sucursal_id = :sucursal -- Filtro de seguridad
          AND c.estado_id = 1           -- Solo activos
    ');
    
    $stmt->execute(['nombre' => $nombreContenedor, 'sucursal' => $sucursal_id]);
    $contenedor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contenedor) {
        $_SESSION['toast_message'] = 'Contenedor no encontrado o no pertenece a esta sucursal.';
        $_SESSION['toast_type'] = 'error';
        header('Location: bloques.php');
        exit;
    }
} else {
    $_SESSION['toast_message'] = 'No se especificó un contenedor';
    $_SESSION['toast_type'] = 'error';
    header('Location: bloques.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Información del Contenedor</title>
    <link rel="stylesheet" href="../styles/parametros/bloques/informacion_contenedor.css">
    <style>
        /* Agrego el estilo del Toast por si no estaba en tu CSS externo */
        #toast-container { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; width: 90%; max-width: 400px; pointer-events: none; }
        .toast { padding: 15px 20px; border-radius: 8px; color: #fff; font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,0.15); opacity: 0; transform: translateY(-20px); transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 10px; }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.error { background-color: #dc3545; border-left: 5px solid #bd2130; }
        .toast.success { background-color: #28a745; border-left: 5px solid #1e7e34; }
    </style>
</head>
<body>

    <!-- Contenedor de Toasts -->
    <div id="toast-container"></div>

    <header>
        <div class="header-left">
            <a href="../index.php">
                <img src="../imagenes/IPSA.png" alt="Logo" class="logo">
                <span>IPROPORTSA</span>
            </a>
        </div>
        <div class="header-right">
            <span>Bienvenido, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a href="../logout.php" class="logout-button">Cerrar sesión</a>
        </div>
    </header>
    <main>
        <h1>Información del Contenedor</h1>
        <form>
            <div class="form-group">
                <label for="codigo_contenedor">Código del Contenedor:</label>
                <input type="text" id="codigo_contenedor" value="<?php echo htmlspecialchars($contenedor['nombre']); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="tipo_unidad">Tipo de Unidad:</label>
                <input type="text" id="tipo_unidad" value="<?php echo htmlspecialchars($contenedor['tipo_unidad_nombre']); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="modelo">Modelo:</label>
                <input type="text" id="modelo" value="<?php echo htmlspecialchars($contenedor['modelo_nombre']); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="estado_unidad">Estado de la Unidad:</label>
                <!-- Aquí se muestra el NOMBRE gracias al alias en la consulta SQL -->
                <input type="text" id="estado_unidad" value="<?php echo htmlspecialchars($contenedor['estatus_unidad']); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="observaciones_estado">Observaciones del Estado:</label>
                <textarea id="observaciones_estado" readonly><?php echo htmlspecialchars($contenedor['observaciones_unidad']); ?></textarea>
            </div>

            <div class="form-group">
                <label for="status_box">Status Box:</label>
                <!-- Aquí se muestra el NOMBRE gracias al alias en la consulta SQL -->
                <input type="text" id="status_box" value="<?php echo htmlspecialchars($contenedor['estatus_box']); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="observaciones_box">Observaciones Box:</label>
                <textarea id="observaciones_box" readonly><?php echo htmlspecialchars($contenedor['observaciones_box']); ?></textarea>
            </div>

            <div class="form-group">
                <label for="ubicacion">Ubicación:</label>
                <input type="text" id="ubicacion" value="Patio: <?php echo htmlspecialchars($contenedor['patio_nombre']); ?>, Bloque: <?php echo htmlspecialchars($contenedor['bloque_numero']); ?>, Posición: <?php echo htmlspecialchars($contenedor['posicion']); ?>" readonly>
            </div>
            
            <div class="buttons">
                <a href="bloques.php" class="regresar-button">
                    <img src="../imagenes/regresar.png" alt="Regresar">
                    <span>Regresar</span>
                </a>
                <a href="modificar_contenedor.php?nombre=<?php echo urlencode($contenedor['nombre']); ?>" class="guardar-button">
                    <img src="../imagenes/modificar.png" alt="Modificar">
                    <span>Modificar</span>
                </a>
            </div>
        </form>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>

    <!-- Script para Toasts -->
    <script>
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        
        // Animación
        void toast.offsetWidth; 
        toast.classList.add('show');

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    <?php if (isset($_SESSION['toast_message'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showToast('<?php echo addslashes($_SESSION['toast_message']); ?>', '<?php echo $_SESSION['toast_type'] ?? "success"; ?>');
        });
        <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
    <?php endif; ?>
    </script>
</body>
</html>