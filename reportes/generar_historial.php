<?php
session_start();
require '../config/database.php';
require __DIR__ . '/excel_export_helper.php';
require __DIR__ . '/report_display_helper.php';
require __DIR__ . '/pdf_export_helper.php';

date_default_timezone_set('America/Guatemala');

if (!isset($_SESSION['user_id'])) { exit('Acceso denegado'); }

$modo = $_GET['modo'] ?? 'export';
$formato = $_GET['formato'] ?? 'excel';
$contenedor_id = !empty($_GET['contenedor_id']) ? (int)$_GET['contenedor_id'] : null;
$username = $_SESSION['username'] ?? 'Usuario';
$sucursal_id = isset($_SESSION['sucursal_activa']) ? (int)$_SESSION['sucursal_activa'] : null;

try {
    if (!$contenedor_id) {
        throw new Exception("Debe seleccionar un contenedor de la lista.");
    }

    $stmtC = $pdo->prepare("SELECT nombre FROM contenedores WHERE id = :id");
    $stmtC->execute(['id' => $contenedor_id]);
    $nombreContenedor = $stmtC->fetchColumn();

    if (!$nombreContenedor) {
        $nombreContenedor = "Contenedor ID: $contenedor_id (Eliminado)";
    }

    $sql = "
        SELECT
            l.fecha_evento AS fecha,
            top.nombre AS operacion,
            u.username AS usuario,
            l.observaciones AS detalle_base,
            p_ant.nombre AS patio_ant, b_ant.numero AS bloq_ant, lt.posicion_anterior,
            p_nue.nombre AS patio_nue, b_nue.numero AS bloq_nue, lt.posicion_nueva
        FROM logs_eventos l
        JOIN tipos_operacion top ON l.tipo_operacion_id = top.id
        JOIN users u ON l.usuario_id = u.id
        LEFT JOIN logs_traslados lt ON l.id = lt.evento_id
        LEFT JOIN bloques b_ant ON lt.bloque_anterior_id = b_ant.id
        LEFT JOIN patios p_ant ON b_ant.patio_id = p_ant.id
        LEFT JOIN bloques b_nue ON lt.bloque_nuevo_id = b_nue.id
        LEFT JOIN patios p_nue ON b_nue.patio_id = p_nue.id
        WHERE l.contenedor_id = :cid
        ORDER BY l.fecha_evento ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['cid' => $contenedor_id]);
    $rows_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($rows_raw as $r) {
        $detalle = $r['detalle_base'];
        if ($r['patio_nue']) {
            $origen = ($r['patio_ant']) ? "{$r['patio_ant']} B{$r['bloq_ant']} P{$r['posicion_anterior']}" : "Ingreso/Externo";
            $destino = "{$r['patio_nue']} B{$r['bloq_nue']} P{$r['posicion_nueva']}";
            $detalle .= " [Movido de: $origen -> A: $destino]";
        }

        $rows[] = [
            'fecha' => $r['fecha'],
            'operacion' => $r['operacion'],
            'usuario' => $r['usuario'],
            'detalle' => $detalle
        ];
    }
    $rows = report_format_row_dates($rows, ['fecha']);

    $headers = ['Fecha Hora', 'Operacion', 'Usuario', 'Detalle Historico'];
    $dataKeys = ['fecha', 'operacion', 'usuario', 'detalle'];

    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        $sucursalNombre = report_excel_get_sucursal_name($pdo, $sucursal_id);
        if ($formato === 'pdf') {
            $contentHtml = report_pdf_simple_html($headers, $dataKeys, $rows);
            report_pdf_output(
                'Historial_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $nombreContenedor) . "_" . date('Ymd') . '.pdf',
                'Historial Completo del Contenedor',
                [
                    'Sucursal' => $sucursalNombre,
                    'Contenedor' => $nombreContenedor,
                    'Generado por' => $username,
                    'Fecha de emision' => date('d/m/Y'),
                    'Hora de emision' => date('H:i')
                ],
                $contentHtml
            );
        }
        [$spreadsheet, $sheet, $startRow] = report_excel_bootstrap(
            'Historial Completo del Contenedor',
            [
                'Sucursal' => $sucursalNombre,
                'Contenedor' => $nombreContenedor,
                'Generado por' => $username,
                'Fecha de emision' => date('d/m/Y'),
                'Hora de emision' => date('H:i')
            ],
            max(count($headers), 1),
            'Historial'
        );

        report_excel_render_simple_sheet($sheet, $startRow, $headers, $dataKeys, $rows);
        report_excel_output($spreadsheet, "Historial_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $nombreContenedor) . "_" . date('Ymd') . ".xlsx");
    }
} catch (Exception $e) {
    if ($modo === 'preview') echo json_encode(['error' => $e->getMessage()]);
    else echo "Error: " . $e->getMessage();
}
?>
