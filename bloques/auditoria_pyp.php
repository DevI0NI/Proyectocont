<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// 1. SEGURIDAD DE CONTEXTO
if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../index.php');
    exit;
}
$sucursal_id = $_SESSION['sucursal_activa'];
$rol_usuario = (int)$_SESSION['rol_id'];
$usuario_id  = $_SESSION['user_id'];

// BLINDAJE DE ROL: Solo Mecánicos (4) y Empresa Externa (5) deberían estar aquí
if (!in_array($rol_usuario, [4, 5])) {
    $_SESSION['toast_message'] = 'No tienes permisos para realizar auditorías.';
    $_SESSION['toast_type'] = 'error';
    header('Location: contenedor.php');
    exit;
}

require '../config/database.php';

// Referencias para volver al lugar exacto
$ref_patio = $_GET['ref_patio'] ?? '';
$ref_bloque = $_GET['ref_bloque'] ?? '';

// 2. OBTENER DATOS (Solo para validación interna, no mostrar info visual)
if (isset($_GET['nombre'])) {
    $nombreContenedor = $_GET['nombre'];

    $stmt = $pdo->prepare('
        SELECT 
            c.id, c.nombre, 
            pa.estatus_pyp, 
            pa.informacion_adicional,
            pa.fecha_registro
        FROM contenedores c
        JOIN bloques b ON c.bloque_id = b.id
        JOIN patios p ON b.patio_id = p.id
        LEFT JOIN pyp_auditoria pa ON c.id = pa.contenedor_id
        WHERE c.nombre = :nombre 
          AND p.sucursal_id = :sucursal
          AND c.estado_id = 1
    ');
    $stmt->execute(['nombre' => $nombreContenedor, 'sucursal' => $sucursal_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        header('Location: contenedor.php');
        exit;
    }
} else {
    header('Location: contenedor.php');
    exit;
}

// 3. PROCESAR GUARDADO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nuevoEstatus = trim($_POST['estatus_pyp']);
    $nuevaInfo    = trim($_POST['informacion_adicional']);
    $idContenedor = $data['id'];

    // Validaciones
    if (strlen($nuevoEstatus) > 25) {
        $error_message = "El estatus no puede superar los 25 caracteres.";
    } elseif (empty($nuevaInfo)) {
        $error_message = "La información adicional es obligatoria.";
    } elseif (strlen($nuevaInfo) > 150) {
        $error_message = "La información adicional no puede superar los 150 caracteres.";
    } else {
        try {
            $pdo->beginTransaction();

            $stmtUpd = $pdo->prepare('
                UPDATE pyp_auditoria 
                SET estatus_pyp = :estatus, 
                    informacion_adicional = :info,
                    usuario_id = :uid,
                    fecha_registro = NOW()
                WHERE contenedor_id = :cid
            ');
            $stmtUpd->execute([
                'estatus' => $nuevoEstatus,
                'info'    => $nuevaInfo,
                'uid'     => $usuario_id,
                'cid'     => $idContenedor
            ]);

            // Logs solo si hubo cambios
            if ($data['estatus_pyp'] !== $nuevoEstatus || $data['informacion_adicional'] !== $nuevaInfo) {
                $obsLog = "Auditoría PYP Actualizada. Estatus: $nuevoEstatus.";
                $stmtLog = $pdo->prepare('INSERT INTO logs_eventos (contenedor_id, usuario_id, tipo_operacion_id, observaciones, estado_id) VALUES (?, ?, 4, ?, 1)');
                $stmtLog->execute([$idContenedor, $usuario_id, $obsLog]);
            }

            $pdo->commit();

            $_SESSION['toast_message'] = 'Auditoría guardada correctamente.';
            $_SESSION['toast_type'] = 'success';

            // Redirección
            $url = "contenedor.php";
            if($ref_patio && $ref_bloque) {
                $url .= "?patio_id=$ref_patio&bloque_id=$ref_bloque";
            }
            header("Location: $url");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_message = "Error al guardar: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión Estatus PYP</title>
    <link rel="stylesheet" href="../styles/parametros/bloques/modificar_contenedor.css">
    <style>
        .char-count { font-size: 0.8rem; color: #666; text-align: right; display: block; margin-top: 2px; }
        .text-danger { color: #dc3545; }
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
    <span class="username"><?php echo $_SESSION['username']; ?></span>
    <a href="../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

<main>
    <h1>Gestión Estatus PYP</h1>

    <?php if (isset($error_message)): ?>
        <div style="background-color:#f8d7da; color:#721c24; padding:10px; border-radius:5px; text-align:center; margin-bottom:15px;">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <form action="" method="post">
        
        <div class="form-group">
            <label for="estatus_pyp">Estatus PYP (Corto):</label>
            <input type="text" id="estatus_pyp" name="estatus_pyp" 
                   value="<?php echo htmlspecialchars($data['estatus_pyp'] ?? ''); ?>" 
                   maxlength="25" 
                   required
                   placeholder="Ej: En Revisión, Aprobado..."
                   autocomplete="off">
        </div>

        <div class="form-group">
            <label for="informacion_adicional">Información Adicional:</label>
            <textarea id="informacion_adicional" name="informacion_adicional" rows="5" 
                      maxlength="150"
                      required
                      placeholder="Detalles obligatorios de la auditoría..."><?php echo htmlspecialchars($data['informacion_adicional'] ?? ''); ?></textarea>
        </div>

        <div class="buttons">
            <?php
                $backLink = "contenedor.php";
                if ($ref_patio && $ref_bloque) {
                    $backLink .= "?patio_id=" . urlencode($ref_patio) . "&bloque_id=" . urlencode($ref_bloque);
                }
            ?>
            <a href="<?php echo htmlspecialchars($backLink); ?>" class="regresar-button">
                <img src="../imagenes/regresar.png" alt="Regresar">
                <span>Cancelar</span>
            </a>

            <button type="submit" class="guardar-button">
                <img src="../imagenes/Save.png" alt="Guardar">
                <span>Guardar Cambios</span>
            </button>
        </div>
    </form>
</main>

<footer>
    <p>&copy; IPSA Web - 2025</p>
</footer>

<script>
    // Función genérica para contadores
    function setupCounter(inputId, counterId, maxLen) {
        const input = document.getElementById(inputId);
        const counter = document.getElementById(counterId);
        
        function update() {
            const len = input.value.length;
            counter.textContent = `${len} / ${maxLen}`;
            
            if (len >= maxLen) {
                counter.classList.add('text-danger');
            } else {
                counter.classList.remove('text-danger');
            }
        }
        
        input.addEventListener('input', update);
        update(); // Inicializar
    }

    setupCounter('estatus_pyp', 'contador-estatus', 25);
    setupCounter('informacion_adicional', 'contador-info', 150);
</script>

</body>
</html>