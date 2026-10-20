<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function report_pdf_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function report_pdf_logo_data_uri(): ?string
{
    if (!extension_loaded('gd')) {
        return null;
    }

    $logoPath = __DIR__ . '/../imagenes/IPSA.png';
    if (!is_file($logoPath)) {
        return null;
    }

    $content = file_get_contents($logoPath);
    if ($content === false) {
        return null;
    }

    return 'data:image/png;base64,' . base64_encode($content);
}

function report_pdf_grouped_html(array $headers, array $dataKeys, array $rows, array $options = []): string
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
        return '<p class="empty-state">No se encontraron registros para exportar.</p>';
    }

    $groups = [];
    foreach ($rows as $row) {
        $first = $row[$groupFields[0]] ?? '';
        $second = $row[$groupFields[1]] ?? '';
        $label = ($first !== '' && $second !== '') ? "{$first} - Bloque {$second}" : 'General';
        $groups[$label][] = $row;
    }

    if (empty($groups)) {
        return '<p class="empty-state">No se encontraron registros para exportar.</p>';
    }

    $html = '';
    foreach ($groups as $label => $items) {
        $html .= '<section class="group-block">';
        $html .= '<div class="group-title">' . report_pdf_escape($label) . '</div>';
        $html .= '<table class="report-table"><thead><tr>';
        foreach ($visibleHeaders as $header) {
            $html .= '<th>' . report_pdf_escape($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($items as $row) {
            $html .= '<tr>';
            foreach ($visibleKeys as $key) {
                $html .= '<td>' . report_pdf_escape($row[$key] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }

        if ($totalLabel) {
            $html .= '<tr class="total-row">';
            $html .= '<td colspan="' . max(count($visibleHeaders) - 1, 1) . '">' . report_pdf_escape($totalLabel) . '</td>';
            $html .= '<td class="total-count">' . count($items) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></section>';
    }

    return $html;
}

function report_pdf_simple_html(array $headers, array $dataKeys, array $rows): string
{
    if (empty($rows)) {
        return '<p class="empty-state">No se encontraron registros para exportar.</p>';
    }

    $html = '<table class="report-table"><thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . report_pdf_escape($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($dataKeys as $key) {
            $html .= '<td>' . report_pdf_escape($row[$key] ?? '') . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    return $html;
}

function report_pdf_output(string $filename, string $title, array $meta, string $contentHtml): void
{
    $logo = report_pdf_logo_data_uri();

    $metaHtml = '';
    foreach ($meta as $label => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $metaHtml .= '<div class="meta-item"><span class="meta-label">' . report_pdf_escape($label) . ':</span> ' . report_pdf_escape($value) . '</div>';
    }

    $html = '<!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 28px 24px; }
            body { font-family: DejaVu Sans, Arial, sans-serif; color: #17322e; font-size: 11px; }
            .header-band { background: #0b5d57; color: #fff; padding: 14px 18px; border-radius: 12px 12px 0 0; }
            .header-table { width: 100%; border-collapse: collapse; }
            .header-logo { width: 84px; vertical-align: middle; }
            .header-title { text-align: center; font-size: 21px; font-weight: bold; letter-spacing: .4px; }
            .title-box { background: #eef7f5; border: 1px solid #cde1dc; border-top: none; padding: 16px 18px 14px; border-radius: 0 0 12px 12px; margin-bottom: 18px; }
            .report-title { font-size: 19px; font-weight: bold; color: #0b5d57; margin: 0 0 10px; }
            .meta-item { margin: 2px 0; color: #36514c; }
            .meta-label { font-weight: bold; }
            .group-block { margin-bottom: 16px; page-break-inside: avoid; }
            .group-title { background: #e4f4f0; border: 1px solid #cde1dc; color: #0b5d57; font-weight: bold; padding: 8px 10px; border-radius: 8px 8px 0 0; }
            .report-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
            .report-table th { background: #0e746b; color: #fff; font-size: 10px; font-weight: bold; padding: 8px; border: 1px solid #0b5d57; }
            .report-table td { border: 1px solid #d6e4e1; padding: 7px 8px; vertical-align: top; word-wrap: break-word; }
            .report-table tbody tr:nth-child(even) td { background: #f8fbfb; }
            .total-row td { background: #eef7f5 !important; font-weight: bold; }
            .total-count { text-align: center; }
            .empty-state { text-align: center; color: #6a7c78; font-style: italic; margin-top: 32px; }
        </style>
    </head>
    <body>
        <div class="header-band">
            <table class="header-table">
                <tr>
                    <td class="header-logo">' . ($logo ? '<img src="' . $logo . '" style="height:52px;">' : '') . '</td>
                    <td class="header-title">SISTEMA PORTUARIO PROPORTSA</td>
                </tr>
            </table>
        </div>
        <div class="title-box">
            <div class="report-title">' . report_pdf_escape($title) . '</div>
            ' . $metaHtml . '
        </div>
        ' . $contentHtml . '
    </body>
    </html>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}
