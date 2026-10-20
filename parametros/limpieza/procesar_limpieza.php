<?php
session_start();
require '../../config/database.php';

echo json_encode(['error' => 'Esta herramienta de mantenimiento no esta disponible en el sistema.']);
exit;

date_default_timezone_set('America/Guatemala');

$accion = $_POST['accion'] ?? '';
$inicio = ($_POST['inicio'] ?? '') . ' 00:00:00';
$fin    = ($_POST['fin'] ?? '') . ' 23:59:59';

if (!$accion || !$_POST['inicio']) {
    echo json_encode(['error' => 'Datos incompletos']);
    exit;
}

try {
    $stmtOp = $pdo->query("SELECT id FROM tipos_operacion WHERE nombre = 'RETIRO'");
    $opRetiro = $stmtOp->fetchColumn() ?: 5;

    // ===================================================
    // MODO ANÁLISIS (CONTAR TODO)
    // ===================================================
    if ($accion === 'analizar') {
        
        // 1. Contenedores Candidatos (IDs)
        // Usamos una tabla temporal o subconsulta para no repetir lógica
        $subQuery = "
            SELECT c.id 
            FROM contenedores c
            JOIN logs_eventos l ON c.id = l.contenedor_id
            WHERE c.estado_id = 2 
              AND l.tipo_operacion_id = :op
              AND l.fecha_evento BETWEEN :ini AND :fin
        ";
        $params = ['op' => $opRetiro, 'ini' => $inicio, 'fin' => $fin];

        // A. Contenedores
        $stmtC = $pdo->prepare("SELECT COUNT(DISTINCT id) FROM ($subQuery) AS tmp");
        $stmtC->execute($params);
        $countC = $stmtC->fetchColumn();

        // Si no hay contenedores, todo es 0
        if ($countC == 0) {
            echo json_encode([
                'contenedores' => 0, 'logs_eventos' => 0, 'logs_traslados' => 0, 
                'pyp_auditoria' => 0, 'logs_estatus' => 0, 'logs_ipsa' => 0, 'logs_modificaciones' => 0
            ]);
            exit;
        }

        // B. Logs Eventos (Total de eventos de esos contenedores)
        $stmtL = $pdo->prepare("SELECT COUNT(*) FROM logs_eventos WHERE contenedor_id IN ($subQuery)");
        $stmtL->execute($params);
        $countL = $stmtL->fetchColumn();

        // C. Pyp Auditoria
        $stmtP = $pdo->prepare("SELECT COUNT(*) FROM pyp_auditoria WHERE contenedor_id IN ($subQuery)");
        $stmtP->execute($params);
        $countP = $stmtP->fetchColumn();

        // D. Logs Estatus (Relacionados a eventos de esos contenedores)
        $stmtLE = $pdo->prepare("SELECT COUNT(*) FROM logs_estatus le JOIN logs_eventos l ON le.evento_id = l.id WHERE l.contenedor_id IN ($subQuery)");
        $stmtLE->execute($params);
        $countLE = $stmtLE->fetchColumn();

        // E. Logs IPSA
        $stmtLI = $pdo->prepare("SELECT COUNT(*) FROM logs_ipsa li JOIN logs_eventos l ON li.evento_id = l.id WHERE l.contenedor_id IN ($subQuery)");
        $stmtLI->execute($params);
        $countLI = $stmtLI->fetchColumn();

        // F. Logs Modificaciones
        $stmtLM = $pdo->prepare("SELECT COUNT(*) FROM logs_modificaciones lm JOIN logs_eventos l ON lm.evento_id = l.id WHERE l.contenedor_id IN ($subQuery)");
        $stmtLM->execute($params);
        $countLM = $stmtLM->fetchColumn();

        // G. Logs Traslados
        $stmtLT = $pdo->prepare("SELECT COUNT(*) FROM logs_traslados lt JOIN logs_eventos l ON lt.evento_id = l.id WHERE l.contenedor_id IN ($subQuery)");
        $stmtLT->execute($params);
        $countLT = $stmtLT->fetchColumn();

        echo json_encode([
            'contenedores' => $countC,
            'logs_eventos' => $countL,
            'pyp_auditoria' => $countP,
            'logs_estatus' => $countLE,
            'logs_ipsa' => $countLI,
            'logs_modificaciones' => $countLM,
            'logs_traslados' => $countLT
        ]);

    } 
    // ===================================================
    // MODO ELIMINAR
    // ===================================================
    elseif ($accion === 'eliminar') {
        // Al borrar el contenedor padre, MySQL (InnoDB) borra automáticamente 
        // los hijos en las otras 6 tablas gracias al ON DELETE CASCADE.
        $sql = "
            DELETE c
            FROM contenedores c
            INNER JOIN logs_eventos l ON c.id = l.contenedor_id
            WHERE c.estado_id = 2
              AND l.tipo_operacion_id = :op
              AND l.fecha_evento BETWEEN :ini AND :fin
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['op' => $opRetiro, 'ini' => $inicio, 'fin' => $fin]);
        
        echo json_encode(['success' => true, 'eliminados' => $stmt->rowCount()]);
    }

} catch (Exception $e) {
    echo json_encode(['error' => 'Error BD: ' . $e->getMessage()]);
}
?>
