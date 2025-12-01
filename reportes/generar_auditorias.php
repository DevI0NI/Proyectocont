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
    // 2. RECIBIR FILTROS (Fechas, Patio, Bloque)
    $f_inicio = $_GET['f_inicio'] ?? date('Y-m-01');
    $f_fin    = $_GET['f_fin'] ?? date('Y-m-t');
    
    $patio_id  = !empty($_GET['patio_id']) ? (int)$_GET['patio_id'] : null;
    $bloque_id = !empty($_GET['bloque_id']) ? (int)$_GET['bloque_id'] : null;
    
    $cols = $_GET['cols_aud'] ?? [];

    // 3. MAPEO (Incluyendo patio y bloque para agrupar)
    $mapaCampos = [
        'patio'       => "p.nombre AS patio_nombre",
        'bloque'      => "b.numero AS bloque_numero",
        'contenedor'  => "c.nombre AS contenedor_nombre",
        'estatus_pyp' => "pa.estatus_pyp AS estatus",
        'info'        => "pa.informacion_adicional AS info",
        'fecha'       => "pa.fecha_registro AS fecha",
        'usuario'     => "u.username AS auditor"
    ];

    $titulos = [
        'patio'       => 'Patio',
        'bloque'      => 'Bloque',
        'contenedor'  => 'Contenedor', 
        'estatus_pyp' => 'Estatus', 
        'info'        => 'Detalle', 
        'fecha'       => 'Fecha', 
        'usuario'     => 'Auditor'
    ];

    $selectParts = []; $headers = []; $dataKeys = [];

    // Selección por defecto
    if (empty($cols)) { 
        $cols = ['patio', 'bloque', 'contenedor', 'estatus_pyp', 'info', 'fecha', 'usuario']; 
    }

    foreach ($cols as $k) {
        if (isset($mapaCampos[$k])) {
            $selectParts[] = $mapaCampos[$k];
            $headers[] = $titulos[$k];
            // Extraer alias para usar en el array asociativo
            $parts = explode(" AS ", $mapaCampos[$k]);
            $dataKeys[] = trim(end($parts));
        }
    }
    $sqlSelect = implode(", ", $selectParts);

    // 4. CONSULTA SQL (Con filtros de Patio, Bloque y Sucursal)
    $sql = "
        SELECT $sqlSelect
        FROM pyp_auditoria pa
        JOIN contenedores c ON pa.contenedor_id = c.id
        JOIN bloques b ON c.bloque_id = b.id
        JOIN patios p ON b.patio_id = p.id
        LEFT JOIN users u ON pa.usuario_id = u.id
        WHERE pa.fecha_registro BETWEEN :ini AND :fin
          AND p.sucursal_id = :suc
    ";

    $params = [
        'ini' => $f_inicio . ' 00:00:00',
        'fin' => $f_fin . ' 23:59:59',
        'suc' => $sucursal_id
    ];

    // Aplicar filtros opcionales
    if ($patio_id) {
        $sql .= " AND p.id = :pid ";
        $params['pid'] = $patio_id;
    }
    if ($bloque_id) {
        $sql .= " AND b.id = :bid ";
        $params['bid'] = $bloque_id;
    }

    $sql .= " ORDER BY pa.fecha_registro DESC $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. SALIDA
    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        // EXPORTACIÓN A EXCEL AGRUPADO
        $filename = "Auditorias_" . date('Ymd_His') . ".xls";
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=$filename");
        header("Pragma: no-cache");
        header("Expires: 0");
        
        echo "<meta charset='UTF-8'><style>td{border:1px solid #ccc;mso-number-format:'\@';}.group-header{background:#e0f2f1;font-weight:bold;}.table-header{background:#00695C;color:white;}</style>";
        
        // Agrupar datos
        $grupos = [];
        foreach ($rows as $row) {
            $p = $row['patio_nombre'] ?? '';
            $b = $row['bloque_numero'] ?? '';
            $key = ($p && $b) ? "$p - Bloque $b" : "General";
            $grupos[$key][] = $row;
        }

        // Renderizar Tablas
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