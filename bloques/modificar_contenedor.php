<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Contexto de Sucursal
if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../../index.php');
    exit;
}
$sucursal_id = $_SESSION['sucursal_activa'];
$usuario_id = $_SESSION['user_id'];

date_default_timezone_set('America/Guatemala');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../config/database.php';

// =======================================================
// 1. CARGAR DATOS ACTUALES (GET)
// =======================================================
$contenedor = null;
$modelos = [];

if (isset($_GET['nombre'])) {
    $nombreContenedor = $_GET['nombre'];
    
    // Traemos los datos actuales y sus nombres para poder comparar en el log
    $stmt = $pdo->prepare('
        SELECT c.*, 
               t.nombre as tipo_nombre, 
               m.nombre as modelo_nombre
        FROM contenedores c
        LEFT JOIN tipos_unidad t ON c.tipo_unidad_id = t.id
        LEFT JOIN modelos m ON c.modelo_id = m.id
        JOIN patios p ON c.bloque_id = (SELECT id FROM bloques WHERE id = c.bloque_id) 
                      AND p.id = (SELECT patio_id FROM bloques WHERE id = c.bloque_id)
        WHERE c.nombre = :nombre 
          AND p.sucursal_id = :sucursal
          AND c.estado_id = 1
    ');
    $stmt->execute(['nombre' => $nombreContenedor, 'sucursal' => $sucursal_id]);
    $contenedor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contenedor) {
        $_SESSION['toast_message'] = 'Contenedor no encontrado.';
        $_SESSION['toast_type'] = 'error';
        header('Location: bloques.php');
        exit;
    }

    // Cargar modelos para el select inicial
    if (!empty($contenedor['tipo_unidad_id'])) {
        $stmtMod = $pdo->prepare('SELECT id, nombre FROM modelos WHERE tipo_unidad_id = ? AND estado_id = 1');
        $stmtMod->execute([$contenedor['tipo_unidad_id']]);
        $modelos = $stmtMod->fetchAll();
    }
} else {
    header('Location: bloques.php');
    exit;
}

// =======================================================
// 2. CARGAR CATÁLOGOS
// =======================================================
$stmtTipos = $pdo->query('SELECT id, nombre FROM tipos_unidad WHERE estado_id = 1 ORDER BY nombre');
$tiposUnidad = $stmtTipos->fetchAll(PDO::FETCH_KEY_PAIR); // Array [ID => Nombre] para logs rápidos

// IDs de Tipos de Estatus (Para logs_estatus)
$stmtCat = $pdo->prepare('SELECT id FROM tipos_estatus WHERE nombre = :nombre');
$stmtCat->execute(['nombre' => 'Unidad']);
$idCatUnidad = $stmtCat->fetchColumn();

$stmtCat->execute(['nombre' => 'Estructura']);
$idCatBox = $stmtCat->fetchColumn();

// Listas para Selects
$stmtEstU = $pdo->prepare('SELECT id, nombre FROM estatus_contenedores WHERE tipo_estatus_id = ? AND estado_id = 1 ORDER BY nombre');
$stmtEstU->execute([$idCatUnidad]);
$estadosUnidad = $stmtEstU->fetchAll(PDO::FETCH_ASSOC);

$stmtEstB = $pdo->prepare('SELECT id, nombre FROM estatus_contenedores WHERE tipo_estatus_id = ? AND estado_id = 1 ORDER BY nombre');
$stmtEstB->execute([$idCatBox]);
$statusBox = $stmtEstB->fetchAll(PDO::FETCH_ASSOC);

// Estatus IPSA
$stmtIpsa = $pdo->query('SELECT id, nombre FROM estatus_ipsa WHERE estado_id = 1 ORDER BY nombre');
$listaIpsa = $stmtIpsa->fetchAll(PDO::FETCH_KEY_PAIR);

// =======================================================
// 3. PROCESAR FORMULARIO (POST)
// =======================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Datos Nuevos del Formulario
    $nuevoNombre   = trim(strtoupper($_POST['codigo_contenedor']));
    $nuevoTipo     = (int)$_POST['tipo_unidad'];
    $nuevoModelo   = (int)$_POST['modelo'];
    $nuevoEstUni   = (int)$_POST['estado_unidad'];
    $nuevaObsUni   = trim($_POST['observaciones_estado']);
    $nuevoEstBox   = (int)$_POST['status_box'];
    $nuevaObsBox   = trim($_POST['observaciones_box']);
    $nuevoIpsa     = (int)$_POST['estatus_ipsa'];
    
    $idContenedor  = $contenedor['id'];

    try {
        $pdo->beginTransaction();

        // 3.1. Validación de Duplicado (si cambió el nombre)
        if ($nuevoNombre !== $contenedor['nombre']) {
            $stmtDup = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE nombre = :nombre AND id != :id');
            $stmtDup->execute(['nombre' => $nuevoNombre, 'id' => $idContenedor]);
            if ($stmtDup->fetchColumn() > 0) throw new Exception('Ya existe otro contenedor con ese nombre.');
        }

        // 3.2. Actualizar Contenedor
        $sqlUpd = '
            UPDATE contenedores SET 
                nombre = :nom, tipo_unidad_id = :tip, modelo_id = :mod,
                estatus_unidad_id = :e_uni, observaciones_unidad = :o_uni,
                estatus_box_id = :e_box, observaciones_box = :o_box,
                estatus_ipsa_id = :ipsa
            WHERE id = :id
        ';
        $stmtUpd = $pdo->prepare($sqlUpd);
        $stmtUpd->execute([
            'nom' => $nuevoNombre, 'tip' => $nuevoTipo, 'mod' => $nuevoModelo,
            'e_uni' => $nuevoEstUni, 'o_uni' => $nuevaObsUni,
            'e_box' => $nuevoEstBox, 'o_box' => $nuevaObsBox,
            'ipsa' => $nuevoIpsa, 'id' => $idContenedor
        ]);

        // =======================================================
        // 4. AUDITORÍA INTELIGENTE (LOGS SEPARADOS)
        // =======================================================
        
        // IDs de Operación
        $opModDatos = 4;   // MODIFICACION_DATOS
        $opModEstatus = 3; // MODIFICACION_ESTATUS

        // Helper para insertar evento
        function registrarEvento($pdo, $cid, $uid, $op, $obs) {
            $stmt = $pdo->prepare('INSERT INTO logs_eventos (contenedor_id, usuario_id, tipo_operacion_id, observaciones, estado_id) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$cid, $uid, $op, $obs]);
            return $pdo->lastInsertId();
        }

        // --- A. CAMBIOS DE DATOS (Texto, Tipo, Modelo, Obs) ---
        // Se registran en logs_modificaciones
        
        // 1. Nombre
        if ($contenedor['nombre'] !== $nuevoNombre) {
            $eid = registrarEvento($pdo, $idContenedor, $usuario_id, $opModDatos, "Cambio de código de equipo");
            $stmtDet = $pdo->prepare('INSERT INTO logs_modificaciones (evento_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, 'Nombre', $contenedor['nombre'], $nuevoNombre]);
        }

        // 2. Tipo Unidad
        if ($contenedor['tipo_unidad_id'] != $nuevoTipo) {
            $nombreNuevoTipo = $tiposUnidad[$nuevoTipo] ?? 'ID:'.$nuevoTipo;
            $eid = registrarEvento($pdo, $idContenedor, $usuario_id, $opModDatos, "Cambio de Tipo de Unidad");
            $stmtDet = $pdo->prepare('INSERT INTO logs_modificaciones (evento_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, 'Tipo Unidad', $contenedor['tipo_nombre'], $nombreNuevoTipo]);
        }

        // 3. Modelo
        if ($contenedor['modelo_id'] != $nuevoModelo) {
            // Buscar nombre del nuevo modelo para el log
            $stmtM = $pdo->prepare("SELECT nombre FROM modelos WHERE id = ?");
            $stmtM->execute([$nuevoModelo]);
            $nombreNuevoModelo = $stmtM->fetchColumn();

            $eid = registrarEvento($pdo, $idContenedor, $usuario_id, $opModDatos, "Cambio de Modelo");
            $stmtDet = $pdo->prepare('INSERT INTO logs_modificaciones (evento_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, 'Modelo', $contenedor['modelo_nombre'], $nombreNuevoModelo]);
        }

        // 4. Observaciones (Unidad)
        if ($contenedor['observaciones_unidad'] !== $nuevaObsUni) {
            $eid = registrarEvento($pdo, $idContenedor, $usuario_id, $opModDatos, "Actualización Obs. Unidad");
            $stmtDet = $pdo->prepare('INSERT INTO logs_modificaciones (evento_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, 'Obs. Unidad', $contenedor['observaciones_unidad'], $nuevaObsUni]);
        }

        // 5. Observaciones (Box)
        if ($contenedor['observaciones_box'] !== $nuevaObsBox) {
            $eid = registrarEvento($pdo, $idContenedor, $usuario_id, $opModDatos, "Actualización Obs. Box");
            $stmtDet = $pdo->prepare('INSERT INTO logs_modificaciones (evento_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, 'Obs. Box', $contenedor['observaciones_box'], $nuevaObsBox]);
        }

        // --- B. CAMBIO DE ESTATUS IPSA ---
        // Se registra en logs_ipsa
        if ($contenedor['estatus_ipsa_id'] != $nuevoIpsa) {
            $eid = registrarEvento($pdo, $idContenedor, $usuario_id, $opModEstatus, "Cambio de Estatus IPSA");
            $stmtDet = $pdo->prepare('INSERT INTO logs_ipsa (evento_id, estatus_anterior_id, estatus_nuevo_id) VALUES (?, ?, ?)');
            $stmtDet->execute([$eid, $contenedor['estatus_ipsa_id'], $nuevoIpsa]);
        }

        // --- C. CAMBIOS DE ESTATUS OPERATIVOS (Unidad/Box) ---
        // Se registran en logs_estatus (Usando los IDs de tipos_estatus: 1=Unidad, 2=Estructura)

        // 1. Estatus Unidad
        if ($contenedor['estatus_unidad_id'] != $nuevoEstUni) {
            $eid = registrarEvento($pdo, $idContenedor, $usuario_id, $opModEstatus, "Cambio Estatus de Unidad");
            // Nota: Aquí usamos los IDs normalizados (tipo_estatus_id = 1 para Unidad)
            $stmtDet = $pdo->prepare('INSERT INTO logs_estatus (evento_id, tipo_estatus_id, valor_anterior_id, valor_nuevo_id) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, $idCatUnidad, $contenedor['estatus_unidad_id'], $nuevoEstUni]);
        }

        // 2. Estatus Box
        if ($contenedor['estatus_box_id'] != $nuevoEstBox) {
            $eid = registrarEvento($pdo, $idContenedor, $usuario_id, $opModEstatus, "Cambio Estatus de Estructura");
            // Nota: Aquí usamos los IDs normalizados (tipo_estatus_id = 2 para Estructura)
            $stmtDet = $pdo->prepare('INSERT INTO logs_estatus (evento_id, tipo_estatus_id, valor_anterior_id, valor_nuevo_id) VALUES (?, ?, ?, ?)');
            $stmtDet->execute([$eid, $idCatBox, $contenedor['estatus_box_id'], $nuevoEstBox]);
        }

        $pdo->commit();

        $_SESSION['toast_message'] = 'Contenedor actualizado correctamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: bloques.php');
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['toast_message'] = 'Error: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header("Location: modificar_contenedor.php?nombre=" . urlencode($nombreContenedor));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modificar Contenedor</title>
    <link rel="stylesheet" href="../styles/parametros/bloques/modificar_contenedor.css">
</head>
<body>
    <header>
        <div id="toast-container" class="toast-container"></div>
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
    <h1>Modificar Contenedor</h1>

    <form action="" method="post">
        <div class="form-group">
            <label for="codigo_contenedor">Código del Contenedor:</label>
            <input type="text" id="codigo_contenedor" name="codigo_contenedor" 
                   value="<?php echo htmlspecialchars($contenedor['nombre']); ?>" required>
            <span class="error-message" id="contenedor-error">Ya existe.</span>
            <span class="valid-message" id="contenedor-valid">Disponible.</span>
        </div>

        <div class="form-group">
            <label for="tipo_unidad">Tipo de Unidad:</label>
            <select id="tipo_unidad" name="tipo_unidad" required>
                <?php foreach ($tiposUnidad as $id => $nombre): ?>
                    <option value="<?php echo $id; ?>" <?php echo ($id == $contenedor['tipo_unidad_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($nombre); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="modelo">Modelo:</label>
            <select id="modelo" name="modelo" required>
                <?php foreach ($modelos as $mod): ?>
                    <option value="<?php echo $mod['id']; ?>" <?php echo ($mod['id'] == $contenedor['modelo_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($mod['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="estado_unidad">Estado de la Unidad:</label>
            <select id="estado_unidad" name="estado_unidad" required>
                <?php foreach ($estadosUnidad as $est): ?>
                    <option value="<?php echo $est['id']; ?>" <?php echo ($est['id'] == $contenedor['estatus_unidad_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($est['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="observaciones_estado">Observaciones Unidad:</label>
            <textarea id="observaciones_estado" name="observaciones_estado"><?php echo htmlspecialchars($contenedor['observaciones_unidad']); ?></textarea>
        </div>

        <div class="form-group">
            <label for="status_box">Status Box:</label>
            <select id="status_box" name="status_box" required>
                <?php foreach ($statusBox as $est): ?>
                    <option value="<?php echo $est['id']; ?>" <?php echo ($est['id'] == $contenedor['estatus_box_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($est['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="observaciones_box">Observaciones Box:</label>
            <textarea id="observaciones_box" name="observaciones_box"><?php echo htmlspecialchars($contenedor['observaciones_box']); ?></textarea>
        </div>
        
        <!-- Estatus IPSA (Manual) -->
        <div class="form-group" style="background-color:#e8f5e9; border:1px solid #81c784; padding:10px; border-radius:5px;">
            <label for="estatus_ipsa" style="color:#2e7d32;">Estatus IPSA:</label>
            <select id="estatus_ipsa" name="estatus_ipsa" required>
                <?php foreach ($listaIpsa as $id => $nombre): ?>
                    <option value="<?php echo $id; ?>" <?php echo ($id == $contenedor['estatus_ipsa_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($nombre); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Hidden -->
        <input type="hidden" name="bloque" value="<?php echo $contenedor['bloque_id']; ?>">
        <input type="hidden" name="posicion" value="<?php echo $contenedor['posicion']; ?>">

        <div class="buttons">
            <a href="bloques.php" class="regresar-button">
                <img src="../imagenes/regresar.png" alt="Regresar">
                <span>Regresar</span>
            </a>
            <button type="submit" class="guardar-button">
                <img src="../imagenes/Save.png" alt="Guardar">
                <span>Guardar</span>
            </button>
        </div>
    </form>
    </main>
    <footer>
        <p>&copy; IPSA Web - 2025</p>
    </footer>

    <script>
    // Validación nombre (AJAX)
    document.getElementById('codigo_contenedor').addEventListener('input', function() {
        const nombre = this.value.toUpperCase();
        const nombreOriginal = '<?php echo $nombreContenedor; ?>';
        
        const err = document.getElementById('contenedor-error');
        const ok = document.getElementById('contenedor-valid');
        const input = this;

        input.classList.remove('error', 'valid');
        err.style.display = 'none';
        ok.style.display = 'none';
        
        if (nombre === nombreOriginal || nombre === '') return;

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '../ingresos/verificar_contenedor.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                if (xhr.responseText === 'exists') {
                    input.classList.add('error');
                    err.style.display = 'inline';
                } else {
                    input.classList.add('valid');
                    ok.style.display = 'inline';
                }
            }
        };
        xhr.send('nombre=' + encodeURIComponent(nombre));
    });

    // Carga Modelos
    document.getElementById('tipo_unidad').addEventListener('change', function() {
        const id = this.value;
        const select = document.getElementById('modelo');
        if(!id) return;
        
        fetch(`../ingresos/obtener_modelos.php?tipo_unidad_id=${id}`)
            .then(r => r.json())
            .then(data => {
                let opts = '';
                data.forEach(m => opts += `<option value="${m.id}">${m.nombre}</option>`);
                select.innerHTML = opts;
            });
    });
    
    // Toast
    function showToast(msg, type='success'){
        const c = document.getElementById('toast-container');
        const t = document.createElement('div');
        t.className = `toast ${type}`;
        t.textContent = msg;
        c.appendChild(t);
        void t.offsetWidth; t.classList.add('show');
        setTimeout(()=>{ t.classList.remove('show'); setTimeout(()=>t.remove(),300); }, 4000);
    }

    <?php if (isset($_SESSION['toast_message'])): ?>
    document.addEventListener('DOMContentLoaded', function () {
      showToast(
        <?php echo json_encode($_SESSION['toast_message']); ?>,
        <?php echo json_encode($_SESSION['toast_type'] ?? 'success'); ?>
      );
    });
    <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
    <?php endif; ?>
    </script>
</body>
</html>