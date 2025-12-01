<?php
session_start();
require '../config/database.php';

// 1. ZONA HORARIA OBLIGATORIA
date_default_timezone_set('America/Guatemala');

if (!isset($_SESSION['user_id'])) { exit('Acceso denegado'); }
if (!isset($_SESSION['sucursal_activa'])) { exit('Sin sucursal activa'); }

$sucursal_id = $_SESSION['sucursal_activa'];
$modo = $_GET['modo'] ?? 'export';
$limit = ($modo === 'preview') ? " LIMIT 50" : "";

try {
    // 2. RECIBIR DATOS (Aquí faltaba recibir patio y bloque)
    $f_inicio  = $_GET['f_inicio'] ?? date('Y-m-01');
    $f_fin     = $_GET['f_fin'] ?? date('Y-m-t');
    
    $patio_id  = !empty($_GET['patio_id']) ? (int)$_GET['patio_id'] : null;
    $bloque_id = !empty($_GET['bloque_id']) ? (int)$_GET['bloque_id'] : null;
    
    $cols = $_GET['cols_mov'] ?? [];

    // 3. MAPEO
    $mapaCampos = [
        'patio'      => "p.nombre AS patio_nombre",
        'bloque'     => "b.numero AS bloque_numero",
        'fecha'      => "l.fecha_evento AS fecha_hora",
        'operacion'  => "top.nombre AS operacion",
        'contenedor' => "IFNULL(c.nombre, 'Eliminado') AS contenedor",
        'tipo'       => "IFNULL(tu.nombre, '-') AS tipo",
        'modelo'     => "IFNULL(m.nombre, '-') AS modelo",
        'usuario'    => "u.username AS usuario",
        'detalle'    => "l.observaciones AS detalle"
    ];

    $titulos = [
        'patio'      => 'Patio', 'bloque' => 'Bloque', 'fecha' => 'Fecha Hora',
        'operacion'  => 'Operacion', 'contenedor' => 'Contenedor',
        'tipo'       => 'Tipo', 'modelo'     => 'Modelo',
        'usuario'    => 'Usuario', 'detalle'    => 'Detalle'
    ];

    $selectParts = []; $headers = []; $dataKeys = [];

    if (empty($cols)) { 
        $cols = ['patio', 'bloque', 'contenedor', 'operacion', 'usuario', 'detalle', 'fecha']; 
    }

    foreach ($cols as $k) {
        if (isset($mapaCampos[$k])) {
            $selectParts[] = $mapaCampos[$k];
            $headers[] = $titulos[$k];
            $parts = explode(" AS ", $mapaCampos[$k]);
            $dataKeys[] = trim(end($parts));
        }
    }
    $sqlSelect = implode(", ", $selectParts);

    // 4. PREPARAR SQL BASE
    $sql = "
        SELECT $sqlSelect
        FROM logs_eventos l
        JOIN tipos_operacion top ON l.tipo_operacion_id = top.id
        JOIN users u ON l.usuario_id = u.id
        LEFT JOIN contenedores c ON l.contenedor_id = c.id
        LEFT JOIN tipos_unidad tu ON c.tipo_unidad_id = tu.id
        LEFT JOIN modelos m ON c.modelo_id = m.id
        JOIN bloques b ON c.bloque_id = b.id
        JOIN patios p ON b.patio_id = p.id
        WHERE l.fecha_evento BETWEEN :ini AND :fin
          AND p.sucursal_id = :suc
    ";

    $params = [
        'ini' => $f_inicio . ' 00:00:00',
        'fin' => $f_fin . ' 23:59:59',
        'suc' => $sucursal_id
    ];

    // --- FILTROS DE PATIO Y BLOQUE (ESTO FALTABA) ---
    if ($patio_id) {
        $sql .= " AND p.id = :pid ";
        $params['pid'] = $patio_id;
    }
    if ($bloque_id) {
        $sql .= " AND b.id = :bid ";
        $params['bid'] = $bloque_id;
    }
    // ------------------------------------------------

    $sql .= " ORDER BY l.fecha_evento DESC $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. SALIDA
    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        $filename = "Bitacora_" . date('Ymd_His') . ".xls";
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=$filename");
        header("Pragma: no-cache");
        header("Expires: 0");
        
        echo "<meta charset='UTF-8'><style>td{border:1px solid #ccc;mso-number-format:'\@';}.group-header{background:#e0f2f1;font-weight:bold;}.table-header{background:#00695C;color:white;}</style>";
        
        $grupos = [];
        foreach ($rows as $row) {
            $p = $row['patio_nombre'] ?? '';
            $b = $row['bloque_numero'] ?? '';
            $key = ($p && $b) ? "$p - Bloque $b" : "General";
            $grupos[$key][] = $row;
        }

        foreach ($grupos as $titulo => $items) {
            echo "<table>";
            if ($titulo !== 'General') {
                echo "<tr><td colspan='".count($headers)."' class='group-header'>$titulo</td></tr>";
            }
            
            echo "<tr>";
            foreach ($headers as $h) { 
                if ($h !== 'Patio' && $h !== 'Bloque') echo "<td class='table-header'>$h</td>"; 
            }
            echo "</tr>";
            
            foreach ($items as $row) {
                echo "<tr>";
                foreach ($dataKeys as $i => $key) {
                    if ($headers[$i] !== 'Patio' && $headers[$i] !== 'Bloque') {
                        echo "<td>" . htmlspecialchars($row[$key] ?? '') . "</td>";
                    }
                }
                echo "</tr>";
            }
            echo "<tr><td colspan='10' style='border:none; height:15px;'></td></tr>";
            echo "</table>";
        }
    }

} catch (Exception $e) {
    if ($modo === 'preview') echo json_encode(['error' => $e->getMessage()]);
    else echo "Error: " . $e->getMessage();
}
?>