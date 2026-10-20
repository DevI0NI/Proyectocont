<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function report_excel_get_sucursal_name(PDO $pdo, ?int $sucursalId): string
{
    if (!$sucursalId) {
        return 'No definida';
    }

    $stmt = $pdo->prepare("SELECT nombre FROM sucursales WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $sucursalId]);
    return $stmt->fetchColumn() ?: 'No definida';
}

function report_excel_bootstrap(string $title, array $meta, int $columnCount, string $sheetTitle = 'Reporte'): array
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr($sheetTitle, 0, 31));
    $sheet->getDefaultRowDimension()->setRowHeight(22);

    $lastColumnLetter = Coordinate::stringFromColumnIndex(max($columnCount, 1));

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
    $sheet->setCellValue('A2', mb_strtoupper($title));

    $metaValues = array_values(array_filter($meta, static fn ($value) => $value !== null && $value !== ''));
    $sheet->setCellValue('A3', implode('   |   ', array_slice($metaValues, 0, 2)));
    $sheet->setCellValue('A4', implode('   |   ', array_slice($metaValues, 2)));

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

    return [$spreadsheet, $sheet, 6, $lastColumnLetter];
}

function report_excel_render_grouped_sheet(Worksheet $sheet, int $startRow, array $headers, array $dataKeys, array $rows, array $options = []): int
{
    $groupFields = $options['group_fields'] ?? ['patio_nombre', 'bloque_numero'];
    $hiddenHeaders = $options['hidden_headers'] ?? ['Patio', 'Bloque'];
    $totalLabel = $options['total_label'] ?? null;

    $visibleHeaders = [];
    $visibleKeys = [];
    foreach ($headers as $index => $header) {
        if (in_array($header, $hiddenHeaders, true)) {
            continue;
        }
        $visibleHeaders[] = $header;
        $visibleKeys[] = $dataKeys[$index] ?? '';
    }

    if (empty($visibleHeaders)) {
        $visibleHeaders = ['Dato'];
        $visibleKeys = [array_values($dataKeys)[0] ?? ''];
    }

    $lastColumnLetter = Coordinate::stringFromColumnIndex(max(count($visibleHeaders), 1));
    $currentRow = $startRow;

    $groups = [];
    foreach ($rows as $row) {
        $a = $row[$groupFields[0]] ?? '';
        $b = $row[$groupFields[1]] ?? '';
        $label = ($a !== '' && $b !== '') ? "{$a} - Bloque {$b}" : 'General';
        $groups[$label][] = $row;
    }

    if (empty($groups)) {
        $sheet->mergeCells("A{$currentRow}:{$lastColumnLetter}{$currentRow}");
        $sheet->setCellValue("A{$currentRow}", 'No se encontraron registros para exportar.');
        $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
            'font' => ['italic' => true, 'size' => 12, 'color' => ['rgb' => '6B7F7B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
        return $currentRow;
    }

    foreach ($groups as $groupLabel => $items) {
        $sheet->mergeCells("A{$currentRow}:{$lastColumnLetter}{$currentRow}");
        $sheet->setCellValue("A{$currentRow}", $groupLabel);
        $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E4F4F0']],
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0B5D57']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C8DFDA']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
        ]);
        $currentRow++;

        foreach ($visibleHeaders as $columnIndex => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1) . $currentRow, $header);
        }
        $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0E746B']],
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '0B5D57']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
        ]);
        $sheet->getRowDimension($currentRow)->setRowHeight(24);
        $currentRow++;

        foreach ($items as $row) {
            foreach ($visibleKeys as $columnIndex => $key) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1) . $currentRow, $row[$key] ?? '');
            }
            $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E6E3']]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ($currentRow % 2 === 0 ? 'FFFFFF' : 'F9FCFB')]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
            ]);
            $currentRow++;
        }

        if ($totalLabel) {
            $mergeTo = Coordinate::stringFromColumnIndex(max(count($visibleHeaders) - 1, 1));
            $sheet->mergeCells("A{$currentRow}:{$mergeTo}{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", $totalLabel);
            $sheet->setCellValue("{$lastColumnLetter}{$currentRow}", count($items));
            $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF7F5']],
                'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '123A36']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E6E3']]]
            ]);
            $sheet->getStyle("{$lastColumnLetter}{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $currentRow++;
        }

        $currentRow += 1;
    }

    foreach (range(1, count($visibleHeaders)) as $columnIndex) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
    }

    return $currentRow;
}

function report_excel_render_simple_sheet(Worksheet $sheet, int $startRow, array $headers, array $dataKeys, array $rows): int
{
    $lastColumnLetter = Coordinate::stringFromColumnIndex(max(count($headers), 1));
    $currentRow = $startRow;

    if (empty($rows)) {
        $sheet->mergeCells("A{$currentRow}:{$lastColumnLetter}{$currentRow}");
        $sheet->setCellValue("A{$currentRow}", 'No se encontraron registros para exportar.');
        $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
            'font' => ['italic' => true, 'size' => 12, 'color' => ['rgb' => '6B7F7B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
        return $currentRow;
    }

    foreach ($headers as $columnIndex => $header) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1) . $currentRow, $header);
    }
    $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0E746B']],
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '0B5D57']]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
    ]);
    $sheet->getRowDimension($currentRow)->setRowHeight(24);
    $currentRow++;

    foreach ($rows as $row) {
        foreach ($dataKeys as $columnIndex => $key) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1) . $currentRow, $row[$key] ?? '');
        }
        $sheet->getStyle("A{$currentRow}:{$lastColumnLetter}{$currentRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E6E3']]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ($currentRow % 2 === 0 ? 'FFFFFF' : 'F9FCFB')]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
        ]);
        $currentRow++;
    }

    foreach (range(1, count($headers)) as $columnIndex) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
    }

    return $currentRow;
}

function report_excel_output(Spreadsheet $spreadsheet, string $filename): void
{
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->getStyle('A1:' . $sheet->getHighestColumn() . $sheet->getHighestRow())->getAlignment()->setWrapText(true);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
