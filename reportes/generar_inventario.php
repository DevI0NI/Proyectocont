<?php
session_start();
require '../config/database.php';
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/report_display_helper.php';
require __DIR__ . '/pdf_export_helper.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

if (!isset($_SESSION['user_id'])) { exit('Acceso denegado'); }

date_default_timezone_set('America/Guatemala');

$sucursal_id = (int)($_SESSION['sucursal_activa'] ?? 0);
$username = $_SESSION['username'] ?? 'Usuario';
$modo = $_GET['modo'] ?? 'export';
$formato = $_GET['formato'] ?? 'excel';
$limit = ($modo === 'preview') ? " LIMIT 50" : "";

try {
    $fechaInicio = $_GET['f_inicio'] ?? date('Y-m-01');
    $fechaFin = $_GET['f_fin'] ?? date('Y-m-t');
    $patio_id = !empty($_GET['patio_id']) ? (int)$_GET['patio_id'] : null;
    $bloque_id = !empty($_GET['bloque_id']) ? (int)$_GET['bloque_id'] : null;
    $colsSeleccionadas = $_GET['cols'] ?? [];

    $mapaCampos = [
        'patio' => "p.nombre AS patio_nombre",
        'bloque' => "b.numero AS bloque_numero",
        'posicion' => "c.posicion",
        'nombre' => "c.nombre AS contenedor_nombre",
        'tipo' => "t.nombre AS tipo_nombre",
        'modelo' => "m.nombre AS modelo_nombre",
        'estatus_unidad' => "eu.nombre AS estatus_uni_nombre",
        'estatus_box' => "eb.nombre AS estatus_box_nombre",
        'estatus_ipsa' => "ei.nombre AS estatus_ipsa_nombre",
        'estatus_pyp' => "IFNULL(pa.estatus_pyp, 'Sin Asignar') AS pyp_estado",
        'observaciones_unidad' => "c.observaciones_unidad",
        'observaciones_box' => "c.observaciones_box",
        'fecha_ingreso' => "c.fecha_ingreso"
    ];

    $titulos = [
        'patio' => 'Patio',
        'bloque' => 'Bloque',
        'posicion' => 'Posicion',
        'nombre' => 'Contenedor',
        'tipo' => 'Tipo',
        'modelo' => 'Modelo',
        'estatus_unidad' => 'Est. Unidad',
        'estatus_box' => 'Est. Box',
        'estatus_ipsa' => 'Est. IPSA',
        'estatus_pyp' => 'Est. PYP',
        'observaciones_unidad' => 'Obs. Unidad',
        'observaciones_box' => 'Obs. Box',
        'fecha_ingreso' => 'Ingreso'
    ];

    $sucursalNombre = 'No definida';
    if ($sucursal_id > 0) {
        $stmtSuc = $pdo->prepare("SELECT nombre FROM sucursales WHERE id = :id LIMIT 1");
        $stmtSuc->execute(['id' => $sucursal_id]);
        $sucursalNombre = $stmtSuc->fetchColumn() ?: 'No definida';
    }

    $selectParts = [
        $mapaCampos['patio'],
        $mapaCampos['bloque']
    ];
    $headers = [];
    $dataKeys = [];

    if (empty($colsSeleccionadas)) {
        $colsSeleccionadas = ['nombre'];
    }

    foreach ($colsSeleccionadas as $colKey) {
        if (!isset($mapaCampos[$colKey])) {
            continue;
        }

        if ($colKey !== 'patio' && $colKey !== 'bloque') {
            $selectParts[] = $mapaCampos[$colKey];
        }

        $headers[] = $titulos[$colKey] ?? ucfirst($colKey);

        $parts = explode(" AS ", $mapaCampos[$colKey]);
        if (count($parts) > 1) {
            $dataKeys[] = trim(end($parts));
        } else {
            $partsDot = explode(".", $mapaCampos[$colKey]);
            $dataKeys[] = trim(end($partsDot));
        }
    }

    $selectParts = array_values(array_unique($selectParts));
    $sqlSelect = implode(", ", $selectParts);
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

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = report_format_row_dates($rows, ['fecha_ingreso']);

    if ($modo === 'preview') {
        header('Content-Type: application/json');
        echo json_encode(['headers' => $headers, 'rows' => $rows]);
        exit;
    }

    if ($formato === 'pdf') {
        $contentHtml = report_pdf_grouped_html($headers, $dataKeys, $rows, [
            'hidden_headers' => ['Patio', 'Bloque'],
            'total_label' => 'Total contenedores'
        ]);
        report_pdf_output(
            'Inventario_' . date('Ymd_His') . '.pdf',
            'Reporte de Inventario Activo',
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

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Inventario');
    $sheet->freezePane('A10');
    $sheet->getDefaultRowDimension()->setRowHeight(22);

    $visibleHeaders = [];
    $visibleKeys = [];
    foreach ($headers as $index => $header) {
        if ($header === 'Patio' || $header === 'Bloque') {
            continue;
        }
        $visibleHeaders[] = $header;
        $visibleKeys[] = $dataKeys[$index] ?? '';
    }

    if (empty($visibleHeaders)) {
        $visibleHeaders = ['Contenedor'];
        $visibleKeys = ['contenedor_nombre'];
    }

    $lastColumnLetter = Coordinate::stringFromColumnIndex(max(count($visibleHeaders), 1));

    $logoPath = __DIR__ . '/../imagenes/IPSA.png';
    if (extension_loaded('gd') && is_file($logoPath)) {
        $drawing = new Drawing();
        $drawing->setName('Logo IPSA');
        $drawing->setDescription('Logo de la empresa');
        $drawing->setPath($logoPath);
        $drawing->setCoordinates('A1');
        $drawing->setOffsetX(8);
        $drawing->setOffsetY(6);
        $drawing->setHeight(52);
        $drawing->setWorksheet($sheet);
    }

    $sheet->mergeCells("A1:{$lastColumnLetter}1");
    $sheet->mergeCells("A2:{$lastColumnLetter}2");
    $sheet->mergeCells("A3:{$lastColumnLetter}3");
    $sheet->mergeCells("A4:{$lastColumnLetter}4");

    $sheet->setCellValue('A1', 'SISTEMA PORTUARIO PROPORTSA');
    $sheet->setCellValue('A2', 'REPORTE DE INVENTARIO ACTIVO');
    $sheet->setCellValue('A3', 'Sucursal: ' . $sucursalNombre . '   |   Generado por: ' . $username);
    $sheet->setCellValue('A4', 'Periodo: ' . date('d/m/Y', strtotime($fechaInicio)) . ' al ' . date('d/m/Y', strtotime($fechaFin)) . '   |   Fecha: ' . date('d/m/Y') . '   |   Hora: ' . date('H:i'));

    $sheet->getStyle("A1:{$lastColumnLetter}1")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0B5D57']],
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
    ]);
    $sheet->getRowDimension(1)->setRowHeight(46);

    $sheet->getStyle("A2:{$lastColumnLetter}2")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDF2EE']],
        'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '0B5D57']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]
    ]);
    $sheet->getRowDimension(2)->setRowHeight(34);

    $sheet->getStyle("A3:{$lastColumnLetter}4")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F6FBFA']],
        'font' => ['size' => 11, 'color' => ['rgb' => '26433F']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]
    ]);

    $sheet->getStyle("A1:{$lastColumnLetter}4")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C8DFDA');

    $currentRow = 6;

    $groups = [];
    foreach ($rows as $row) {
        $patio = $row['patio_nombre'] ?? '';
        $bloque = $row['bloque_numero'] ?? '';
        $label = ($patio !== '' && $bloque !== '') ? "{$patio} - Bloque {$bloque}" : 'General';
        $groups[$label][] = $row;
    }

    if (empty($groups)) {
        $sheet->mergeCells("A{$currentRow}:{$lastColumnLetter}{$currentRow}");
        $sheet->setCellValue("A{$currentRow}", 'No se encontraron registros para exportar.');
        $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
            'font' => ['italic' => true, 'size' => 12, 'color' => ['rgb' => '6B7F7B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
    } else {
        foreach ($groups as $groupLabel => $items) {
            $sheet->mergeCells("A{$currentRow}:{$lastColumnLetter}{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", $groupLabel);
            $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E4F4F0']],
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0B5D57']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C8DFDA']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
            ]);
            $sheet->getRowDimension($currentRow)->setRowHeight(22);
            $currentRow++;

            foreach ($visibleHeaders as $columnIndex => $header) {
                $cell = Coordinate::stringFromColumnIndex($columnIndex + 1) . $currentRow;
                $sheet->setCellValue($cell, $header);
            }
            $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0E746B']],
                'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '0B5D57']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
            ]);
            $sheet->getRowDimension($currentRow)->setRowHeight(24);
            $headerRow = $currentRow;
            $currentRow++;

            foreach ($items as $row) {
                foreach ($visibleKeys as $columnIndex => $key) {
                    $columnLetter = Coordinate::stringFromColumnIndex($columnIndex + 1);
                    $cell = $columnLetter . $currentRow;
                    $value = $row[$key] ?? '';
                    $sheet->setCellValue($cell, $value);
                }

                $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E6E3']]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ($currentRow % 2 === 0 ? 'FFFFFF' : 'F9FCFB')]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
                ]);
                $currentRow++;
            }

            $sheet->mergeCells("A{$currentRow}:" . Coordinate::stringFromColumnIndex(max(count($visibleHeaders) - 1, 1)) . "{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", 'Total contenedores');
            $sheet->setCellValue("{$lastColumnLetter}{$currentRow}", count($items));
            $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF7F5']],
                'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '123A36']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E6E3']]]
            ]);
            $sheet->getStyle("{$lastColumnLetter}{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $currentRow += 2;
        }
    }

    foreach (range(1, count($visibleHeaders)) as $columnIndex) {
        $columnLetter = Coordinate::stringFromColumnIndex($columnIndex);
        $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
    }

    $sheet->getStyle("A1:{$lastColumnLetter}{$sheet->getHighestRow()}")->getAlignment()->setWrapText(true);

    $filename = "Inventario_" . date('Ymd_His') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
} catch (Exception $e) {
    if ($modo === 'preview') echo json_encode(['error' => $e->getMessage()]);
    else echo "Error: " . $e->getMessage();
}
?>
