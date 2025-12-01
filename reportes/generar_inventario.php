<?php
session_start();
require '../config/database.php';

if (!isset($_SESSION['user_id'])) { exit('Acceso denegado'); }

date_default_timezone_set('America/Guatemala');

$sucursal_id = $_SESSION['sucursal_activa'];
$modo = $_GET['modo'] ?? 'export';
$limit = ($modo === 'preview') ? " LIMIT 50" : "";

try {
    // 1. Recibir Filtros (Mes actual por defecto)
    $fechaInicio = $_GET['f_inicio'] ?? date('Y-m-01');
    $fechaFin    = $_GET['f_fin'] ?? date('Y-m-t');
    $patio_id    = !empty($_GET['patio_id']) ? (int)$_GET['patio_id'] : null;
    $bloque_id   = !empty($_GET['bloque_id']) ? (int)$_GET['bloque_id'] : null;

    // 2. Columnas Seleccionadas
    $colsSeleccionadas = $_GET['cols'] ?? [];
    
    // Mapa de campos (SQL completo)
    $mapaCampos = [
        'patio'                => "p.nombre AS patio_nombre",
        'bloque'               => "b.numero AS bloque_numero",
        'posicion'             => "c.posicion",
        'nombre'               => "c.nombre AS contenedor_nombre",
        'tipo'                 => "t.nombre AS tipo_nombre",
        'modelo'               => "m.nombre AS modelo_nombre",
        'estatus_unidad'       => "eu.nombre AS estatus_uni_nombre",
        'estatus_box'          => "eb.nombre AS estatus_box_nombre",
        'estatus_ipsa'         => "ei.nombre AS estatus_ipsa_nombre",
        'estatus_pyp'          => "IFNULL(pa.estatus_pyp, 'Sin Asignar') AS pyp_estado",
        'observaciones_unidad' => "c.observaciones_unidad",
        'observaciones_box'    => "c.observaciones_box",
        'fecha_ingreso'        => "c.fecha_ingreso"
    ];

    $titulos = [
        'patio'                => 'Patio', 
        'bloque'               => 'Bloque',
        'posicion'             => 'Posición', 
        'nombre'               => 'Contenedor', 
        'tipo'                 => 'Tipo',
        'modelo'               => 'Modelo', 
        'estatus_unidad'       => 'Est. Unidad',
        'estatus_box'          => 'Est. Box', 
        'estatus_ipsa'         => 'Est. IPSA',
        'estatus_pyp'          => 'Est. PYP',
        'observaciones_unidad' => 'Obs. Unidad',
        'observaciones_box'    => 'Obs. Box',
        'fecha_ingreso'        => 'Ingreso'
    ];

    $selectParts = [];
    $headers = []; 
    $dataKeys = []; // Para saber qué clave del array $row corresponde a cada columna

    if (empty($colsSeleccionadas)) {
         $selectParts[] = "c.nombre AS contenedor_nombre"; 
         $headers[] = "Contenedor";
         $dataKeys[] = "contenedor_nombre";
    } else {
        foreach ($colsSeleccionadas as $colKey) {
            if (isset($mapaCampos[$colKey])) {
                $selectParts[] = $mapaCampos[$colKey];
                $headers[] = $titulos[$colKey] ?? ucfirst($colKey);
                
                // Extraer el alias para saber cómo buscarlo en $row después
                // Ejemplo: "p.nombre AS patio_nombre" -> "patio_nombre"
                // Ejemplo: "c.posicion" -> "posicion"
                $parts = explode(" AS ", $mapaCampos[$colKey]);
                if (count($parts) > 1) {
                    $dataKeys[] = trim(end($parts));
                } else {
                    // Si no tiene AS, tomamos lo que está después del punto
                    $partsDot = explode(".", $mapaCampos[$colKey]);
                    $dataKeys[] = trim(end($partsDot));
                }
            }
        }
    }

    $sqlSelect = implode(", ", $selectParts);

    // 3. Preparar SQL
    $params = [
        'suc' => $sucursal_id,
        'ini' => $fechaInicio . ' 00:00:00',
        'fin' => $fechaFin . ' 23:59:59'
    ];

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
          AND c.fecha_ingreso BETWEEN :ini AND :fin
    ";

    if ($patio_id) {
        $sql .= " AND p.id = :pid ";
        $params['pid'] = $patio_id;
    }
    if ($bloque_id) {
        $sql .= " AND b.id = :bid ";
        $params['bid'] = $bloque_id;
    }

    $sql .= " ORDER BY p.nombre, b.numero, c.posicion $limit";
    
    // 4. Ejecutar
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params); 
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- SALIDA ---
    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        // === MODO EXPORTAR EXCEL CON AGRUPACIÓN ===
        $filename = "Inventario_" . date('Ymd_His') . ".xls";
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=$filename");
        header("Pragma: no-cache");
        header("Expires: 0");
        
        echo "<meta charset='UTF-8'>";
        echo "<style>
                .group-header { background-color: #e0f2f1; color: #00695C; font-weight: bold; border: 1px solid #ccc; font-size: 14px; text-align: left; }
                .table-header { background-color: #00695C; color: white; font-weight: bold; border: 1px solid #000; }
                td { border: 1px solid #ccc; }
              </style>";

        // 1. Agrupar los datos (Igual que en JS)
        $grupos = [];
        foreach ($rows as $row) {
            // Verificamos si existen las columnas de agrupación en la selección del usuario
            $p = $row['patio_nombre'] ?? '';
            $b = $row['bloque_numero'] ?? '';
            
            $key = ($p && $b) ? "$p - Bloque $b" : "General";
            $grupos[$key][] = $row;
        }

        // 2. Iterar Grupos e Imprimir Tablas
        foreach ($grupos as $titulo => $items) {
            
            echo "<table>"; // Iniciamos tabla para este grupo

            // Fila de Título del Grupo (ocupa todas las columnas)
            // Solo si hay más de un grupo o no es "General"
            if ($titulo !== 'General' || count($grupos) > 1) {
                // Calculamos colspan restando 2 (Patio y Bloque que ocultaremos) o total
                // Para simplificar en Excel, un colspan alto funciona bien
                echo "<tr><td colspan='10' class='group-header' style='height:30px; vertical-align:middle;'>$titulo</td></tr>";
            }

            // Fila de Encabezados de Columnas
            echo "<tr>";
            foreach ($headers as $i => $h) {
                // Ocultamos columnas Patio y Bloque del header si estamos agrupando
                if ($h !== 'Patio' && $h !== 'Bloque') {
                    echo "<td class='table-header'>$h</td>";
                }
            }
            echo "</tr>";

            // Filas de Datos
            foreach ($items as $row) {
                echo "<tr>";
                // Iteramos usando $dataKeys para mantener el orden correcto
                foreach ($dataKeys as $i => $key) {
                    // El header correspondiente es $headers[$i]
                    $headerName = $headers[$i];

                    // Ocultamos celda si es Patio o Bloque
                    if ($headerName !== 'Patio' && $headerName !== 'Bloque') {
                        $val = $row[$key] ?? ''; // Valor seguro
                        echo "<td>" . htmlspecialchars($val) . "</td>";
                    }
                }
                echo "</tr>";
            }
            // Calculamos cuántas columnas visibles hay para ajustar el colspan
            $colsVisibles = 0;
            foreach($headers as $h) { if($h !== 'Patio' && $h !== 'Bloque') $colsVisibles++; }
            
            echo "<tr class='total-row'>";
            // Colspan - 1 para dejar espacio a la celda del número
            echo "<td colspan='" . ($colsVisibles - 1) . "'>Total Contenedores:</td>";
            echo "<td>" . count($items) . "</td>";
            echo "</tr>";
            // Fila vacía para separar grupos visualmente en Excel
            echo "<tr><td colspan='10' style='border:none;'></td></tr>";
            echo "</table>";
        }
    }

} catch (Exception $e) {
    if ($modo === 'preview') echo json_encode(['error' => $e->getMessage()]);
    else echo "Error: " . $e->getMessage();
}
?>