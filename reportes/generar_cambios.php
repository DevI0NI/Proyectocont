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

    $cols = $_GET['cols_cambios'] ?? [];

    // 3. MAPEO DE CAMPOS
    // Definimos qué columna de la DB corresponde a qué checkbox
    $mapaCampos = [
        // Datos de Agrupación (Internos)
        'patio'       => "patio_nombre",
        'bloque'      => "bloque_numero",
        
        // Columnas Visibles
        'fecha'       => "fecha_evento AS fecha",
        'contenedor'  => "contenedor AS contenedor",
        'usuario'     => "usuario AS usuario",
        'tipo_cambio' => "tipo_cambio AS tipo_cambio",
        'campo'       => "campo AS campo",
        'anterior'    => "valor_anterior AS anterior",
        'nuevo'       => "valor_nuevo AS nuevo"
    ];

    $titulos = [
        'fecha'       => 'Fecha Hora',
        'contenedor'  => 'Contenedor',
        'usuario'     => 'Usuario',
        'tipo_cambio' => 'Tipo de Cambio',
        'campo'       => 'Campo Afectado',
        'anterior'    => 'Valor Anterior',
        'nuevo'       => 'Valor Nuevo'
    ];

    // Construcción dinámica de la consulta
    $selectParts = []; 
    $headers = []; 
    $dataKeys = [];

    // Si no hay selección, valores por defecto
    if (empty($cols)) { 
        $cols = ['fecha', 'contenedor', 'usuario', 'tipo_cambio', 'campo', 'anterior', 'nuevo']; 
    }

    // A. SIEMPRE agregamos las columnas de agrupación al SQL (necesarias para el azul)
    // pero NO las agregamos a los headers ni datakeys visibles.
    $selectParts[] = "patio_nombre";
    $selectParts[] = "bloque_numero";

    // B. Agregamos las columnas seleccionadas por el usuario
    foreach ($cols as $k) {
        if (isset($mapaCampos[$k])) {
            // Si es patio o bloque, ya los agregamos arriba al SQL, pero los ignoramos para la tabla visual
            if ($k === 'patio' || $k === 'bloque') {
                continue; 
            }

            $selectParts[] = $mapaCampos[$k];
            $headers[] = $titulos[$k];
            
            // Extraer alias limpio (ej: "valor_nuevo")
            $parts = explode(" AS ", $mapaCampos[$k]);
            $dataKeys[] = trim(end($parts));
        }
    }

    $selectParts = array_unique($selectParts);
    $sqlSelect = implode(", ", $selectParts); // Solo columnas necesarias

    // 4. CONSULTA SQL UNIFICADA (UNION ALL)
    $sql = "
    SELECT $sqlSelect FROM (
        -- 1. LOGS MODIFICACIONES
        SELECT 
            le.fecha_evento,
            IFNULL(c.nombre, 'Eliminado') as contenedor,
            u.username as usuario,
            'Datos Generales' as tipo_cambio,
            lm.campo_modificado as campo,
            lm.valor_anterior,
            lm.valor_nuevo,
            p.sucursal_id,
            IFNULL(p.nombre, 'Sin Ubicación') as patio_nombre,
            IFNULL(b.numero, '0') as bloque_numero,
            p.id as pid, b.id as bid
        FROM logs_modificaciones lm
        JOIN logs_eventos le ON lm.evento_id = le.id
        LEFT JOIN contenedores c ON le.contenedor_id = c.id
        LEFT JOIN users u ON le.usuario_id = u.id
        LEFT JOIN bloques b ON c.bloque_id = b.id
        LEFT JOIN patios p ON b.patio_id = p.id

        UNION ALL

        -- 2. LOGS ESTATUS
        SELECT 
            le.fecha_evento,
            IFNULL(c.nombre, 'Eliminado') as contenedor,
            u.username as usuario,
            'Estatus Contenedor' as tipo_cambio,
            te.nombre as campo,
            IFNULL(ea.nombre, 'N/A') as valor_anterior,
            IFNULL(en.nombre, 'N/A') as valor_nuevo,
            p.sucursal_id,
            IFNULL(p.nombre, 'Sin Ubicación') as patio_nombre,
            IFNULL(b.numero, '0') as bloque_numero,
            p.id as pid, b.id as bid
        FROM logs_estatus le_st
        JOIN logs_eventos le ON le_st.evento_id = le.id
        LEFT JOIN contenedores c ON le.contenedor_id = c.id
        LEFT JOIN users u ON le.usuario_id = u.id
        LEFT JOIN tipos_estatus te ON le_st.tipo_estatus_id = te.id
        LEFT JOIN estatus_contenedores ea ON le_st.valor_anterior_id = ea.id
        LEFT JOIN estatus_contenedores en ON le_st.valor_nuevo_id = en.id
        LEFT JOIN bloques b ON c.bloque_id = b.id
        LEFT JOIN patios p ON b.patio_id = p.id

        UNION ALL

        -- 3. LOGS IPSA
        SELECT 
            le.fecha_evento,
            IFNULL(c.nombre, 'Eliminado') as contenedor,
            u.username as usuario,
            'Estatus IPSA' as tipo_cambio,
            'Condición IPSA' as campo,
            IFNULL(ia.nombre, 'N/A') as valor_anterior,
            IFNULL(inew.nombre, 'N/A') as valor_nuevo,
            p.sucursal_id,
            IFNULL(p.nombre, 'Sin Ubicación') as patio_nombre,
            IFNULL(b.numero, '0') as bloque_numero,
            p.id as pid, b.id as bid
        FROM logs_ipsa li
        JOIN logs_eventos le ON li.evento_id = le.id
        LEFT JOIN contenedores c ON le.contenedor_id = c.id
        LEFT JOIN users u ON le.usuario_id = u.id
        LEFT JOIN estatus_ipsa ia ON li.estatus_anterior_id = ia.id
        LEFT JOIN estatus_ipsa inew ON li.estatus_nuevo_id = inew.id
        LEFT JOIN bloques b ON c.bloque_id = b.id
        LEFT JOIN patios p ON b.patio_id = p.id
    ) AS unificada
    
    WHERE unificada.fecha_evento BETWEEN :ini AND :fin
      AND unificada.sucursal_id = :suc
    ";

    $params = [
        'ini' => $fechaInicio . ' 00:00:00',
        'fin' => $fechaFin . ' 23:59:59',
        'suc' => $sucursal_id
    ];

    // Filtros de ubicación
    if ($patio_id) { $sql .= " AND unificada.pid = :pid "; $params['pid'] = $patio_id; }
    if ($bloque_id) { $sql .= " AND unificada.bid = :bid "; $params['bid'] = $bloque_id; }

    // Ordenamiento para agrupación
    $sql .= " ORDER BY unificada.patio_nombre, unificada.bloque_numero, unificada.fecha_evento DESC $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. SALIDA
    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        // EXCEL
        $filename = "Cambios_" . date('Ymd_His') . ".xls";
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=$filename");
        header("Pragma: no-cache");
        header("Expires: 0");
        
        echo "<meta charset='UTF-8'><style>td{border:1px solid #ccc;mso-number-format:'\@';}.group-header{background:#e0f2f1;font-weight:bold;}.table-header{background:#00695C;color:white;}</style>";
        
        // Agrupar
        $grupos = [];
        foreach ($rows as $row) {
            $p = $row['patio_nombre'];
            $b = $row['bloque_numero'];
            $key = "$p - Bloque $b";
            $grupos[$key][] = $row;
        }

        foreach ($grupos as $titulo => $items) {
            echo "<table>";
            echo "<tr><td colspan='".count($headers)."' class='group-header'>$titulo</td></tr>";
            
            echo "<tr>";
            foreach ($headers as $h) echo "<td class='table-header'>$h</td>";
            echo "</tr>";
            
            foreach ($items as $row) {
                echo "<tr>";
                // Usamos dataKeys para imprimir EXACTAMENTE las columnas solicitadas en orden
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