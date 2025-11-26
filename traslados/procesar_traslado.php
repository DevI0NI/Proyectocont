<?php
session_start();

// 1. Seguridad de Sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// 2. Contexto de Sucursal
if (!isset($_SESSION['sucursal_activa'])) {
    $_SESSION['toast_message'] = 'Error de sesión. Seleccione una sucursal.';
    $_SESSION['toast_type'] = 'error';
    header('Location: ../index.php');
    exit;
}
$sucursal_id = $_SESSION['sucursal_activa'];
$usuario_id = $_SESSION['user_id'];

require '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contenedorId  = $_POST['contenedor_id'];
    $nuevoBloqueId = $_POST['nuevo_bloque_id'];
    $nuevaPosicion = $_POST['nueva_posicion'];

    try {
        $pdo->beginTransaction();

        // ---------------------------------------------------------
        // A. VALIDAR CONTENEDOR ORIGEN (Y obtener nombres para el Log)
        // ---------------------------------------------------------
        $stmtOld = $pdo->prepare('
            SELECT 
                c.bloque_id, 
                c.posicion, 
                p.sucursal_id,
                p.nombre AS patio_nombre,    -- Nombre del Patio Origen
                b.numero AS bloque_numero    -- Número del Bloque Origen
            FROM contenedores c
            JOIN bloques b ON c.bloque_id = b.id
            JOIN patios p ON b.patio_id = p.id
            WHERE c.id = :id AND c.estado_id = 1
        ');
        $stmtOld->execute(['id' => $contenedorId]);
        $infoActual = $stmtOld->fetch(PDO::FETCH_ASSOC);

        if (!$infoActual) {
            throw new Exception("El contenedor no existe o no está activo.");
        }
        if ($infoActual['sucursal_id'] != $sucursal_id) {
            throw new Exception("Error de seguridad: El contenedor no pertenece a la sucursal activa.");
        }

        $bloqueAnteriorId = $infoActual['bloque_id'];
        $posicionAnterior = $infoActual['posicion'];

        // ---------------------------------------------------------
        // B. VALIDAR APILAMIENTO
        // ---------------------------------------------------------
        $stmtStack = $pdo->prepare('
            SELECT COUNT(*) FROM contenedores 
            WHERE bloque_id = :bid AND posicion < :pos AND estado_id = 1
        ');
        $stmtStack->execute(['bid' => $bloqueAnteriorId, 'pos' => $posicionAnterior]);
        
        if ($stmtStack->fetchColumn() > 0) {
            throw new Exception("Movimiento bloqueado: Hay contenedores obstruyendo la salida.");
        }

        // ---------------------------------------------------------
        // C. VALIDAR BLOQUE DESTINO (Y obtener nombres para el Log)
        // ---------------------------------------------------------
        $stmtDest = $pdo->prepare('
            SELECT 
                b.capacidad_actual, 
                b.capacidad_maxima, 
                p.sucursal_id,
                p.nombre AS patio_nombre,  -- Nombre del Patio Destino
                b.numero AS bloque_numero  -- Número del Bloque Destino
            FROM bloques b
            JOIN patios p ON b.patio_id = p.id
            WHERE b.id = :id AND b.estado_id = 1
        ');
        $stmtDest->execute(['id' => $nuevoBloqueId]);
        $infoDestino = $stmtDest->fetch(PDO::FETCH_ASSOC);

        if (!$infoDestino) throw new Exception("El bloque destino no existe.");
        
        if ($infoDestino['sucursal_id'] != $sucursal_id) {
            throw new Exception("Error: No puedes trasladar a un bloque de otra sucursal.");
        }

        if ($infoDestino['capacidad_actual'] >= $infoDestino['capacidad_maxima']) {
            throw new Exception("El bloque destino está lleno.");
        }

        // Validar posición libre
        $stmtOcu = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE bloque_id = :b AND posicion = :p AND estado_id = 1');
        $stmtOcu->execute(['b' => $nuevoBloqueId, 'p' => $nuevaPosicion]);
        if ($stmtOcu->fetchColumn() > 0) {
            throw new Exception("La posición $nuevaPosicion ya está ocupada.");
        }

        // ---------------------------------------------------------
        // D. EJECUTAR TRASLADO
        // ---------------------------------------------------------
        
        $stmtUpd = $pdo->prepare('UPDATE contenedores SET bloque_id = :nb, posicion = :np WHERE id = :id');
        $stmtUpd->execute(['nb' => $nuevoBloqueId, 'np' => $nuevaPosicion, 'id' => $contenedorId]);

        // Actualizar Capacidades
        $stmtDec = $pdo->prepare('UPDATE bloques SET capacidad_actual = capacidad_actual - 1 WHERE id = :id');
        $stmtDec->execute(['id' => $bloqueAnteriorId]);
        
        $stmtInc = $pdo->prepare('UPDATE bloques SET capacidad_actual = capacidad_actual + 1 WHERE id = :id');
        $stmtInc->execute(['id' => $nuevoBloqueId]);

        // ---------------------------------------------------------
        // E. AUDITORÍA DETALLADA
        // ---------------------------------------------------------
        
        $stmtOp = $pdo->prepare('SELECT id FROM tipos_operacion WHERE nombre = "TRASLADO"');
        $stmtOp->execute();
        $opId = $stmtOp->fetchColumn() ?: 2;

        // Construir mensaje detallado para humanos
        $observacionDetallada = sprintf(
            "Traslado: De %s Bloque %s (Pos %s) -> A %s Bloque %s (Pos %s)",
            $infoActual['patio_nombre'],
            $infoActual['bloque_numero'],
            $posicionAnterior,
            $infoDestino['patio_nombre'],
            $infoDestino['bloque_numero'],
            $nuevaPosicion
        );

        // Insertar Evento
        $stmtLog = $pdo->prepare('
            INSERT INTO logs_eventos (contenedor_id, usuario_id, tipo_operacion_id, observaciones, estado_id) 
            VALUES (:cid, :uid, :op, :obs, 1)
        ');
        $stmtLog->execute([
            'cid' => $contenedorId, 
            'uid' => $usuario_id, 
            'op' => $opId, 
            'obs' => $observacionDetallada
        ]);
        $eventoId = $pdo->lastInsertId();

        // Insertar Detalle Técnico (IDs para reportes)
        $stmtDet = $pdo->prepare('
            INSERT INTO logs_traslados (evento_id, bloque_anterior_id, posicion_anterior, bloque_nuevo_id, posicion_nueva)
            VALUES (:eid, :bold, :pold, :bnew, :pnew)
        ');
        $stmtDet->execute([
            'eid' => $eventoId,
            'bold' => $bloqueAnteriorId,
            'pold' => $posicionAnterior,
            'bnew' => $nuevoBloqueId,
            'pnew' => $nuevaPosicion
        ]);

        $pdo->commit();

        $_SESSION['toast_message'] = 'Contenedor trasladado exitosamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: traslados.php');
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['toast_message'] = 'Error: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header('Location: traslados.php');
        exit;
    }
}
?>