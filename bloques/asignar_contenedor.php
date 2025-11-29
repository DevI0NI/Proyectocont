<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require '../config/database.php';

// Solo Roles permitidos pueden asignar (Mecánico y PYP y Admins)
// Rol 3 (Usuario Básico) no debería poder.
if (!in_array($_SESSION['rol_id'], [1, 2, 4, 5])) {
    $_SESSION['toast_message'] = 'No tienes permisos para asignar contenedores.';
    $_SESSION['toast_type'] = 'error';
    header('Location: contenedor.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contenedorId = isset($_POST['contenedor_id']) ? (int)$_POST['contenedor_id'] : 0;
    $usuarioId = $_SESSION['user_id'];

    if ($contenedorId <= 0) {
        $_SESSION['toast_message'] = 'ID de contenedor inválido.';
        $_SESSION['toast_type'] = 'error';
        header('Location: contenedor.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. VERIFICAR QUE ESTÉ DISPONIBLE Y NO ASIGNADO AÚN
        // Buscamos info del contenedor y si ya tiene registro en pyp_auditoria
        $sqlCheck = "
            SELECT c.id, c.estatus_ipsa_id, pa.contenedor_id as ya_asignado
            FROM contenedores c
            LEFT JOIN pyp_auditoria pa ON c.id = pa.contenedor_id
            WHERE c.id = :id AND c.estado_id = 1
        ";
        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->execute(['id' => $contenedorId]);
        $info = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$info) {
            throw new Exception("El contenedor no existe.");
        }

        // Regla: Debe estar 'Disponible' (ID 1) en IPSA
        if ($info['estatus_ipsa_id'] != 1) {
            throw new Exception("El contenedor no está Disponible (IPSA). No se puede asignar.");
        }

        // Regla: No debe estar asignado ya
        if ($info['ya_asignado']) {
            throw new Exception("Este contenedor ya está asignado/auditado.");
        }

        // 2. INSERTAR EN PYP_AUDITORIA (Esto cuenta como la asignación)
        $stmtIns = $pdo->prepare("
            INSERT INTO pyp_auditoria (contenedor_id, estatus_pyp, fecha_registro, usuario_id, estado_id)
            VALUES (:cid, 'Asignado', NOW(), :uid, 1)
        ");
        $stmtIns->execute(['cid' => $contenedorId, 'uid' => $usuarioId]);

        // 3. LOG DE EVENTOS (Auditoría General)
        // Usamos Tipo Operación 4 (MODIFICACION_DATOS) o podrías crear uno nuevo 'ASIGNACION'
        $stmtLog = $pdo->prepare("
            INSERT INTO logs_eventos (contenedor_id, usuario_id, tipo_operacion_id, observaciones, estado_id)
            VALUES (:cid, :uid, 4, 'Contenedor asignado para auditoría PYP', 1)
        ");
        $stmtLog->execute(['cid' => $contenedorId, 'uid' => $usuarioId]);

        $pdo->commit();

        $_SESSION['toast_message'] = 'Contenedor asignado correctamente.';
        $_SESSION['toast_type'] = 'success';

        // Redirigimos de vuelta manteniendo el contexto si es posible, o al general
        $redirect = 'contenedor.php';
        if (isset($_SERVER['HTTP_REFERER'])) {
            // Intentar volver al bloque específico si veníamos de ahí
            $parts = parse_url($_SERVER['HTTP_REFERER']);
            if (isset($parts['query'])) {
                $redirect .= '?' . $parts['query'];
            }
        }
        header('Location: ' . $redirect);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['toast_message'] = 'Error: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header('Location: contenedor.php');
        exit;
    }
}
?>