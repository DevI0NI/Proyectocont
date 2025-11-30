<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// 1. CONTEXTO DE SUCURSAL
if (!isset($_SESSION['sucursal_activa'])) {
    header('Location: ../index.php');
    exit;
}
$sucursal_id = $_SESSION['sucursal_activa'];
$usuario_id  = $_SESSION['user_id'];

date_default_timezone_set('America/Guatemala');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../config/database.php';

$error_message = '';

// =======================================================
// 1. CARGA DE DATOS MAESTROS
// =======================================================

$stmtPatios = $pdo->prepare('SELECT id, nombre FROM patios WHERE estado_id = 1 AND sucursal_id = :sucursal ORDER BY nombre ASC');
$stmtPatios->execute(['sucursal' => $sucursal_id]);
$patios = $stmtPatios->fetchAll(PDO::FETCH_ASSOC);

$stmtTiposUnidad = $pdo->query('SELECT id, nombre FROM tipos_unidad WHERE estado_id = 1 ORDER BY nombre ASC');
$tiposUnidad = $stmtTiposUnidad->fetchAll(PDO::FETCH_ASSOC);

$stmtCat = $pdo->prepare('SELECT id FROM tipos_estatus WHERE nombre = :nombre');
$stmtCat->execute(['nombre' => 'Unidad']);
$idCatUnidad = $stmtCat->fetchColumn();
$stmtEstUnidad = $pdo->prepare('SELECT id, nombre FROM estatus_contenedores WHERE tipo_estatus_id = :id AND estado_id = 1 ORDER BY nombre ASC');
$stmtEstUnidad->execute(['id' => $idCatUnidad]);
$estadosUnidad = $stmtEstUnidad->fetchAll(PDO::FETCH_ASSOC);

$stmtCat->execute(['nombre' => 'Estructura']);
$idCatBox = $stmtCat->fetchColumn();
$stmtEstBox = $pdo->prepare('SELECT id, nombre FROM estatus_contenedores WHERE tipo_estatus_id = :id AND estado_id = 1 ORDER BY nombre ASC');
$stmtEstBox->execute(['id' => $idCatBox]);
$statusBox = $stmtEstBox->fetchAll(PDO::FETCH_ASSOC);

$stmtIpsaList = $pdo->query("SELECT id, nombre FROM estatus_ipsa WHERE estado_id = 1 ORDER BY nombre ASC");
$listaEstatusIpsa = $stmtIpsaList->fetchAll(PDO::FETCH_ASSOC);

// =======================================================
// 2. PROCESAMIENTO (POST)
// =======================================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fechaIngreso        = $_POST['fecha_ingreso'] . ' ' . date('H:i:s');
    $codigoContenedor    = trim(strtoupper($_POST['codigo_contenedor']));
    $tipoUnidad          = (int)$_POST['tipo_unidad'];
    $modelo              = (int)$_POST['modelo'];
    $estadoUnidadId      = (int)$_POST['estado_unidad'];
    $observacionesEstado = trim($_POST['observaciones_estado']);
    $statusBoxId         = (int)$_POST['status_box'];
    $observacionesBox    = trim($_POST['observaciones_box']);
    $estatusIpsaId       = (int)$_POST['estatus_ipsa']; 
    $bloqueId            = (int)$_POST['bloque'];
    $posicion            = (int)$_POST['posicion'];

    try {
        $pdo->beginTransaction();

        // A. Validar Sucursal del Bloque
        $stmtCheckSuc = $pdo->prepare('SELECT p.sucursal_id FROM bloques b JOIN patios p ON b.patio_id = p.id WHERE b.id = :id');
        $stmtCheckSuc->execute(['id' => $bloqueId]);
        if ($stmtCheckSuc->fetchColumn() != $sucursal_id) {
            throw new Exception('Error de seguridad: El bloque no pertenece a la sucursal activa.');
        }

        // B. Validar Capacidad
        $stmtCap = $pdo->prepare('SELECT capacidad_actual, capacidad_maxima FROM bloques WHERE id = :id');
        $stmtCap->execute(['id' => $bloqueId]);
        $cap = $stmtCap->fetch(PDO::FETCH_ASSOC);
        if ($cap['capacidad_actual'] >= $cap['capacidad_maxima']) {
            throw new Exception("El bloque seleccionado está lleno.");
        }
        
        // C. Validar Posición Específica
        $stmtPos = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE bloque_id = :id AND posicion = :pos AND estado_id = 1');
        $stmtPos->execute(['id' => $bloqueId, 'pos' => $posicion]);
        if ($stmtPos->fetchColumn() > 0) {
            throw new Exception("La posición $posicion ya está ocupada por otro contenedor activo.");
        }

        // D. LÓGICA DE REINGRESO / NUEVO
        $stmtExist = $pdo->prepare('SELECT id, estado_id FROM contenedores WHERE nombre = :nombre');
        $stmtExist->execute(['nombre' => $codigoContenedor]);
        $contenedorExistente = $stmtExist->fetch(PDO::FETCH_ASSOC);

        $contenedorId = 0;
        $esReingreso = false;

        if ($contenedorExistente) {
            // Si existe y está ACTIVO -> Error
            if ($contenedorExistente['estado_id'] == 1) {
                throw new Exception('El número de equipo ya existe y está activo en el patio.');
            } 
            // Si existe y está INACTIVO -> REINGRESO
            else {
                $esReingreso = true;
                $contenedorId = $contenedorExistente['id'];
                
                // 1. UPDATE del Contenedor (Reactivar)
                $sqlUpdate = '
                    UPDATE contenedores SET 
                        bloque_id = :blk, 
                        posicion = :pos, 
                        tipo_unidad_id = :tip, 
                        modelo_id = :mod,
                        estatus_unidad_id = :e_uni, 
                        observaciones_unidad = :o_uni, 
                        estatus_box_id = :e_box, 
                        observaciones_box = :o_box,
                        estatus_ipsa_id = :ipsa, 
                        fecha_ingreso = :fec, 
                        estado_id = 1 
                    WHERE id = :id
                ';
                $stmtUpd = $pdo->prepare($sqlUpdate);
                $stmtUpd->execute([
                    'blk' => $bloqueId, 'pos' => $posicion, 'tip' => $tipoUnidad, 'mod' => $modelo,
                    'e_uni' => $estadoUnidadId, 'o_uni' => $observacionesEstado,
                    'e_box' => $statusBoxId, 'o_box' => $observacionesBox,
                    'ipsa' => $estatusIpsaId, 'fec' => $fechaIngreso, 'id' => $contenedorId
                ]);

                // 2. LIMPIEZA CRÍTICA: BORRAR AUDITORÍA PYP ANTERIOR
                // Al reingresar, el proceso de PYP debe empezar de cero.
                $stmtDelPyp = $pdo->prepare('DELETE FROM pyp_auditoria WHERE contenedor_id = :cid');
                $stmtDelPyp->execute(['cid' => $contenedorId]);
            }
        } else {
            // NUEVO (INSERT)
            $sqlIns = '
                INSERT INTO contenedores (
                    nombre, bloque_id, posicion, tipo_unidad_id, modelo_id,
                    estatus_unidad_id, observaciones_unidad, estatus_box_id, observaciones_box,
                    estatus_ipsa_id, fecha_ingreso, estado_id
                ) VALUES (
                    :nom, :blk, :pos, :tip, :mod,
                    :e_uni, :o_uni, :e_box, :o_box,
                    :ipsa, :fec, 1
                )
            ';
            $stmtIns = $pdo->prepare($sqlIns);
            $stmtIns->execute([
                'nom' => $codigoContenedor, 'blk' => $bloqueId, 'pos' => $posicion,
                'tip' => $tipoUnidad, 'mod' => $modelo,
                'e_uni' => $estadoUnidadId, 'o_uni' => $observacionesEstado,
                'e_box' => $statusBoxId, 'o_box' => $observacionesBox,
                'ipsa' => $estatusIpsaId, 'fec' => $fechaIngreso
            ]);
            $contenedorId = $pdo->lastInsertId();
        }

        // E. LOGS (Auditoría)
        $stmtOp = $pdo->prepare('SELECT id FROM tipos_operacion WHERE nombre = "INGRESO"');
        $stmtOp->execute();
        $opId = $stmtOp->fetchColumn() ?: 1; 

        $logMensaje = $esReingreso ? "Reingreso de equipo (Historial previo archivado)." : "Ingreso inicial (Equipo nuevo).";

        // Log Maestro
        $stmtLog = $pdo->prepare('INSERT INTO logs_eventos (contenedor_id, usuario_id, tipo_operacion_id, observaciones, estado_id) VALUES (?, ?, ?, ?, 1)');
        $stmtLog->execute([$contenedorId, $usuario_id, $opId, $logMensaje]);
        $eventoId = $pdo->lastInsertId();

        // Log Detalle (Ubicación)
        $stmtDetalle = $pdo->prepare('INSERT INTO logs_traslados (evento_id, bloque_nuevo_id, posicion_nueva) VALUES (?, ?, ?)');
        $stmtDetalle->execute([$eventoId, $bloqueId, $posicion]);

        // F. ACTUALIZAR CAPACIDAD DEL BLOQUE
        // El reingreso o ingreso nuevo OCUPA un espacio
        $stmtInc = $pdo->prepare('UPDATE bloques SET capacidad_actual = capacidad_actual + 1 WHERE id = :bid');
        $stmtInc->execute(['bid' => $bloqueId]);

        $pdo->commit();

        $_SESSION['toast_message'] = $esReingreso ? 'Contenedor reingresado y reseteado correctamente.' : 'Contenedor ingresado correctamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: ingresos.php');
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo Ingreso</title>
    <link rel="stylesheet" href="../styles/ingresos.css/ingresos.css">
    <style>
        #toast-container { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; width: 90%; max-width: 400px; pointer-events: none; }
        .toast { padding: 15px 20px; border-radius: 8px; color: #fff; font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,0.15); opacity: 0; transform: translateY(-20px); transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 10px; }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.error { background-color: #dc3545; border-left: 5px solid #bd2130; }
        .toast.success { background-color: #28a745; border-left: 5px solid #1e7e34; }
        
        input.error { border-color: #ff4d4d !important; background-color: #fff5f5; }
        input.valid { border-color: #28a745 !important; }
        .error-message { color: #ff4d4d; display: none; font-size: 0.85em; font-weight: bold; }
        .valid-message { color: #28a745; display: none; font-size: 0.85em; font-weight: bold; }
    </style>
</head>
<body>
<div id="toast-container"></div>

<header>
  <div class="header-left">
    <a href="../index.php">
      <img src="../imagenes/IPSA.png" alt="Logo" class="logo">
      <span class="brand-name">IPROPORTSA</span>
    </a>
  </div>
  <div class="header-right">
    <span class="welcome-label">Bienvenido,</span>
    <span class="username"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
    <a href="../logout.php" class="logout-button">Cerrar sesión</a>
  </div>
</header>

<main>
    <h1>Nuevo Ingreso</h1>
    
    <?php if ($error_message): ?>
        <p style="color: red; text-align: center; font-weight: bold;"><?php echo htmlspecialchars($error_message); ?></p>
    <?php endif; ?>
    
    <form action="ingresos.php" method="post">
        <div class="form-group">
            <label for="fecha_ingreso">Fecha de Ingreso:</label>
            <input type="date" id="fecha_ingreso" name="fecha_ingreso" required value="<?php echo isset($_POST['fecha_ingreso']) ? htmlspecialchars($_POST['fecha_ingreso']) : ''; ?>">
        </div>
        <div class="form-group">
            <label for="codigo_contenedor">Número de Equipo:</label>
            <input type="text" id="codigo_contenedor" name="codigo_contenedor" required maxlength="11" pattern="^[A-Z0-9]{11}$" title="Debe tener exactamente 11 caracteres, solo mayúsculas y números" value="<?php echo isset($_POST['codigo_contenedor']) ? htmlspecialchars($_POST['codigo_contenedor']) : ''; ?>">
            <span class="error-message" id="contenedor-error">Este contenedor ya existe.</span>
            <span class="valid-message" id="contenedor-valid">Contenedor disponible.</span>
        </div>
        
        <div class="form-group">
            <label for="tipo_unidad">Tipo de Unidad:</label>
            <select id="tipo_unidad" name="tipo_unidad" required>
                <option value="">Seleccione un tipo</option>
                <?php foreach ($tiposUnidad as $tipo): ?>
                    <option value="<?php echo $tipo['id']; ?>" <?php echo (isset($_POST['tipo_unidad']) && $_POST['tipo_unidad'] == $tipo['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($tipo['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="modelo">Modelo:</label>
            <select id="modelo" name="modelo" required>
                <option value="">Seleccione un modelo</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="estado_unidad">Estado de Unidad:</label>
            <select id="estado_unidad" name="estado_unidad" required onchange="calcularIpsa()">
                <option value="">Seleccione un estado</option>
                <?php foreach ($estadosUnidad as $estado): ?>
                    <option value="<?php echo $estado['id']; ?>" data-nombre="<?php echo htmlspecialchars($estado['nombre']); ?>">
                        <?php echo htmlspecialchars($estado['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="observaciones_estado">Observaciones (Unidad):</label>
            <textarea id="observaciones_estado" name="observaciones_estado"><?php echo isset($_POST['observaciones_estado']) ? htmlspecialchars($_POST['observaciones_estado']) : ''; ?></textarea>
        </div>
        
        <div class="form-group">
            <label for="status_box">Status de Box:</label>
            <select id="status_box" name="status_box" required onchange="calcularIpsa()">
                <option value="">Seleccione un status</option>
                <?php foreach ($statusBox as $status): ?>
                    <option value="<?php echo $status['id']; ?>" data-nombre="<?php echo htmlspecialchars($status['nombre']); ?>">
                        <?php echo htmlspecialchars($status['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="observaciones_box">Observaciones (Box):</label>
            <textarea id="observaciones_box" name="observaciones_box"><?php echo isset($_POST['observaciones_box']) ? htmlspecialchars($_POST['observaciones_box']) : ''; ?></textarea>
        </div>

        <div class="form-group">
            <label for="estatus_ipsa">Estatus IPSA:</label>
            <select id="estatus_ipsa" name="estatus_ipsa" required style="border: 2px solid #00695C;">
                <?php foreach ($listaEstatusIpsa as $ipsa): ?>
                    <option value="<?php echo $ipsa['id']; ?>" data-nombre="<?php echo htmlspecialchars($ipsa['nombre']); ?>">
                        <?php echo htmlspecialchars($ipsa['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="display:block; margin-top:5px; color:#666;">El sistema sugiere un estatus, pero puedes cambiarlo.</small>
        </div>

        <div class="ubicacion-container">
            <h2>Ubicación</h2>
            <div class="ubicacion-fields">
                <select id="patio" name="patio" required>
                    <option value="">Seleccione un patio</option>
                    <?php foreach ($patios as $patio): ?>
                        <option value="<?php echo $patio['id']; ?>" <?php echo (isset($_POST['patio']) && $_POST['patio'] == $patio['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($patio['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select id="bloque" name="bloque" required disabled>
                    <option value="">Seleccione un bloque</option>
                </select>
                
                <input type="text" id="posicion" name="posicion" placeholder="Posición" readonly required>
            </div>
        </div>

        <div class="buttons">
            <a href="../index.php" class="regresar-button">
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
// Cálculo sugerido IPSA
function calcularIpsa() {
    const unidadSelect = document.getElementById('estado_unidad');
    const boxSelect = document.getElementById('status_box');
    const ipsaSelect = document.getElementById('estatus_ipsa');

    const nomUnidad = unidadSelect.options[unidadSelect.selectedIndex]?.dataset.nombre || '';
    const nomBox = boxSelect.options[boxSelect.selectedIndex]?.dataset.nombre || '';

    let sugerido = 'Pendiente';
    if (nomUnidad === 'DMG' || nomBox === 'DMG') {
        sugerido = 'OOS';
    } else if (nomUnidad === 'OK' && (nomBox === 'OK' || nomBox.includes('Reparación Mínima'))) {
        sugerido = 'Disponible';
    } else if (nomUnidad.includes('Pendiente') || nomBox.includes('Pendiente')) {
        sugerido = 'Pendiente';
    }

    for (let i = 0; i < ipsaSelect.options.length; i++) {
        if (ipsaSelect.options[i].dataset.nombre === sugerido) {
            ipsaSelect.selectedIndex = i;
            break;
        }
    }
}

// Configuración inicial
document.addEventListener('DOMContentLoaded', function () {
    const fechaInput = document.getElementById('fecha_ingreso');
    if (fechaInput && !fechaInput.value) { 
        const hoy = new Date();
        const yyyy = hoy.getFullYear();
        const mm = String(hoy.getMonth() + 1).padStart(2, '0');
        const dd = String(hoy.getDate()).padStart(2, '0');
        fechaInput.value = `${yyyy}-${mm}-${dd}`;
    }
    // Repoblar si hay error
    <?php if(isset($_POST['tipo_unidad'])): ?> document.getElementById('tipo_unidad').dispatchEvent(new Event('change')); <?php endif; ?>
    <?php if(isset($_POST['patio'])): ?> document.getElementById('patio').dispatchEvent(new Event('change')); <?php endif; ?>
});

// Cargar Modelos
document.getElementById('tipo_unidad').addEventListener('change', function () {
    const tipoId = this.value;
    const select = document.getElementById('modelo');
    const prev = "<?php echo $_POST['modelo'] ?? ''; ?>";
    
    select.innerHTML = '<option value="">Cargando...</option>';
    if(tipoId){
        fetch(`obtener_modelos.php?tipo_unidad_id=${tipoId}`)
        .then(r => r.json())
        .then(data => {
            select.innerHTML = '<option value="">Seleccione un modelo</option>';
            data.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.text = item.nombre;
                if(item.id == prev) opt.selected = true;
                select.appendChild(opt);
            });
        });
    } else {
        select.innerHTML = '<option value="">Seleccione un modelo</option>';
    }
});

// Cargar Bloques
document.getElementById('patio').addEventListener('change', function () {
    const patioId = this.value;
    const select = document.getElementById('bloque');
    const prev = "<?php echo $_POST['bloque'] ?? ''; ?>";
    
    select.innerHTML = '<option value="">Cargando...</option>';
    select.disabled = true;
    document.getElementById('posicion').value = '';

    if(patioId){
        fetch(`obtener_bloques.php?patio_id=${patioId}`)
        .then(r => r.json())
        .then(data => {
            select.innerHTML = '<option value="">Seleccione un bloque</option>';
            if(data.length > 0){
                select.disabled = false;
                data.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item.id;
                    const libres = item.capacidad_maxima - item.capacidad_actual;
                    opt.text = `Bloque ${item.numero} (Libres: ${libres})`;
                    if(item.id == prev) opt.selected = true;
                    select.appendChild(opt);
                });
                if(prev) select.dispatchEvent(new Event('change'));
            } else {
                select.innerHTML = '<option value="">Sin bloques disponibles</option>';
            }
        });
    }
});

// Cargar Posición
document.getElementById('bloque').addEventListener('change', function () {
    const bloqueId = this.value;
    const input = document.getElementById('posicion');
    input.value = 'Calculando...';
    if(bloqueId){
        fetch(`obtener_posicion.php?bloque_id=${bloqueId}`)
        .then(r => r.json())
        .then(data => {
            if(data.posicion){
                input.value = data.posicion;
            } else if(data.error){
                input.value = '';
                alert(data.error);
                this.value = '';
            }
        });
    } else {
        input.value = '';
    }
});

// Validación Contenedor
document.getElementById('codigo_contenedor').addEventListener('input', function () {
    this.value = this.value.toUpperCase();
    const val = this.value;
    const regex = /^[A-Z0-9]{11}$/;
    const err = document.getElementById('contenedor-error');
    const ok = document.getElementById('contenedor-valid');
    
    this.classList.remove('error', 'valid');
    err.style.display = 'none';
    ok.style.display = 'none';

    if(regex.test(val)){
        fetch('verificar_contenedor.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'nombre=' + encodeURIComponent(val)
        })
        .then(r => r.text())
        .then(res => {
            if(res === 'exists'){
                this.classList.add('error');
                err.textContent = 'Este contenedor ya está activo en patio.';
                err.style.display = 'inline';
            } else {
                this.classList.add('valid');
                ok.textContent = (res === 'available') ? 'Nuevo ingreso (Disponible).' : 'Reingreso (Disponible).';
                ok.style.display = 'inline';
            }
        });
    }
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