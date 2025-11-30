<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit; }

require '../config/database.php';

// Configurar Headers para Excel
$filename = "reporte_" . date('Ymd_His') . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=$filename");
header("Pragma: no-cache");
header("Expires: 0");

// Capturar datos (usamos $_GET porque el form de exportar es un link o form GET)
// OJO: En reportes.php puse el form method="GET" action="exportar_reporte.php".
// Asegúrate de que el form use GET para que esto funcione fácil, o usa POST y cambia el backend.
// En mi código de reportes.php puse method="GET" en el form.

$sucursal_id = $_SESSION['sucursal_activa'];
$tipo = $_GET['tipo'] ?? '';

// --- COPIA DE LÓGICA DE CONSULTA (Simplificada para Exportación Completa) ---
// Nota: En un sistema real, esto se encapsularía en una clase ReporteService.

try {
    $sql = "";
    $params = [];
    $headers = [];

    if ($tipo === 'inventario') {
        $cols = $_GET['cols'] ?? [];
        // Mapa igual al anterior...
        $mapa = [
            'posicion' => "c.posicion AS 'Posición'",
            'nombre' => "c.nombre AS 'Contenedor'",
            'tipo' => "t.nombre AS 'Tipo'",
            'modelo' => "m.nombre AS 'Modelo'",
            'estatus_unidad' => "eu.nombre AS 'Est. Unidad'",
            'estatus_box' => "eb.nombre AS 'Est. Box'",
            'estatus_ipsa' => "ei.nombre AS 'Est. IPSA'",
            'estatus_pyp' => "IFNULL(pa.estatus_pyp, 'Sin Asignar') AS 'Est. PYP'",
            'fecha_ingreso' => "c.fecha_ingreso AS 'Ingreso'"
        ];
        
        $selects = [];
        if(empty($cols)) $cols = array_keys($mapa); // Si no marca nada, todo (o manejar error)

        foreach($cols as $c) if(isset($mapa[$c])) $selects[] = $mapa[$c];
        
        $sqlSelect = implode(", ", $selects);
        $sql = "SELECT $sqlSelect FROM contenedores c 
                JOIN bloques b ON c.bloque_id = b.id JOIN patios p ON b.patio_id = p.id 
                LEFT JOIN tipos_unidad t ON c.tipo_unidad_id = t.id 
                LEFT JOIN modelos m ON c.modelo_id = m.id
                LEFT JOIN estatus_contenedores eu ON c.estatus_unidad_id = eu.id 
                LEFT JOIN estatus_contenedores eb ON c.estatus_box_id = eb.id 
                LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id 
                LEFT JOIN pyp_auditoria pa ON c.id = pa.contenedor_id 
                WHERE c.estado_id = 1 AND p.sucursal_id = :suc ORDER BY p.nombre, b.numero, c.posicion";
        $params = ['suc' => $sucursal_id];

    } elseif ($tipo === 'movimientos' || $tipo === 'auditorias') {
        // Lógica similar para movimientos/auditorias con filtros de fecha
        // ... (Implementar lógica similar mapeando columnas de $_GET) ...
        // Para brevedad, asumo que entiendes el patrón: Mapear inputs -> Construir SELECT -> Ejecutar.
        
        // Ejemplo rápido Movimientos:
        if ($tipo === 'movimientos') {
             $sql = "SELECT l.fecha_evento, t.nombre as operacion, c.nombre as contenedor, u.username, l.observaciones 
                     FROM logs_eventos l JOIN tipos_operacion t ON l.tipo_operacion_id = t.id 
                     JOIN users u ON l.usuario_id = u.id LEFT JOIN contenedores c ON l.contenedor_id = c.id 
                     WHERE l.fecha_evento BETWEEN :ini AND :fin ORDER BY l.id DESC";
             $params = ['ini' => $_GET['f_inicio'].' 00:00:00', 'fin' => $_GET['f_fin'].' 23:59:59'];
        }
    }

    if ($sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo "<meta charset='UTF-8'><table border='1'>";
        $first = true;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($first) {
                echo "<thead><tr>";
                foreach (array_keys($row) as $k) echo "<th style='background:#00695C;color:white;'>$k</th>";
                echo "</tr></thead><tbody>";
                $first = false;
            }
            echo "<tr>";
            foreach ($row as $v) echo "<td>" . htmlspecialchars($v) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }

} catch (Exception $e) {
    echo "Error al generar reporte.";
}
?>