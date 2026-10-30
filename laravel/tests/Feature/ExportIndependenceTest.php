<?php

namespace Tests\Feature;

use App\Services\ReportService;
use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExportIndependenceTest extends TestCase
{
    public function test_export_libraries_are_loaded_from_laravel_vendor(): void
    {
        foreach ([Dompdf::class, Spreadsheet::class] as $class) {
            $path = str_replace('\\', '/', (new \ReflectionClass($class))->getFileName());
            $this->assertStringStartsWith(str_replace('\\', '/', base_path('vendor/')), $path);
        }
    }

    public function test_excel_preserves_headers_logo_and_literal_cells_without_legacy_helpers(): void
    {
        $reports = app(ReportService::class);
        foreach (['inventario', 'ingresos', 'despachos', 'auditorias', 'reparaciones', 'costos'] as $type) {
            $headers = $reports->operationalHeaders($type);
            $book = $reports->spreadsheet('Reporte '.$type, ['Sucursal' => 'Sucursal de prueba'], $headers,
                [array_fill(0, count($headers), '=1+1')]);
            $sheet = $book->getActiveSheet();
            $this->assertSame('SISTEMA PORTUARIO PROPORTSA', $sheet->getCell('A1')->getValue());
            $this->assertSame($headers[0], $sheet->getCell('A6')->getValue());
            $this->assertSame('=1+1', $sheet->getCell('A7')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A7')->getDataType());
            $this->assertCount(1, $sheet->getDrawingCollection());
            $path = tempnam(sys_get_temp_dir(), 'proportsa-export-');
            try {
                (new Xlsx($book))->save($path);
                $loaded = IOFactory::load($path);
                $this->assertSame('=1+1', $loaded->getActiveSheet()->getCell('A7')->getValue());
                $loaded->disconnectWorksheets();
            } finally {
                $book->disconnectWorksheets();
                unlink($path);
            }
        }
    }

    public function test_pdf_renders_populated_and_empty_reports_with_local_assets(): void
    {
        foreach ([[['SUDU5353440', 'Sin novedades']], []] as $rows) {
            $pdf = app(ReportService::class)->pdfBinary('Reporte de prueba', 'Sucursal de prueba', '2026-10-01',
                '2026-10-29', ['Contenedor', 'Observaciones'], $rows);
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertGreaterThan(1000, strlen($pdf));
        }
    }
}
