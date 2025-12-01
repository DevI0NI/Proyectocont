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
    $fechaInicio = $_GET['f_inicio'] ?? date('Y-m-01');
    $fechaFin    = $_GET['f_fin'] ?? date('Y-m-t');
    $patio_id    = !empty($_GET['patio_id']) ? (int)$_GET['patio_id'] : null;
    $bloque_id   = !empty($_GET['bloque_id']) ? (int)$_GET['bloque_id'] : null;

    $cols = $_GET['cols_desp'] ?? []; 

    // 3. MAPEO DE CAMPOS
    $mapaCampos = [
        'patio'          => "IFNULL(p.nombre, 'Sin Ubicación') AS patio_nombre",
        'bloque'         => "IFNULL(b.numero, '0') AS bloque_numero",
        'posicion'       => "IFNULL(c.posicion, '-') AS posicion",
        'contenedor'     => "IFNULL(c.nombre, 'Eliminado') AS contenedor",
        'tipo'           => "IFNULL(tu.nombre, '-') AS tipo",
        'modelo'         => "IFNULL(m.nombre, '-') AS modelo",
        'estatus_unidad' => "IFNULL(eu.nombre, '-') AS est_unidad",
        'estatus_box'    => "IFNULL(eb.nombre, '-') AS est_box",
        'estatus_ipsa'   => "IFNULL(ei.nombre, '-') AS est_ipsa",
        'estatus_pyp'    => "IFNULL(pa.estatus_pyp, 'Sin Asignar') AS est_pyp",
        'fecha_ingreso'  => "c.fecha_ingreso AS fecha_ingreso",
        'fecha_despacho' => "l.fecha_evento AS fecha_despacho",
        'dias_estadia'   => "DATEDIFF(l.fecha_evento, c.fecha_ingreso) AS dias_estadia",
        'usuario'        => "u.username AS usuario",
        'observaciones'  => "l.observaciones AS observaciones"
    ];

    $titulos = [
        'patio'          => 'Patio', 
        'bloque'         => 'Bloque', 
        'posicion'       => 'Posición',
        'contenedor'     => 'Contenedor', 
        'tipo'           => 'Tipo', 
        'modelo'         => 'Modelo',
        'estatus_unidad' => 'Est. Unidad', 
        'estatus_box'    => 'Est. Box',
        'estatus_ipsa'   => 'Est. IPSA', 
        'estatus_pyp'    => 'Est. PYP',
        'fecha_ingreso'  => 'Ingreso', 
        'fecha_despacho' => 'Despacho',
        'dias_estadia'   => 'Días Estadía',
        'usuario'        => 'Despachador',
        'observaciones'  => 'Observaciones'
    ];

    $selectParts = []; $headers = []; $dataKeys = [];

    if (empty($cols)) { 
        $cols = ['patio', 'bloque', 'contenedor', 'tipo', 'estatus_unidad', 'fecha_despacho', 'dias_estadia', 'usuario', 'observaciones']; 
    }

    // SIEMPRE incluir las columnas de agrupación
    $selectParts[] = $mapaCampos['patio'];
    $selectParts[] = $mapaCampos['bloque'];

    foreach ($cols as $k) {
        if (isset($mapaCampos[$k])) {
            if ($k !== 'patio' && $k !== 'bloque') {
                $selectParts[] = $mapaCampos[$k];
            }
            $headers[] = $titulos[$k];
            $parts = explode(" AS ", $mapaCampos[$k]);
            $dataKeys[] = trim(end($parts));
        }
    }
    
    $selectParts = array_unique($selectParts);
    $sqlSelect = implode(", ", $selectParts);

    // 4. CONSULTA SQL
    $sql = "
        SELECT $sqlSelect
        FROM logs_eventos l
        LEFT JOIN contenedores c ON l.contenedor_id = c.id
        LEFT JOIN tipos_unidad tu ON c.tipo_unidad_id = tu.id
        LEFT JOIN modelos m ON c.modelo_id = m.id
        LEFT JOIN users u ON l.usuario_id = u.id
        LEFT JOIN estatus_contenedores eu ON c.estatus_unidad_id = eu.id
        LEFT JOIN estatus_contenedores eb ON c.estatus_box_id = eb.id
        LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id
        LEFT JOIN pyp_auditoria pa ON c.id = pa.contenedor_id
        LEFT JOIN bloques b ON c.bloque_id = b.id
        LEFT JOIN patios p ON b.patio_id = p.id
        WHERE l.tipo_operacion_id = 5 
          AND l.fecha_evento BETWEEN :ini AND :fin
          AND u.sucursal_id = :suc
    ";

    $params = [
        'ini' => $fechaInicio . ' 00:00:00',
        'fin' => $fechaFin . ' 23:59:59',
        'suc' => $sucursal_id
    ];

    if ($patio_id) { $sql .= " AND p.id = :pid "; $params['pid'] = $patio_id; }
    if ($bloque_id) { $sql .= " AND b.id = :bid "; $params['bid'] = $bloque_id; }

    $sql .= " ORDER BY l.fecha_evento DESC $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. SALIDA
    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        // EXPORTACIÓN EXCEL PROFESIONAL
        $filename = "Despachos_" . date('Ymd_His') . ".xls";
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=$filename");
        header("Pragma: no-cache");
        header("Expires: 0");
        
        echo "<meta charset='UTF-8'>";
        echo "<style>
                .group-header { background-color: #e0f2f1; color: #00695C; font-weight: bold; border: 1px solid #ccc; font-size: 14px; text-align: left; }
                .table-header { background-color: #00695C; color: white; font-weight: bold; border: 1px solid #000; }
                .total-row { background-color: #FFFDE7; font-weight: bold; }
                td { border: 1px solid #ccc; mso-number-format:'\@'; } /* Texto por defecto */
                .number { mso-number-format:'0'; } /* Números sin error */
              </style>";
        
        // Agrupar
        $grupos = [];
        foreach ($rows as $row) {
            $p = $row['patio_nombre'] ?? 'Sin Ubicación';
            $b = $row['bloque_numero'] ?? '0';
            $key = "$p - Bloque $b";
            $grupos[$key][] = $row;
        }

        foreach ($grupos as $titulo => $items) {
            echo "<table>";
            // Título de grupo
            echo "<tr><td colspan='".count($headers)."' class='group-header'>$titulo</td></tr>";
            
            // Encabezados
            echo "<tr>";
            foreach ($headers as $h) { 
                if ($h !== 'Patio' && $h !== 'Bloque') echo "<td class='table-header'>$h</td>"; 
            }
            echo "</tr>";
            
            // Filas
            foreach ($items as $row) {
                echo "<tr>";
                foreach ($dataKeys as $i => $key) {
                    if ($headers[$i] !== 'Patio' && $headers[$i] !== 'Bloque') {
                        // Aplicar clase number solo si es dias_estadia para evitar el triangulo verde
                        $clase = ($key === 'dias_estadia') ? "class='number'" : "";
                        echo "<td $clase>" . htmlspecialchars($row[$key] ?? '') . "</td>";
                    }
                }
                echo "</tr>";
            }
            
            // === TOTALIZADOR (Pegado a la izquierda) ===
            $colsVisibles = 0;
            foreach($headers as $h) { if($h !== 'Patio' && $h !== 'Bloque') $colsVisibles++; }
            
            echo "<tr class='total-row'>";
            // Colspan -1 para dejar espacio al número
            // style='text-align: left;' fuerza la alineación a la izquierda
            echo "<td colspan='" . ($colsVisibles - 1) . "' style='text-align: left;'>Total Despachados:</td>";
            echo "<td class='number'>" . count($items) . "</td>";
            echo "</tr>";

            // Separador
            echo "<tr><td colspan='10' style='border:none; height:15px;'></td></tr>";
            echo "</table>";
        }
    }

} catch (Exception $e) {
    if ($modo === 'preview') echo json_encode(['error' => $e->getMessage()]);
    else echo "Error: " . $e->getMessage();
}
?>