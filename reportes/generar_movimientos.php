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
    $cols = $_GET['cols_mov'] ?? [];

    $mapaCampos = [
        'patio' => "p.nombre AS patio_nombre",
        'bloque' => "b.numero AS bloque_numero",
        'fecha' => "l.fecha_evento AS fecha_hora",
        'operacion' => "top.nombre AS operacion",
        'contenedor' => "IFNULL(c.nombre, 'Eliminado') AS contenedor",
        'tipo' => "IFNULL(tu.nombre, '-') AS tipo",
        'modelo' => "IFNULL(m.nombre, '-') AS modelo",
        'usuario' => "u.username AS usuario",
        'detalle' => "l.observaciones AS detalle"
    ];

    $titulos = [
        'patio' => 'Patio',
        'bloque' => 'Bloque',
        'fecha' => 'Fecha Hora',
        'operacion' => 'Operacion',
        'contenedor' => 'Contenedor',
        'tipo' => 'Tipo',
        'modelo' => 'Modelo',
        'usuario' => 'Usuario',
        'detalle' => 'Detalle'
    ];

    if (empty($cols)) {
        $cols = ['patio', 'bloque', 'contenedor', 'operacion', 'usuario', 'detalle', 'fecha'];
    }

    $selectParts = [];
    $headers = [];
    $dataKeys = [];

    foreach ($cols as $k) {
        if (!isset($mapaCampos[$k])) continue;
        $selectParts[] = $mapaCampos[$k];
        $headers[] = $titulos[$k];
        $parts = explode(" AS ", $mapaCampos[$k]);
        $dataKeys[] = trim(end($parts));
    }

    $sqlSelect = implode(", ", $selectParts);

    $sql = "
        SELECT $sqlSelect
        FROM logs_eventos l
        JOIN tipos_operacion top ON l.tipo_operacion_id = top.id
        JOIN users u ON l.usuario_id = u.id
        LEFT JOIN contenedores c ON l.contenedor_id = c.id
        LEFT JOIN tipos_unidad tu ON c.tipo_unidad_id = tu.id
        LEFT JOIN modelos m ON c.modelo_id = m.id
        JOIN bloques b ON c.bloque_id = b.id
        JOIN patios p ON b.patio_id = p.id
        WHERE l.fecha_evento BETWEEN :ini AND :fin
          AND p.sucursal_id = :suc
    ";

    $params = [
        'ini' => $f_inicio . ' 00:00:00',
        'fin' => $f_fin . ' 23:59:59',
        'suc' => $sucursal_id
    ];

    if ($patio_id) {
        $sql .= " AND p.id = :pid ";
        $params['pid'] = $patio_id;
    }
    if ($bloque_id) {
        $sql .= " AND b.id = :bid ";
        $params['bid'] = $bloque_id;
    }

    $sql .= " ORDER BY l.fecha_evento DESC $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = report_format_row_dates($rows, ['fecha_hora']);

    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
    } else {
        $sucursalNombre = report_excel_get_sucursal_name($pdo, $sucursal_id);
        if ($formato === 'pdf') {
            $contentHtml = report_pdf_grouped_html($headers, $dataKeys, $rows, [
                'hidden_headers' => ['Patio', 'Bloque']
            ]);
            report_pdf_output(
                'Bitacora_' . date('Ymd_His') . '.pdf',
                'Bitacora de Movimientos',
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
            'Bitacora de Movimientos',
            [
                'Sucursal' => $sucursalNombre,
                'Generado por' => $username,
                'Periodo' => date('d/m/Y', strtotime($f_inicio)) . ' al ' . date('d/m/Y', strtotime($f_fin)),
                'Fecha de emision' => date('d/m/Y'),
                'Hora de emision' => date('H:i')
            ],
            max(count($headers) - 2, 1),
            'Movimientos'
        );

        report_excel_render_grouped_sheet($sheet, $startRow, $headers, $dataKeys, $rows, [
            'hidden_headers' => ['Patio', 'Bloque']
        ]);
        report_excel_output($spreadsheet, "Bitacora_" . date('Ymd_His') . ".xlsx");
    }
} catch (Exception $e) {
    if ($modo === 'preview') echo json_encode(['error' => $e->getMessage()]);
    else echo "Error: " . $e->getMessage();
}
?>
