<?php
session_start();
require '../config/database.php';

// 1. ZONA HORARIA
date_default_timezone_set('America/Guatemala');

if (!isset($_SESSION['user_id'])) { exit('Acceso denegado'); }

$modo = $_GET['modo'] ?? 'export';
$contenedor_id = !empty($_GET['contenedor_id']) ? (int)$_GET['contenedor_id'] : null;

try {
    if (!$contenedor_id) {
        throw new Exception("Debe seleccionar un contenedor de la lista.");
    }

    // A. Obtener datos del contenedor (Nombre actual)
    $stmtC = $pdo->prepare("SELECT nombre FROM contenedores WHERE id = :id");
    $stmtC->execute(['id' => $contenedor_id]);
    $nombreContenedor = $stmtC->fetchColumn();

    if (!$nombreContenedor) {
        // Si fue borrado físicamente (raro), intentamos buscarlo en logs
        $nombreContenedor = "Contenedor ID: $contenedor_id (Eliminado)";
    }

    // B. Consulta Histórica (Eventos + Traslados)
    // Hacemos JOIN a logs_traslados para enriquecer la info si fue un movimiento interno
    $sql = "
        SELECT 
            l.fecha_evento AS fecha,
            top.nombre AS operacion,
            u.username AS usuario,
            l.observaciones AS detalle_base,
            
            -- Datos específicos si fue traslado
            p_ant.nombre AS patio_ant, b_ant.numero AS bloq_ant, lt.posicion_anterior,
            p_nue.nombre AS patio_nue, b_nue.numero AS bloq_nue, lt.posicion_nueva
            
        FROM logs_eventos l
        JOIN tipos_operacion top ON l.tipo_operacion_id = top.id
        JOIN users u ON l.usuario_id = u.id
        
        -- Join Traslados (Solo coincidirá si el evento fue un traslado)
        LEFT JOIN logs_traslados lt ON l.id = lt.evento_id
        LEFT JOIN bloques b_ant ON lt.bloque_anterior_id = b_ant.id
        LEFT JOIN patios p_ant ON b_ant.patio_id = p_ant.id
        LEFT JOIN bloques b_nue ON lt.bloque_nuevo_id = b_nue.id
        LEFT JOIN patios p_nue ON b_nue.patio_id = p_nue.id
        
        WHERE l.contenedor_id = :cid
        ORDER BY l.fecha_evento ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['cid' => $contenedor_id]);
    $rows_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // C. Procesar datos para que sean legibles
    $rows = [];
    foreach ($rows_raw as $r) {
        $detalle = $r['detalle_base'];

        // Si es traslado, construimos una frase mejor
        if ($r['patio_nue']) { // Si hay datos de destino, es un traslado
            $origen = ($r['patio_ant']) ? "{$r['patio_ant']} B{$r['bloq_ant']} P{$r['posicion_anterior']}" : "Ingreso/Externo";
            $destino = "{$r['patio_nue']} B{$r['bloq_nue']} P{$r['posicion_nueva']}";
            $detalle .= " [Movido de: $origen -> A: $destino]";
        }

        $rows[] = [
            'fecha'     => $r['fecha'],
            'operacion' => $r['operacion'],
            'usuario'   => $r['usuario'],
            'detalle'   => $detalle
        ];
    }

    $headers = ['Fecha Hora', 'Operación', 'Usuario', 'Detalle Histórico'];

    // D. SALIDA
    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        // EXCEL
        $filename = "Historial_" . $nombreContenedor . "_" . date('Ymd') . ".xls";
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=$filename");
        header("Pragma: no-cache");
        header("Expires: 0");
        
        echo "<meta charset='UTF-8'>";
        echo "<h3>Historial Completo: $nombreContenedor</h3>";
        echo "<table border='1'>";
        echo "<tr style='background-color:#00695C; color:white;'>";
        foreach ($headers as $h) echo "<th>$h</th>";
        echo "</tr>";
        foreach ($rows as $row) {
            echo "<tr>";
            echo "<td>{$row['fecha']}</td>";
            echo "<td>{$row['operacion']}</td>";
            echo "<td>{$row['usuario']}</td>";
            echo "<td>{$row['detalle']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

} catch (Exception $e) {
    if ($modo === 'preview') echo json_encode(['error' => $e->getMessage()]);
    else echo "Error: " . $e->getMessage();
}
?>