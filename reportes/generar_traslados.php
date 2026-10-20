<?php
session_start();
require '../config/database.php';
require __DIR__ . '/excel_export_helper.php';
require __DIR__ . '/report_display_helper.php';
require __DIR__ . '/pdf_export_helper.php';

date_default_timezone_set('America/Guatemala');

if (!isset($_SESSION['user_id'])) { exit('Acceso denegado'); }
if (!isset($_SESSION['sucursal_activa'])) { exit('Sin sucursal activa'); }

$sucursal_id = (int)$_SESSION['sucursal_activa'];
$username = $_SESSION['username'] ?? 'Usuario';
$modo = $_GET['modo'] ?? 'export';
$formato = $_GET['formato'] ?? 'excel';
$limit = ($modo === 'preview') ? " LIMIT 50" : "";

try {
    $f_inicio = $_GET['f_inicio'] ?? date('Y-m-01');
    $f_fin = $_GET['f_fin'] ?? date('Y-m-t');
    $patio_id = !empty($_GET['patio_id']) ? (int)$_GET['patio_id'] : null;
    $bloque_id = !empty($_GET['bloque_id']) ? (int)$_GET['bloque_id'] : null;
    $cols = $_GET['cols_tras'] ?? [];

    $mapaCampos = [
        'patio' => "p_dest.nombre AS patio_nombre",
        'bloque' => "b_dest.numero AS bloque_numero",
        'fecha' => "le.fecha_evento AS fecha",
        'contenedor' => "IFNULL(c.nombre, 'Eliminado') AS contenedor",
        'tipo' => "IFNULL(tu.nombre, '-') AS tipo",
        'origen' => "CONCAT(p_orig.nombre, ' - B', b_orig.numero, ' (Pos ', lt.posicion_anterior, ')') AS origen",
        'destino' => "CONCAT(p_dest.nombre, ' - B', b_dest.numero, ' (Pos ', lt.posicion_nueva, ')') AS destino",
        'usuario' => "u.username AS usuario",
        'detalle' => "le.observaciones AS detalle"
    ];

    $titulos = [
        'fecha' => 'Fecha Hora',
        'contenedor' => 'Contenedor',
        'tipo' => 'Tipo',
        'origen' => 'Origen (Desde)',
        'destino' => 'Destino (Posicion)',
        'usuario' => 'Operador',
        'detalle' => 'Observaciones'
    ];

    if (empty($cols)) {
        $cols = ['fecha', 'contenedor', 'tipo', 'origen', 'destino', 'usuario', 'detalle'];
    }

    $selectParts = ["p_dest.nombre AS patio_nombre", "b_dest.numero AS bloque_numero"];
    $headers = [];
    $dataKeys = [];

    foreach ($cols as $k) {
        if (!isset($mapaCampos[$k]) || $k === 'patio' || $k === 'bloque') continue;
        $selectParts[] = $mapaCampos[$k];
        $headers[] = $titulos[$k];
        $parts = explode(" AS ", $mapaCampos[$k]);
        $dataKeys[] = trim(end($parts));
    }

    $selectParts = array_unique($selectParts);
    $sqlSelect = implode(", ", $selectParts);

    $sql = "
        SELECT $sqlSelect
        FROM logs_traslados lt
        JOIN logs_eventos le ON lt.evento_id = le.id
        LEFT JOIN contenedores c ON le.contenedor_id = c.id
        LEFT JOIN tipos_unidad tu ON c.tipo_unidad_id = tu.id
        JOIN users u ON le.usuario_id = u.id
        JOIN bloques b_orig ON lt.bloque_anterior_id = b_orig.id
        JOIN patios p_orig ON b_orig.patio_id = p_orig.id
        JOIN bloques b_dest ON lt.bloque_nuevo_id = b_dest.id
        JOIN patios p_dest ON b_dest.patio_id = p_dest.id
        WHERE le.fecha_evento BETWEEN :ini AND :fin
          AND p_dest.sucursal_id = :suc
          AND lt.bloque_anterior_id IS NOT NULL
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
    $rows = report_format_row_dates($rows, ['fecha']);

    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        $sucursalNombre = report_excel_get_sucursal_name($pdo, $sucursal_id);
        if ($formato === 'pdf') {
            $contentHtml = report_pdf_grouped_html($headers, $dataKeys, $rows);
            report_pdf_output(
                'Traslados_' . date('Ymd_His') . '.pdf',
                'Reporte de Traslados',
                [
                    'Sucursal' => $sucursalNombre,
                    'Generado por' => $username,
                    'Periodo' => date('d/m/Y', strtotime($f_inicio)) . ' al ' . date('d/m/Y', strtotime($f_fin)),
                    'Fecha de emision' => date('d/m/Y'),
                    'Hora de emision' => date('H:i')
                ],
                $contentHtml
            );
        }
        [$spreadsheet, $sheet, $startRow] = report_excel_bootstrap(
            'Reporte de Traslados',
            [
                'Sucursal' => $sucursalNombre,
                'Generado por' => $username,
                'Periodo' => date('d/m/Y', strtotime($f_inicio)) . ' al ' . date('d/m/Y', strtotime($f_fin)),
                'Fecha de emision' => date('d/m/Y'),
                'Hora de emision' => date('H:i')
            ],
            max(count($headers), 1),
            'Traslados'
        );

        report_excel_render_grouped_sheet($sheet, $startRow, $headers, $dataKeys, $rows);
        report_excel_output($spreadsheet, "Traslados_" . date('Ymd_His') . ".xlsx");
    }
} catch (Exception $e) {
    if ($modo === 'preview') echo json_encode(['error' => $e->getMessage()]);
    else echo "Error: " . $e->getMessage();
}
?>
