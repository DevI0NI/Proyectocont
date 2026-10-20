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
    $fechaInicio = $_GET['f_inicio'] ?? date('Y-m-01');
    $fechaFin = $_GET['f_fin'] ?? date('Y-m-t');
    $patio_id = !empty($_GET['patio_id']) ? (int)$_GET['patio_id'] : null;
    $bloque_id = !empty($_GET['bloque_id']) ? (int)$_GET['bloque_id'] : null;
    $cols = $_GET['cols_desp'] ?? [];

    $mapaCampos = [
        'patio' => "IFNULL(p.nombre, 'Sin Ubicacion') AS patio_nombre",
        'bloque' => "IFNULL(b.numero, '0') AS bloque_numero",
        'posicion' => "IFNULL(c.posicion, '-') AS posicion",
        'contenedor' => "IFNULL(c.nombre, 'Eliminado') AS contenedor",
        'tipo' => "IFNULL(tu.nombre, '-') AS tipo",
        'modelo' => "IFNULL(m.nombre, '-') AS modelo",
        'estatus_unidad' => "IFNULL(eu.nombre, '-') AS est_unidad",
        'estatus_box' => "IFNULL(eb.nombre, '-') AS est_box",
        'estatus_ipsa' => "IFNULL(ei.nombre, '-') AS est_ipsa",
        'estatus_pyp' => "IFNULL(pa.estatus_pyp, 'Sin Asignar') AS est_pyp",
        'fecha_ingreso' => "c.fecha_ingreso AS fecha_ingreso",
        'fecha_despacho' => "l.fecha_evento AS fecha_despacho",
        'dias_estadia' => "DATEDIFF(l.fecha_evento, c.fecha_ingreso) AS dias_estadia",
        'usuario' => "u.username AS usuario",
        'observaciones' => "l.observaciones AS observaciones"
    ];

    $titulos = [
        'patio' => 'Patio',
        'bloque' => 'Bloque',
        'posicion' => 'Posicion',
        'contenedor' => 'Contenedor',
        'tipo' => 'Tipo',
        'modelo' => 'Modelo',
        'estatus_unidad' => 'Est. Unidad',
        'estatus_box' => 'Est. Box',
        'estatus_ipsa' => 'Est. IPSA',
        'estatus_pyp' => 'Est. PYP',
        'fecha_ingreso' => 'Ingreso',
        'fecha_despacho' => 'Despacho',
        'dias_estadia' => 'Dias Estadia',
        'usuario' => 'Despachador',
        'observaciones' => 'Observaciones'
    ];

    if (empty($cols)) {
        $cols = ['patio', 'bloque', 'contenedor', 'tipo', 'estatus_unidad', 'fecha_despacho', 'dias_estadia', 'usuario', 'observaciones'];
    }

    $selectParts = [$mapaCampos['patio'], $mapaCampos['bloque']];
    $headers = [];
    $dataKeys = [];

    foreach ($cols as $k) {
        if (!isset($mapaCampos[$k])) continue;
        if ($k !== 'patio' && $k !== 'bloque') {
            $selectParts[] = $mapaCampos[$k];
        }
        $headers[] = $titulos[$k];
        $parts = explode(" AS ", $mapaCampos[$k]);
        $dataKeys[] = trim(end($parts));
    }

    $selectParts = array_unique($selectParts);
    $sqlSelect = implode(", ", $selectParts);

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
    $rows = report_format_row_dates($rows, ['fecha_ingreso', 'fecha_despacho']);

    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        $sucursalNombre = report_excel_get_sucursal_name($pdo, $sucursal_id);
        if ($formato === 'pdf') {
            $contentHtml = report_pdf_grouped_html($headers, $dataKeys, $rows, [
                'hidden_headers' => ['Patio', 'Bloque'],
                'total_label' => 'Total despachados'
            ]);
            report_pdf_output(
                'Despachos_' . date('Ymd_His') . '.pdf',
                'Reporte de Contenedores Despachados',
                [
                    'Sucursal' => $sucursalNombre,
                    'Generado por' => $username,
                    'Periodo' => date('d/m/Y', strtotime($fechaInicio)) . ' al ' . date('d/m/Y', strtotime($fechaFin)),
                    'Fecha de emision' => date('d/m/Y'),
                    'Hora de emision' => date('H:i')
                ],
                $contentHtml
            );
        }
        [$spreadsheet, $sheet, $startRow] = report_excel_bootstrap(
            'Reporte de Contenedores Despachados',
            [
                'Sucursal' => $sucursalNombre,
                'Generado por' => $username,
                'Periodo' => date('d/m/Y', strtotime($fechaInicio)) . ' al ' . date('d/m/Y', strtotime($fechaFin)),
                'Fecha de emision' => date('d/m/Y'),
                'Hora de emision' => date('H:i')
            ],
            max(count($headers) - 2, 1),
            'Despachos'
        );

        report_excel_render_grouped_sheet($sheet, $startRow, $headers, $dataKeys, $rows, [
            'hidden_headers' => ['Patio', 'Bloque'],
            'total_label' => 'Total despachados'
        ]);
        report_excel_output($spreadsheet, "Despachos_" . date('Ymd_His') . ".xlsx");
    }
} catch (Exception $e) {
    if ($modo === 'preview') echo json_encode(['error' => $e->getMessage()]);
    else echo "Error: " . $e->getMessage();
}
?>
