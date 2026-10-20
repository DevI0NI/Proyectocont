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
    $cols = $_GET['cols_cambios'] ?? [];

    $mapaCampos = [
        'patio' => "patio_nombre",
        'bloque' => "bloque_numero",
        'fecha' => "fecha_evento AS fecha",
        'contenedor' => "contenedor AS contenedor",
        'usuario' => "usuario AS usuario",
        'tipo_cambio' => "tipo_cambio AS tipo_cambio",
        'campo' => "campo AS campo",
        'anterior' => "valor_anterior AS anterior",
        'nuevo' => "valor_nuevo AS nuevo"
    ];

    $titulos = [
        'fecha' => 'Fecha Hora',
        'contenedor' => 'Contenedor',
        'usuario' => 'Usuario',
        'tipo_cambio' => 'Tipo de Cambio',
        'campo' => 'Campo Afectado',
        'anterior' => 'Valor Anterior',
        'nuevo' => 'Valor Nuevo'
    ];

    if (empty($cols)) {
        $cols = ['fecha', 'contenedor', 'usuario', 'tipo_cambio', 'campo', 'anterior', 'nuevo'];
    }

    $selectParts = ["patio_nombre", "bloque_numero"];
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
    SELECT $sqlSelect FROM (
        SELECT
            le.fecha_evento,
            IFNULL(c.nombre, 'Eliminado') as contenedor,
            u.username as usuario,
            'Datos Generales' as tipo_cambio,
            lm.campo_modificado as campo,
            lm.valor_anterior,
            lm.valor_nuevo,
            p.sucursal_id,
            IFNULL(p.nombre, 'Sin Ubicacion') as patio_nombre,
            IFNULL(b.numero, '0') as bloque_numero,
            p.id as pid, b.id as bid
        FROM logs_modificaciones lm
        JOIN logs_eventos le ON lm.evento_id = le.id
        LEFT JOIN contenedores c ON le.contenedor_id = c.id
        LEFT JOIN users u ON le.usuario_id = u.id
        LEFT JOIN bloques b ON c.bloque_id = b.id
        LEFT JOIN patios p ON b.patio_id = p.id

        UNION ALL

        SELECT
            le.fecha_evento,
            IFNULL(c.nombre, 'Eliminado') as contenedor,
            u.username as usuario,
            'Estatus Contenedor' as tipo_cambio,
            te.nombre as campo,
            IFNULL(ea.nombre, 'N/A') as valor_anterior,
            IFNULL(en.nombre, 'N/A') as valor_nuevo,
            p.sucursal_id,
            IFNULL(p.nombre, 'Sin Ubicacion') as patio_nombre,
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

        SELECT
            le.fecha_evento,
            IFNULL(c.nombre, 'Eliminado') as contenedor,
            u.username as usuario,
            'Estatus IPSA' as tipo_cambio,
            'Condicion IPSA' as campo,
            IFNULL(ia.nombre, 'N/A') as valor_anterior,
            IFNULL(inew.nombre, 'N/A') as valor_nuevo,
            p.sucursal_id,
            IFNULL(p.nombre, 'Sin Ubicacion') as patio_nombre,
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

    if ($patio_id) { $sql .= " AND unificada.pid = :pid "; $params['pid'] = $patio_id; }
    if ($bloque_id) { $sql .= " AND unificada.bid = :bid "; $params['bid'] = $bloque_id; }

    $sql .= " ORDER BY unificada.patio_nombre, unificada.bloque_numero, unificada.fecha_evento DESC $limit";

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
                'Cambios_' . date('Ymd_His') . '.pdf',
                'Reporte de Cambios',
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
            'Reporte de Cambios',
            [
                'Sucursal' => $sucursalNombre,
                'Generado por' => $username,
                'Periodo' => date('d/m/Y', strtotime($fechaInicio)) . ' al ' . date('d/m/Y', strtotime($fechaFin)),
                'Fecha de emision' => date('d/m/Y'),
                'Hora de emision' => date('H:i')
            ],
            max(count($headers), 1),
            'Cambios'
        );

        report_excel_render_grouped_sheet($sheet, $startRow, $headers, $dataKeys, $rows);
        report_excel_output($spreadsheet, "Cambios_" . date('Ymd_His') . ".xlsx");
    }
} catch (Exception $e) {
    if ($modo === 'preview') echo json_encode(['error' => $e->getMessage()]);
    else echo "Error: " . $e->getMessage();
}
?>
