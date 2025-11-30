<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// 1. SEGURIDAD DE CONTEXTO
if (!isset($_SESSION['sucursal_activa'])) {
    $_SESSION['toast_message'] = 'Error: Seleccione una sucursal.';
    $_SESSION['toast_type'] = 'error';
    header('Location: ../index.php');
    exit;
}
$sucursal_id = $_SESSION['sucursal_activa'];
$usuario_id = $_SESSION['user_id'];

require '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Capturar datos del formulario
    $contenedorId = isset($_POST['contenedor_id']) ? (int)$_POST['contenedor_id'] : 0;
    $observaciones = isset($_POST['observaciones']) ? trim($_POST['observaciones']) : '';

    if ($contenedorId <= 0) {
        $_SESSION['toast_message'] = 'Error: Contenedor no válido.';
        $_SESSION['toast_type'] = 'error';
        header('Location: despachos.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 2. VALIDACIÓN RIGUROSA (Anti-hackeo)
        // Volvemos a verificar todo lo que el JS verificó, pero en el servidor.
        $stmtCheck = $pdo->prepare("
            SELECT 
                c.id, c.nombre, c.bloque_id, c.posicion, c.estatus_ipsa_id,
                pa.estatus_pyp
            FROM contenedores c
            JOIN bloques b ON c.bloque_id = b.id
            JOIN patios p ON b.patio_id = p.id
            LEFT JOIN pyp_auditoria pa ON c.id = pa.contenedor_id
            WHERE c.id = :id 
              AND c.estado_id = 1 
              AND p.sucursal_id = :sucursal
        ");
        $stmtCheck->execute(['id' => $contenedorId, 'sucursal' => $sucursal_id]);
        $c = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        // Validaciones de Negocio
        if (!$c) { throw new Exception("El contenedor no existe o no pertenece a esta sucursal."); }
        
        // a) IPSA Disponible (ID 1)
        if ($c['estatus_ipsa_id'] != 1) { throw new Exception("Error: El contenedor no tiene estatus IPSA 'Disponible'."); }
        
        // b) PYP Asignado (No puede estar vacío)
        if (empty($c['estatus_pyp'])) { throw new Exception("Error: El contenedor no ha sido auditado por PYP."); }

        // c) Bloqueo Físico
        $stmtBlock = $pdo->prepare("
            SELECT COUNT(*) FROM contenedores 
            WHERE bloque_id = :bid 
              AND posicion < :pos 
              AND estado_id = 1
        ");
        $stmtBlock->execute(['bid' => $c['bloque_id'], 'pos' => $c['posicion']]);
        
        if ($stmtBlock->fetchColumn() > 0) {
            throw new Exception("Error Físico: Hay contenedores delante de este equipo. Debe moverlos primero.");
        }

        // 3. REGISTRAR EVENTO (LOG) - ARCHIVADO DIRECTAMENTE (Estado 2)
        // Buscamos ID operación RETIRO (o lo creamos si no existe, asumo ID 5)
        $stmtOp = $pdo->prepare("SELECT id FROM tipos_operacion WHERE nombre = 'RETIRO'");
        $stmtOp->execute();
        $opId = $stmtOp->fetchColumn() ?: 5; 

        $obsLog = "Despacho exitoso. Observaciones: " . $observaciones;
        
        // El log se inserta con estado_id = 2 (Para que el limpiador pueda borrarlo junto con el contenedor)
        $stmtLog = $pdo->prepare("
            INSERT INTO logs_eventos (contenedor_id, usuario_id, tipo_operacion_id, observaciones, estado_id) 
            VALUES (:cid, :uid, :op, :obs, 2)
        ");
        $stmtLog->execute([
            'cid' => $contenedorId, 
            'uid' => $usuario_id, 
            'op' => $opId, 
            'obs' => $obsLog
        ]);

        // 4. ARCHIVAR LOGS HISTÓRICOS (Pasar a Estado 2)
        // "Apagamos" todos los eventos previos de este contenedor para que el limpiador pueda borrarlos
        $stmtArchiveLogs = $pdo->prepare("UPDATE logs_eventos SET estado_id = 2 WHERE contenedor_id = :cid");
        $stmtArchiveLogs->execute(['cid' => $contenedorId]);

        // 5. ARCHIVAR AUDITORÍA PYP (Pasar a Estado 2)
        // También archivamos la auditoría asociada
        $stmtArchivePyp = $pdo->prepare("UPDATE pyp_auditoria SET estado_id = 2 WHERE contenedor_id = :cid");
        $stmtArchivePyp->execute(['cid' => $contenedorId]);

        // 6. ACTUALIZAR CAPACIDAD DEL BLOQUE
        // Liberamos un espacio
        $stmtCap = $pdo->prepare("UPDATE bloques SET capacidad_actual = capacidad_actual - 1 WHERE id = :bid");
        $stmtCap->execute(['bid' => $c['bloque_id']]);

        // 7. APAGAR CONTENEDOR (SOFT DELETE)
        // Pasamos a estado_id = 2. 
        $stmtDel = $pdo->prepare("UPDATE contenedores SET estado_id = 2 WHERE id = :id");
        $stmtDel->execute(['id' => $contenedorId]);

        $pdo->commit();

        $_SESSION['toast_message'] = "Contenedor " . $c['nombre'] . " despachado y archivado correctamente.";
        $_SESSION['toast_type'] = "success";
        header('Location: despachos.php');
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['toast_message'] = $e->getMessage();
        $_SESSION['toast_type'] = "error";
        header('Location: despachos.php');
        exit;
    }
} else {
    // Acceso directo no permitido
    header('Location: despachos.php');
    exit;
}
?>