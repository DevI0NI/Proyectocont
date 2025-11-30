<?php
session_start();
require '../config/database.php';

if (!isset($_SESSION['user_id'])) { exit('Acceso denegado'); }

// 1. CONFIGURACIÓN DE ZONA HORARIA
date_default_timezone_set('America/Guatemala');

$sucursal_id = $_SESSION['sucursal_activa'];
$modo = $_GET['modo'] ?? 'export'; // 'preview' o 'export'

// Límite para vista previa
$limit = ($modo === 'preview') ? " LIMIT 50" : "";

try {
    // 2. CAPTURA DE FECHAS (Por defecto HOY)
    $fechaInicio = $_GET['f_inicio'] ?? date('Y-m-d');
    $fechaFin    = $_GET['f_fin'] ?? date('Y-m-d');

    // Construcción Dinámica de Columnas
    $colsSeleccionadas = $_GET['cols'] ?? [];
    
    // 3. MAPA DE CAMPOS (Agregados Patio y Bloque)
    $mapaCampos = [
        'patio'          => "p.nombre AS patio_nombre",          // <--- NUEVO
        'bloque'         => "b.numero AS bloque_numero",         // <--- NUEVO
        'posicion'       => "c.posicion",
        'nombre'         => "c.nombre AS contenedor_nombre",
        'tipo'           => "t.nombre AS tipo_nombre",
        'modelo'         => "m.nombre AS modelo_nombre",
        'estatus_unidad' => "eu.nombre AS estatus_uni_nombre",
        'estatus_box'    => "eb.nombre AS estatus_box_nombre",
        'estatus_ipsa'   => "ei.nombre AS estatus_ipsa_nombre",
        'estatus_pyp'    => "IFNULL(pa.estatus_pyp, 'Sin Asignar') AS pyp_estado",
        'fecha_ingreso'  => "c.fecha_ingreso"
    ];

    $titulos = [
        'patio' => 'Patio', 'bloque' => 'Bloque', // <--- NUEVO
        'posicion' => 'Posición', 'nombre' => 'Contenedor', 'tipo' => 'Tipo',
        'modelo' => 'Modelo', 'estatus_unidad' => 'Est. Unidad',
        'estatus_box' => 'Est. Box', 'estatus_ipsa' => 'Est. IPSA',
        'estatus_pyp' => 'Est. PYP', 'fecha_ingreso' => 'Ingreso'
    ];

    $selectParts = [];
    $headers = []; 
    
    if (empty($colsSeleccionadas)) {
         // Default mínimo si no marcan nada
         $selectParts[] = "c.nombre AS contenedor_nombre"; 
         $headers[] = "Contenedor";
    } else {
        foreach ($colsSeleccionadas as $colKey) {
            if (isset($mapaCampos[$colKey])) {
                $selectParts[] = $mapaCampos[$colKey];
                $headers[] = $titulos[$colKey] ?? ucfirst($colKey);
            }
        }
    }

    $sqlSelect = implode(", ", $selectParts);

    // 4. CONSULTA SQL CON FILTRO DE FECHAS
    $sql = "
        SELECT $sqlSelect
        FROM contenedores c
        JOIN bloques b ON c.bloque_id = b.id
        JOIN patios p ON b.patio_id = p.id
        LEFT JOIN tipos_unidad t ON c.tipo_unidad_id = t.id
        LEFT JOIN modelos m ON c.modelo_id = m.id
        LEFT JOIN estatus_contenedores eu ON c.estatus_unidad_id = eu.id
        LEFT JOIN estatus_contenedores eb ON c.estatus_box_id = eb.id
        LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id
        LEFT JOIN pyp_auditoria pa ON c.id = pa.contenedor_id
        WHERE c.estado_id = 1 
          AND p.sucursal_id = :suc
          AND c.fecha_ingreso BETWEEN :inicio AND :fin  -- <--- FILTRO DE FECHAS
        ORDER BY p.nombre, b.numero, c.posicion
        $limit
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'suc' => $sucursal_id,
        'inicio' => $fechaInicio . ' 00:00:00',
        'fin' => $fechaFin . ' 23:59:59'
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- SALIDA ---
    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        // Exportar Excel
        $filename = "Inventario_" . date('Ymd_His') . ".xls";
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=$filename");
        header("Pragma: no-cache");
        header("Expires: 0");
        
        echo "<meta charset='UTF-8'>";
        echo "<table border='1'>";
        
        // Encabezados con estilo
        echo "<thead><tr>";
        foreach ($headers as $h) {
            echo "<th style='background-color:#00695C; color:white; border:1px solid #000; padding:5px;'>" . htmlspecialchars($h) . "</th>";
        }
        echo "</tr></thead>";
        
        // Cuerpo de la tabla
        echo "<tbody>";
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($row as $val) {
                echo "<td style='border:1px solid #ccc; padding:5px;'>" . htmlspecialchars($val) . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table>";
    }

} catch (Exception $e) {
    if ($modo === 'preview') {
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        echo "Error: " . $e->getMessage();
    }
}
?>