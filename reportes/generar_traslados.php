<?php
session_start();
require '../config/database.php';

// 1. ZONA HORARIA
date_default_timezone_set('America/Guatemala');

if (!isset($_SESSION['user_id'])) { exit('Acceso denegado'); }
if (!isset($_SESSION['sucursal_activa'])) { exit('Sin sucursal activa'); }

$sucursal_id = $_SESSION['sucursal_activa'];
$modo = $_GET['modo'] ?? 'export';
$limit = ($modo === 'preview') ? " LIMIT 50" : "";

try {
    // 2. RECIBIR FILTROS
    $f_inicio = $_GET['f_inicio'] ?? date('Y-m-01');
    $f_fin    = $_GET['f_fin'] ?? date('Y-m-t');
    
    $patio_id  = !empty($_GET['patio_id']) ? (int)$_GET['patio_id'] : null;
    $bloque_id = !empty($_GET['bloque_id']) ? (int)$_GET['bloque_id'] : null;
    
    $cols = $_GET['cols_tras'] ?? [];

    // 3. MAPEO DE CAMPOS (Corregido y alineado)
    // Nota: 'patio_nombre' y 'bloque_numero' son OBLIGATORIOS para la agrupación azul,
    // pero no los ponemos en $titulos para que no salgan doble en las columnas.
    $mapaCampos = [
        // Datos para Agrupación (Headers Azules)
        'patio'       => "p_dest.nombre AS patio_nombre",
        'bloque'      => "b_dest.numero AS bloque_numero",
        
        // Datos de la Tabla
        'fecha'       => "le.fecha_evento AS fecha",
        'contenedor'  => "IFNULL(c.nombre, 'Eliminado') AS contenedor",
        'tipo'        => "IFNULL(tu.nombre, '-') AS tipo",
        'origen'      => "CONCAT(p_orig.nombre, ' - B', b_orig.numero, ' (Pos ', lt.posicion_anterior, ')') AS origen",
        'destino'     => "CONCAT(p_dest.nombre, ' - B', b_dest.numero, ' (Pos ', lt.posicion_nueva, ')') AS destino",
        'usuario'     => "u.username AS usuario",
        'detalle'     => "le.observaciones AS detalle"
    ];

    $titulos = [
        'fecha'      => 'Fecha Hora', 
        'contenedor' => 'Contenedor', 
        'tipo'       => 'Tipo',
        'origen'     => 'Origen (Desde)', 
        'destino'    => 'Destino (Posición)', 
        'usuario'    => 'Operador', 
        'detalle'    => 'Observaciones'
    ];

    $selectParts = []; $headers = []; $dataKeys = [];

    // Selección por defecto (Orden lógico)
    if (empty($cols)) { 
        $cols = ['fecha', 'contenedor', 'tipo', 'origen', 'destino', 'usuario', 'detalle']; 
    }

    // SIEMPRE agregamos las columnas de agrupación al SQL, aunque no se pidan en la tabla
    $selectParts[] = "p_dest.nombre AS patio_nombre";
    $selectParts[] = "b_dest.numero AS bloque_numero";

    foreach ($cols as $k) {
        if (isset($mapaCampos[$k]) && $k !== 'patio' && $k !== 'bloque') {
            $selectParts[] = $mapaCampos[$k];
            $headers[] = $titulos[$k];
            // Extraer alias
            $parts = explode(" AS ", $mapaCampos[$k]);
            $dataKeys[] = trim(end($parts));
        }
    }
    
    // Evitar duplicados en el SELECT
    $selectParts = array_unique($selectParts);
    $sqlSelect = implode(", ", $selectParts);

    // 4. CONSULTA SQL (FILTRANDO SOLO TRASLADOS REALES)
    $sql = "
        SELECT $sqlSelect
        FROM logs_traslados lt
        JOIN logs_eventos le ON lt.evento_id = le.id
        LEFT JOIN contenedores c ON le.contenedor_id = c.id
        LEFT JOIN tipos_unidad tu ON c.tipo_unidad_id = tu.id
        JOIN users u ON le.usuario_id = u.id
        
        -- ORIGEN (De donde vino)
        JOIN bloques b_orig ON lt.bloque_anterior_id = b_orig.id
        JOIN patios p_orig ON b_orig.patio_id = p_orig.id
        
        -- DESTINO (A donde fue)
        JOIN bloques b_dest ON lt.bloque_nuevo_id = b_dest.id
        JOIN patios p_dest ON b_dest.patio_id = p_dest.id
        
        WHERE le.fecha_evento BETWEEN :ini AND :fin
          AND p_dest.sucursal_id = :suc
          AND lt.bloque_anterior_id IS NOT NULL  -- <--- ESTO ELIMINA LOS INGRESOS
    ";

    $params = [
        'ini' => $f_inicio . ' 00:00:00',
        'fin' => $f_fin . ' 23:59:59',
        'suc' => $sucursal_id
    ];

    if ($patio_id) { $sql .= " AND p_dest.id = :pid "; $params['pid'] = $patio_id; }
    if ($bloque_id) { $sql .= " AND b_dest.id = :bid "; $params['bid'] = $bloque_id; }

    $sql .= " ORDER BY p_dest.nombre, b_dest.numero, le.fecha_evento DESC $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. SALIDA
    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        // EXPORTACIÓN EXCEL
        $filename = "Traslados_" . date('Ymd_His') . ".xls";
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=$filename");
        header("Pragma: no-cache");
        header("Expires: 0");
        
        echo "<meta charset='UTF-8'><style>td{border:1px solid #ccc;mso-number-format:'\@';}.group-header{background:#e0f2f1;font-weight:bold;}.table-header{background:#00695C;color:white;}</style>";
        
        // Agrupar
        $grupos = [];
        foreach ($rows as $row) {
            $p = $row['patio_nombre'] ?? '';
            $b = $row['bloque_numero'] ?? '';
            $key = ($p && $b) ? "$p - Bloque $b" : "General";
            $grupos[$key][] = $row;
        }

        foreach ($grupos as $titulo => $items) {
            echo "<table>";
            if ($titulo !== 'General') echo "<tr><td colspan='".count($headers)."' class='group-header'>$titulo</td></tr>";
            
            echo "<tr>";
            foreach ($headers as $h) echo "<td class='table-header'>$h</td>";
            echo "</tr>";
            
            foreach ($items as $row) {
                echo "<tr>";
                foreach ($dataKeys as $key) {
                    echo "<td>" . htmlspecialchars($row[$key] ?? '') . "</td>";
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