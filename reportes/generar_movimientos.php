<?php
session_start();
require '../config/database.php';

if (!isset($_SESSION['user_id'])) { exit('Acceso denegado'); }

$modo = $_GET['modo'] ?? 'export';
$limit = ($modo === 'preview') ? " LIMIT 50" : "";

try {
    $f_inicio = $_GET['f_inicio'] . ' 00:00:00';
    $f_fin    = $_GET['f_fin'] . ' 23:59:59';
    $colsSel  = $_GET['cols_mov'] ?? [];

    $mapa = [
        'fecha'      => 'l.fecha_evento AS fecha_evt',
        'operacion'  => 't.nombre AS op_nombre',
        'contenedor' => 'c.nombre AS cont_nombre', // Puede ser null si se borró físico, pero el log persiste
        'usuario'    => 'u.username AS user_name',
        'detalle'    => 'l.observaciones AS obs_val'
    ];

    $selectParts = [];
    $headers = [];
    
    if (empty($colsSel)) { 
        $selectParts[] = 'l.fecha_evento'; 
        $headers[] = 'Fecha'; 
    } else {
        foreach ($colsSel as $k) {
            if(isset($mapa[$k])) { 
                $selectParts[] = $mapa[$k]; 
                $headers[] = ucfirst($k); 
            }
        }
    }

    $sqlSelect = implode(", ", $selectParts);

    // Nota: Los logs no tienen sucursal_id directo, mostramos todo lo que el usuario (si es global) o filtrado por usuario si fuera necesario.
    // Asumimos visión global por ahora o filtrado por fecha.
    $sql = "
        SELECT $sqlSelect
        FROM logs_eventos l
        JOIN tipos_operacion t ON l.tipo_operacion_id = t.id
        JOIN users u ON l.usuario_id = u.id
        LEFT JOIN contenedores c ON l.contenedor_id = c.id
        WHERE l.fecha_evento BETWEEN :ini AND :fin
        ORDER BY l.id DESC
        $limit
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['ini' => $f_inicio, 'fin' => $f_fin]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        $filename = "Movimientos_" . date('Ymd') . ".xls";
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=$filename");
        
        echo "<meta charset='UTF-8'><table border='1'><thead><tr>";
        foreach ($headers as $h) echo "<th style='background:#00695C;color:white;'>$h</th>";
        echo "</tr></thead><tbody>";
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($row as $val) echo "<td>" . htmlspecialchars($val) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }

} catch (Exception $e) {
    if ($modo === 'preview') echo json_encode(['error' => $e->getMessage()]);
    else echo "Error: " . $e->getMessage();
}
?>