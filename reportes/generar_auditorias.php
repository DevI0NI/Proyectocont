<?php
session_start();
require '../config/database.php';

if (!isset($_SESSION['user_id'])) { exit('Acceso denegado'); }

$sucursal_id = $_SESSION['sucursal_activa'];
$modo = $_GET['modo'] ?? 'export';
$limit = ($modo === 'preview') ? " LIMIT 50" : "";

try {
    $f_inicio = $_GET['f_inicio'] . ' 00:00:00';
    $f_fin    = $_GET['f_fin'] . ' 23:59:59';
    $colsSel  = $_GET['cols_aud'] ?? [];

    $mapa = [
        'contenedor'  => 'c.nombre AS cont_nombre',
        'estatus_pyp' => 'pa.estatus_pyp AS pyp_est',
        'info'        => 'pa.informacion_adicional AS pyp_info',
        'fecha'       => 'pa.fecha_registro AS pyp_fecha',
        'usuario'     => 'u.username AS auditor'
    ];

    $selectParts = []; $headers = [];
    
    if (empty($colsSel)) { 
        $selectParts[] = 'pa.estatus_pyp'; 
        $headers[] = 'Estatus'; 
    } else {
        foreach ($colsSel as $k) {
            if(isset($mapa[$k])) { 
                $selectParts[] = $mapa[$k]; 
                $titulos = ['contenedor'=>'Contenedor', 'estatus_pyp'=>'Estatus', 'info'=>'Detalle', 'fecha'=>'Fecha', 'usuario'=>'Auditor'];
                $headers[] = $titulos[$k] ?? ucfirst($k); 
            }
        }
    }
    
    $sqlSelect = implode(", ", $selectParts);

    $sql = "
    SELECT $sqlSelect
    FROM pyp_auditoria pa
    LEFT JOIN contenedores c ON pa.contenedor_id = c.id
    LEFT JOIN users u ON pa.usuario_id = u.id
    JOIN bloques b ON c.bloque_id = b.id
    JOIN patios p ON b.patio_id = p.id
    WHERE pa.fecha_registro BETWEEN :ini AND :fin
        AND p.sucursal_id = :suc
    $limit
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['ini' => $f_inicio, 'fin' => $f_fin, 'suc' => $sucursal_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        $filename = "Auditorias_" . date('Ymd') . ".xls";
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