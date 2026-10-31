<?php

namespace App\Services;

use Dompdf\Dompdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class ReportService
{
    private const ACTIVE = 1;

    public function isOperational(string $type): bool
    {
        return in_array($type, ['inventario', 'ingresos', 'despachos', 'auditorias', 'reparaciones', 'costos'], true);
    }

    /** @return array<int, string> */
    public function operationalHeaders(string $type): array
    {
        return match ($type) {
            'ingresos' => ['Fecha de ingreso', 'Contenedor', 'Ciclo', 'Naviera al ingresar', 'Auditora al ingresar', 'Registrado por'],
            'despachos' => ['Fecha de salida', 'Contenedor', 'EIR de salida', 'Patio de retiro', 'Bloque', 'Posicion', 'Naviera', 'Auditora', 'Resultado', 'Transportista', 'Piloto', 'Placa cabezal', 'Placa chasis', 'Registrado por', 'Observaciones'],
            'auditorias' => ['Fecha', 'Contenedor', 'Ciclo', 'Naviera del ingreso', 'Auditora del ingreso', 'Inspector', 'Resultado', 'Observaciones', 'Correccion revisada (ID)'],
            'reparaciones' => ['Fecha de inicio', 'Fecha de entrega', 'Contenedor', 'Ciclo', 'Orden', 'Intervencion', 'Mecanico', 'Tipo de reparacion', 'Estado', 'Diagnostico', 'Trabajo realizado', 'Solicitud de correccion'],
            'costos' => ['Fecha de inicio', 'Contenedor', 'Ciclo', 'Orden', 'Intervencion', 'Tipo de reparacion', 'Estado', 'Tarifa del servicio (GTQ)', 'Materiales (GTQ)', 'Total calculado (GTQ)', 'Calculo', 'Tipo de cambio USD/GTQ', 'Fecha del tipo de cambio'],
            default => ['Contenedor', 'Patio', 'Bloque', 'Posicion', 'Tipo', 'Modelo', 'Unidad', 'Estructura', 'IPSA', 'Naviera', 'Auditora', 'Auditoria externa', 'Fecha de ingreso'],
        };
    }

    /** @param array<string, mixed> $filters */
    public function operationalRows(int $branchId, string $type, array $filters, bool $export = false): Collection|LengthAwarePaginator
    {
        $query = $this->operationalQuery($branchId, $type, $filters);
        $result = $export ? $query->get() : $query->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();
        $records = $result instanceof LengthAwarePaginator ? $result->getCollection() : $result;
        $materials = $type === 'costos' ? DB::table('maintenance_materials')->whereIn('job_id', $records->whereNull('correction_id')->pluck('job_id'))->get()->groupBy('job_id') : collect();
        $map = function (object $row) use ($type, $materials): array {
            if (in_array($type, ['reparaciones', 'costos'], true)) {
                return $this->maintenanceValues($row, $type, $materials);
            }
            $values = array_values((array) $row);
            $dateIndex = $type === 'inventario' ? count($values) - 1 : 0;
            $values[$dateIndex] = $this->formatDate($values[$dateIndex]);

            return array_map(static fn ($value) => $value === null || $value === '' ? 'No registrado' : $value, $values);
        };

        return $result instanceof LengthAwarePaginator ? $result->through($map) : $result->map($map);
    }

    /** @param array<string, mixed> $filters */
    private function operationalQuery(int $branchId, string $type, array $filters): Builder
    {
        if (in_array($type, ['auditorias', 'reparaciones', 'costos'], true)) {
            $query = $type === 'auditorias' ? $this->auditHistoryQuery($branchId) : $this->maintenanceReportQuery($branchId);
            $query = DB::query()->fromSub($query, 'report_entries')->select('report_entries.*')
                ->whereBetween('fecha', [$filters['f_inicio'].' 00:00:00', $filters['f_fin'].' 23:59:59'])
                ->orderByDesc('fecha')->orderByDesc('entry_id');
            if (($filters['search'] ?? '') !== '') {
                $query->whereRaw("nombre LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%']);
            }
            foreach (['patio_ids' => 'patio_id', 'bloque_ids' => 'bloque_id'] as $filter => $column) {
                if (! empty($filters[$filter])) {
                    $query->whereIn($column, $filters[$filter]);
                }
            }

            return $type === 'auditorias' ? $query->select(['fecha', 'nombre', 'ciclo', 'naviera', 'auditora', 'inspector', 'resultado', 'observaciones', 'correccion']) : $query->orderBy('job_id')->orderBy('correction_id');
        }
        if ($type === 'inventario') {
            $query = DB::table('contenedores as c')->join('bloques as b', 'b.id', '=', 'c.bloque_id')
                ->join('patios as p', 'p.id', '=', 'b.patio_id')
                ->leftJoin('tipos_unidad as t', 't.id', '=', 'c.tipo_unidad_id')->leftJoin('modelos as m', 'm.id', '=', 'c.modelo_id')
                ->leftJoin('estatus_contenedores as eu', 'eu.id', '=', 'c.estatus_unidad_id')
                ->leftJoin('estatus_contenedores as eb', 'eb.id', '=', 'c.estatus_box_id')->leftJoin('estatus_ipsa as ei', 'ei.id', '=', 'c.estatus_ipsa_id')
                ->leftJoin('container_ingress_cycles as ic', function ($join): void {
                    $join->on('ic.id', '=', 'c.current_ingress_cycle_id')->on('ic.container_id', '=', 'c.id');
                })->leftJoin('pyp_auditoria as pa', function ($join): void {
                    $join->on('pa.contenedor_id', '=', 'c.id')->where('pa.estado_id', self::ACTIVE);
                })->where('p.sucursal_id', $branchId)->where('c.estado_id', self::ACTIVE)
                ->select(['c.nombre', 'p.nombre as patio', 'b.numero as bloque', 'c.posicion', 't.nombre as tipo', 'm.nombre as modelo',
                    'eu.nombre as unidad', 'eb.nombre as estructura', 'ei.nombre as ipsa'])
                ->selectRaw('COALESCE(ic.shipping_line_name, c.naviera) as naviera, ic.audit_company_name as auditora')
                ->selectRaw('COALESCE('.ContainerAuditAssignment::statusSql().', '.ContainerAuditAssignment::noticeSql().') as auditoria')
                ->addSelect('c.fecha_ingreso')->orderBy('c.nombre')->orderBy('c.id');
            $query->when($filters['patio_ids'] ?? ($filters['patio_id'] ?? null), fn (Builder $q, mixed $ids) => $q->whereIn('p.id', (array) $ids))
                ->when($filters['bloque_ids'] ?? ($filters['bloque_id'] ?? null), fn (Builder $q, mixed $ids) => $q->whereIn('b.id', (array) $ids));
        } else {
            $query = DB::table('container_assignment_events as e')
                ->join('container_ingress_cycles as cycle', function ($join): void {
                    $join->on('cycle.id', '=', 'e.ingress_cycle_id')->on('cycle.container_id', '=', 'e.container_id');
                })->join('contenedores as c', 'c.id', '=', 'e.container_id')
                ->leftJoin('users as u', 'u.id', '=', 'e.user_id')->where('cycle.branch_id', $branchId);
            if ($type === 'ingresos') {
                $query->whereIn('e.event_type', ['ingress', 'reentry'])
                    ->whereBetween('cycle.entered_at', [$filters['f_inicio'].' 00:00:00', $filters['f_fin'].' 23:59:59'])
                    ->select(['cycle.entered_at as fecha', 'c.nombre', 'cycle.id as ciclo'])
                    ->selectRaw("NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.cycle.shipping_line_name')), 'null') as naviera")
                    ->selectRaw("NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.cycle.audit_company_name')), 'null') as auditora")
                    ->addSelect('u.username')->orderByDesc('cycle.entered_at')->orderByDesc('e.id');
            } else {
                $query->where('e.event_type', 'dispatch')->whereBetween('e.created_at', [$filters['f_inicio'].' 00:00:00', $filters['f_fin'].' 23:59:59'])
                    ->select('e.created_at as fecha');
                foreach (['equipo', 'numero', 'patio', 'bloque', 'posicion', 'naviera', 'auditora', 'auditoria', 'transportista', 'conductor', 'placa_cabezal', 'placa_chasis', 'usuario', 'referencia'] as $key) {
                    $query->selectRaw("NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.document.{$key}')), 'null') as {$key}");
                }
                $query->orderByDesc('e.created_at')->orderByDesc('e.id');
            }
        }
        $searchColumn = $type === 'despachos' ? "JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.document.equipo'))" : 'c.nombre';
        if (($filters['search'] ?? '') !== '') {
            $query->whereRaw($searchColumn." LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%']);
        }

        return $query;
    }

    private function auditHistoryQuery(int $branchId): Builder
    {
        $query = DB::table('container_assignment_events as e')
            ->join('container_ingress_cycles as cycle', function ($join): void {
                $join->on('cycle.id', '=', 'e.ingress_cycle_id')->on('cycle.container_id', '=', 'e.container_id');
            })->join('contenedores as c', 'c.id', '=', 'e.container_id')
            ->leftJoin('bloques as b', 'b.id', '=', 'c.bloque_id')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('cycle.branch_id', $branchId)->where('e.event_type', 'external_audit_status')
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.audit.estatus_pyp'))"), ['Aprobado', 'Rechazado'])
            ->selectRaw('DATE_SUB(e.created_at, INTERVAL 6 HOUR) as fecha, e.id as entry_id')
            ->addSelect(['c.nombre', 'cycle.id as ciclo', 'cycle.shipping_line_name as naviera', 'cycle.audit_company_name as auditora', 'b.patio_id', 'b.id as bloque_id'])
            ->selectRaw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.audit.inspector_name')), 'null'), u.username) as inspector");
        foreach (['resultado' => 'estatus_pyp', 'observaciones' => 'informacion_adicional', 'correccion' => 'maintenance_correction_id'] as $column => $key) {
            $query->selectRaw("NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.after_data, '$.audit.{$key}')), 'null') as {$column}");
        }

        $legacy = DB::table('pyp_auditoria as pa')->join('container_ingress_cycles as cycle', function ($join): void {
            $join->on('cycle.id', '=', 'pa.ingress_cycle_id')->on('cycle.container_id', '=', 'pa.contenedor_id');
        })->join('contenedores as c', 'c.id', '=', 'pa.contenedor_id')->leftJoin('bloques as b', 'b.id', '=', 'c.bloque_id')
            ->leftJoin('users as u', 'u.id', '=', 'pa.usuario_id')->where('cycle.branch_id', $branchId)
            ->whereIn('pa.estatus_pyp', ['Aprobado', 'Rechazado'])
            ->whereNotExists(fn (Builder $events) => $events->selectRaw('1')->from('container_assignment_events as existing')
                ->whereColumn('existing.container_id', 'pa.contenedor_id')->whereColumn('existing.ingress_cycle_id', 'pa.ingress_cycle_id')
                ->where('existing.event_type', 'external_audit_status')->whereColumn('existing.created_at', 'pa.fecha_registro')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(existing.after_data, '$.audit.estatus_pyp')) = pa.estatus_pyp")
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(existing.after_data, '$.audit.external_audit_company_id')) = pa.external_audit_company_id"))
            ->selectRaw('DATE_SUB(pa.fecha_registro, INTERVAL 6 HOUR) as fecha, -pa.contenedor_id as entry_id')
            ->addSelect(['c.nombre', 'cycle.id as ciclo', 'cycle.shipping_line_name as naviera', 'cycle.audit_company_name as auditora', 'b.patio_id', 'b.id as bloque_id',
                'u.username as inspector', 'pa.estatus_pyp as resultado', 'pa.informacion_adicional as observaciones'])
            ->selectRaw('NULL as correccion');
        $columns = ['fecha', 'entry_id', 'nombre', 'ciclo', 'naviera', 'auditora', 'patio_id', 'bloque_id', 'inspector', 'resultado', 'observaciones', 'correccion'];
        $normalize = static function (Builder $source) use ($columns): Builder {
            $normalized = DB::query()->fromSub($source, 'audit_source');
            foreach ($columns as $column) {
                if (in_array($column, ['nombre', 'naviera', 'auditora', 'inspector', 'resultado', 'observaciones'], true)) {
                    $normalized->selectRaw('CONVERT(audit_source.'.$column.' USING utf8mb4) COLLATE utf8mb4_unicode_ci as '.$column);
                } else {
                    $normalized->addSelect('audit_source.'.$column);
                }
            }

            return $normalized;
        };

        return $normalize($query)->unionAll($normalize($legacy));
    }

    private function maintenanceReportQuery(int $branchId): Builder
    {
        $interventions = DB::table('maintenance_jobs')->selectRaw('id as job_id, NULL as correction_id')
            ->where('branch_id', $branchId)->unionAll(DB::table('maintenance_corrections as correction')
            ->join('maintenance_jobs as job', 'job.id', '=', 'correction.job_id')->where('job.branch_id', $branchId)
            ->select(['job.id as job_id', 'correction.id as correction_id']));

        return DB::query()->fromSub($interventions, 'intervention')
            ->join('maintenance_jobs as job', 'job.id', '=', 'intervention.job_id')
            ->leftJoin('maintenance_corrections as correction', 'correction.id', '=', 'intervention.correction_id')
            ->join('contenedores as c', 'c.id', '=', 'job.container_id')->leftJoin('bloques as b', 'b.id', '=', 'c.bloque_id')
            ->leftJoin('users as mechanic', 'mechanic.id', '=', 'job.mechanic_id')
            ->leftJoin('container_ingress_cycles as cycle', function ($join) use ($branchId): void {
                $join->on('cycle.id', '=', 'job.ingress_cycle_id')->on('cycle.container_id', '=', 'job.container_id')->where('cycle.branch_id', $branchId);
            })->where('job.branch_id', $branchId)->where(fn (Builder $q) => $q->whereNull('job.ingress_cycle_id')->orWhereNotNull('cycle.id'))
            ->select(['intervention.job_id', 'intervention.correction_id', 'c.nombre', 'cycle.id as ciclo', 'mechanic.username as mecanico',
                'b.patio_id', 'b.id as bloque_id', 'correction.number', 'correction.reason', 'correction.repair_service_snapshot', 'correction.materials',
                'job.service_unit_cost', 'job.service_currency', 'job.usd_gtq_rate', 'job.exchange_rate_date'])
            ->selectRaw('DATE_SUB(COALESCE(correction.created_at, job.created_at), INTERVAL 6 HOUR) as fecha, COALESCE(correction.id, job.id) as entry_id')
            ->selectRaw('CASE WHEN correction.id IS NULL THEN job.submitted_at ELSE correction.submitted_at END as submitted_at')
            ->selectRaw('CASE WHEN correction.id IS NULL THEN job.status ELSE correction.status END as status')
            ->selectRaw('CASE WHEN correction.id IS NULL THEN job.diagnosis ELSE correction.diagnosis END as diagnosis')
            ->selectRaw('CASE WHEN correction.id IS NULL THEN job.work_performed ELSE correction.work_performed END as work_performed')
            ->addSelect('job.repair_type');
    }

    private function maintenanceValues(object $row, string $type, Collection $materials): array
    {
        $isCorrection = $row->correction_id !== null;
        $snapshot = $isCorrection ? json_decode($row->repair_service_snapshot ?? '{}', true) : [];
        $intervention = $isCorrection ? 'Correccion '.$row->number : 'Reparacion original';
        $status = $isCorrection ? (MaintenanceCorrections::STATES[$row->status] ?? $row->status) : match ($row->status) {
            'pending_review' => 'Pendiente de confirmacion', 'completed' => 'Confirmada',
            default => MaintenanceWorkflow::STATES[$row->status] ?? $row->status,
        };
        $repairType = $isCorrection ? ($snapshot['name'] ?? 'No registrado') : ($row->repair_type ?: 'No registrado');
        if ($type === 'reparaciones') {
            return [$this->formatDate($row->fecha), $row->submitted_at ? $this->formatDate(Carbon::parse($row->submitted_at)->subHours(6)) : 'Sin entregar',
                $row->nombre, $row->ciclo ?? 'Sin ciclo', $row->job_id, $intervention, $row->mecanico ?? 'No registrado', $repairType, $status,
                $row->diagnosis ?? '', $row->work_performed ?? '', $isCorrection ? $row->reason : ''];
        }
        $costJob = $isCorrection ? (object) ['service_unit_cost' => $snapshot['unit_cost'] ?? null, 'service_currency' => $snapshot['currency'] ?? null,
            'usd_gtq_rate' => $snapshot['exchange']['usd_gtq_rate'] ?? null] : $row;
        $costMaterials = $isCorrection ? array_map(static fn (array $item): object => (object) $item, json_decode($row->materials ?? '[]', true)) : $materials->get($row->job_id, collect());
        $cost = app(MaintenanceCost::class)->totals($costJob, $costMaterials);

        return [$this->formatDate($row->fecha), $row->nombre, $row->ciclo ?? 'Sin ciclo', $row->job_id, $intervention, $repairType, $status,
            $cost['labor'] === null ? 'Pendiente' : $cost['labor'] / 100, $cost['materials'] / 100, $cost['total'] / 100, $cost['incomplete'] ? 'Parcial: faltan precios o conversion' : ($row->submitted_at ? 'Registrado' : 'Estimado (borrador)'),
            $costJob->usd_gtq_rate ?? 'No requerido / no registrado', $isCorrection ? ($snapshot['exchange']['exchange_rate_date'] ?? 'No registrada') : ($row->exchange_rate_date ?? 'No registrada')];
    }

    public function rows(int $branchId, string $type, string $start, string $end, int|array|null $patioId, int|array|null $blockId, ?int $containerId, ?int $perPage = null): array
    {
        return match ($type) {
            'movimientos' => $this->movementRows($branchId, $start, $end, $patioId, $blockId, $perPage),
            'despachos' => $this->dispatchRows($branchId, $start, $end, $patioId, $blockId),
            'auditorias' => $this->auditRows($branchId, $start, $end, $patioId, $blockId),
            'traslados' => $this->transferRows($branchId, $start, $end, $patioId, $blockId, $perPage),
            'cambios' => $this->changeRows($branchId, $start, $end, $patioId, $blockId, $perPage),
            'historial' => $this->historyRows($branchId, $containerId, $perPage),
            default => $this->inventoryRows($branchId, $start, $end, $patioId, $blockId),
        };
    }

    public function containersForBranch(int $branchId)
    {
        return DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->where('p.sucursal_id', $branchId)
            ->orderBy('c.nombre')
            ->get(['c.id', 'c.nombre', 'c.estado_id']);
    }

    public function title(string $type): string
    {
        return match ($type) {
            'ingresos' => 'Ingresos por Ciclo',
            'movimientos' => 'Bitacora de Movimientos',
            'despachos' => 'Despachos con EIR de Salida',
            'auditorias' => 'Auditorias externas',
            'reparaciones' => 'Reparaciones y Correcciones',
            'costos' => 'Costos de Mantenimiento',
            'traslados' => 'Reporte de Traslados',
            'cambios' => 'Detalle de Cambios',
            'historial' => 'Historial de Contenedor',
            default => 'Inventario Activo',
        };
    }

    public function safeFilename(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?: 'reporte';
    }

    /**
     * @param  array<string, string>  $meta
     * @param  list<string>  $headers
     * @param  iterable<array<array-key, mixed>>  $rows
     */
    public function spreadsheet(string $title, array $meta, array $headers, iterable $rows): Spreadsheet
    {
        $labels = [];
        foreach ($meta as $label => $value) {
            $labels[] = $label.': '.$value;
        }
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reporte');
        $sheet->getDefaultRowDimension()->setRowHeight(22);
        $headerRow = 6;
        $lastColumn = Coordinate::stringFromColumnIndex(max(count($headers), 1));
        $logoPath = public_path('imagenes/IPSA.png');
        if (extension_loaded('gd') && is_file($logoPath)) {
            $drawing = new Drawing;
            $drawing->setName('Logo IPSA');
            $drawing->setDescription('Logo de la empresa');
            $drawing->setPath($logoPath);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(8);
            $drawing->setOffsetY(6);
            $drawing->setHeight(52);
            $drawing->setWorksheet($sheet);
        }
        for ($row = 1; $row <= 4; $row++) {
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        }
        $sheet->setCellValueExplicit('A1', 'SISTEMA PORTUARIO PROPORTSA', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A2', mb_strtoupper($title), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A3', implode('   |   ', array_slice($labels, 0, 2)), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A4', implode('   |   ', array_slice($labels, 2)), DataType::TYPE_STRING);
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '0B5D57']],
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(46);
        $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DDF2EE']],
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '0B5D57']],
            'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(34);
        $sheet->getStyle("A3:{$lastColumn}4")->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F6FBFA']],
            'font' => ['size' => 11, 'color' => ['rgb' => '26433F']],
            'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
        ]);
        $sheet->getStyle("A1:{$lastColumn}4")->getBorders()->getOutline()->setBorderStyle('thin')->getColor()->setRGB('C8DFDA');
        $sheet->getRowDimension(3)->setRowHeight(30);
        $sheet->getRowDimension(4)->setRowHeight(34);
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$headerRow, $header);
        }
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '0E746B']],
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '0B5D57']]],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(30);
        $rowNumber = $headerRow + 1;
        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $value) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1).$rowNumber,
                    $value, is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->applyFromArray([
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $rowNumber % 2 === 0 ? 'FFFFFF' : 'F9FCFB']],
                'font' => ['size' => 11, 'color' => ['rgb' => '17322E']],
                'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'D9E6E3']]],
                'alignment' => ['vertical' => 'top'],
            ]);
            $sheet->getRowDimension($rowNumber)->setRowHeight(-1);
            $rowNumber++;
        }
        if ($rowNumber === $headerRow + 1) {
            $sheet->mergeCells("A{$rowNumber}:{$lastColumn}{$rowNumber}");
            $sheet->setCellValue('A'.$rowNumber, 'No se encontraron registros para exportar.');
        }
        $sheet->getStyle('A1:'.$lastColumn.max($headerRow + 1, $rowNumber - 1))->getAlignment()->setWrapText(true);
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}".max($headerRow, $rowNumber - 1));
        $sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(9)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $headerRow);
        foreach ($headers as $index => $header) {
            $width = str_contains(mb_strtolower($header), 'observaci') || str_contains(mb_strtolower($header), 'detalle') ? 46 : 25;
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth(count($headers) === 1 ? 65 : $width);
            if (str_contains($header, '(GTQ)')) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->getStyle($column.($headerRow + 1).':'.$column.max($headerRow + 1, $rowNumber - 1))->getNumberFormat()->setFormatCode('"Q "#,##0.00');
            }
        }

        return $spreadsheet;
    }

    /** @param array<string, string> $meta */
    public function pdfBinary(string $title, string $branchName, string $start, string $end, array $headers, $rows, array $meta = []): string
    {
        $meta = $meta ?: ['Sucursal' => $branchName, 'Periodo' => $start.' al '.$end, 'Generado' => now('America/Guatemala')->format('d/m/Y H:i')];
        $logoPath = public_path('imagenes/IPSA.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath)) : null;
        $html = view('reports.pdf', ['title' => $title, 'meta' => $meta, 'logo' => $logo,
            'headers' => $headers, 'rows' => $rows])->render();
        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->getCanvas()->page_text(720, 570, 'Pagina {PAGE_NUM} / {PAGE_COUNT}', null, 8, [0.21, 0.32, 0.30]);

        return $dompdf->output();
    }

    private function inventoryRows(int $branchId, string $start, string $end, int|array|null $patioId, int|array|null $blockId): array
    {
        $rows = DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('tipos_unidad as t', 'c.tipo_unidad_id', '=', 't.id')
            ->leftJoin('modelos as m', 'c.modelo_id', '=', 'm.id')
            ->leftJoin('estatus_contenedores as eu', 'c.estatus_unidad_id', '=', 'eu.id')
            ->leftJoin('estatus_contenedores as eb', 'c.estatus_box_id', '=', 'eb.id')
            ->leftJoin('estatus_ipsa as ei', 'c.estatus_ipsa_id', '=', 'ei.id')
            ->leftJoin('pyp_auditoria as pa', function ($join): void {
                $join->on('c.id', '=', 'pa.contenedor_id')->where('pa.estado_id', self::ACTIVE);
            })
            ->where('c.estado_id', self::ACTIVE)
            ->where('p.sucursal_id', $branchId)
            ->whereBetween('c.fecha_ingreso', [$start.' 00:00:00', $end.' 23:59:59'])
            ->when($patioId, fn ($query) => $query->whereIn('p.id', (array) $patioId))
            ->when($blockId, fn ($query) => $query->whereIn('b.id', (array) $blockId))
            ->orderBy('p.nombre')
            ->orderBy('b.numero')
            ->orderBy('c.posicion')
            ->limit(100)
            ->get([
                'p.nombre as patio',
                'b.numero as bloque',
                'c.posicion',
                'c.nombre as contenedor',
                't.nombre as tipo',
                'm.nombre as modelo',
                'eu.nombre as estatus_unidad',
                'eb.nombre as estatus_box',
                'ei.nombre as estatus_ipsa',
                'pa.estatus_pyp',
                'c.fecha_ingreso',
            ]);

        return [
            ['Patio', 'Bloque', 'Posicion', 'Contenedor', 'Tipo', 'Modelo', 'Unidad', 'Box', 'IPSA', 'Auditoria externa', 'Ingreso'],
            $rows->map(fn ($row) => [
                $row->patio,
                $row->bloque,
                $row->posicion,
                $row->contenedor,
                $row->tipo ?? '-',
                $row->modelo ?? '-',
                $row->estatus_unidad ?? '-',
                $row->estatus_box ?? '-',
                $row->estatus_ipsa ?? '-',
                $row->estatus_pyp ?? 'Sin asignar',
                $this->formatDate($row->fecha_ingreso),
            ]),
        ];
    }

    private function movementRows(int $branchId, string $start, string $end, int|array|null $patioId, int|array|null $blockId, ?int $perPage = null): array
    {
        $rows = DB::table('logs_eventos as l')
            ->join('tipos_operacion as top', 'l.tipo_operacion_id', '=', 'top.id')
            ->join('users as u', 'l.usuario_id', '=', 'u.id')
            ->leftJoin('contenedores as c', 'l.contenedor_id', '=', 'c.id')
            ->leftJoin('tipos_unidad as tu', 'c.tipo_unidad_id', '=', 'tu.id')
            ->leftJoin('modelos as m', 'c.modelo_id', '=', 'm.id')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->whereBetween('l.fecha_evento', [$start.' 00:00:00', $end.' 23:59:59'])
            ->where('p.sucursal_id', $branchId)
            ->when($patioId, fn ($query) => $query->whereIn('p.id', (array) $patioId))
            ->when($blockId, fn ($query) => $query->whereIn('b.id', (array) $blockId))
            ->orderByDesc('l.fecha_evento')
            ->orderByDesc('l.id')
            ->select([
                'p.nombre as patio',
                'b.numero as bloque',
                'l.fecha_evento',
                'top.nombre as operacion',
                'c.nombre as contenedor',
                'tu.nombre as tipo',
                'm.nombre as modelo',
                'u.username as usuario',
                'l.observaciones',
            ]);

        return [
            ['Patio', 'Bloque', 'Fecha', 'Operacion', 'Contenedor', 'Tipo', 'Modelo', 'Usuario', 'Detalle'],
            $this->mapQuery($rows, fn ($row) => [
                $row->patio,
                $row->bloque,
                $this->formatDate($row->fecha_evento),
                $row->operacion,
                $row->contenedor ?? 'Eliminado',
                $row->tipo ?? '-',
                $row->modelo ?? '-',
                $row->usuario,
                $row->observaciones,
            ], $perPage),
        ];
    }

    private function dispatchRows(int $branchId, string $start, string $end, int|array|null $patioId, int|array|null $blockId): array
    {
        $rows = DB::table('logs_eventos as l')
            ->join('users as u', 'l.usuario_id', '=', 'u.id')
            ->leftJoin('contenedores as c', 'l.contenedor_id', '=', 'c.id')
            ->leftJoin('tipos_unidad as tu', 'c.tipo_unidad_id', '=', 'tu.id')
            ->leftJoin('modelos as m', 'c.modelo_id', '=', 'm.id')
            ->leftJoin('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->leftJoin('patios as p', 'b.patio_id', '=', 'p.id')
            ->where('l.tipo_operacion_id', 5)
            ->whereBetween('l.fecha_evento', [$start.' 00:00:00', $end.' 23:59:59'])
            ->where('u.sucursal_id', $branchId)
            ->when($patioId, fn ($query) => $query->whereIn('p.id', (array) $patioId))
            ->when($blockId, fn ($query) => $query->whereIn('b.id', (array) $blockId))
            ->orderByDesc('l.fecha_evento')
            ->limit(100)
            ->get([
                'p.nombre as patio',
                'b.numero as bloque',
                'c.nombre as contenedor',
                'tu.nombre as tipo',
                'm.nombre as modelo',
                'c.fecha_ingreso',
                'l.fecha_evento as fecha_despacho',
                'u.username as usuario',
                'l.observaciones',
            ]);

        return [
            ['Patio', 'Bloque', 'Contenedor', 'Tipo', 'Modelo', 'Ingreso', 'Despacho', 'Despachador', 'Observaciones'],
            $rows->map(fn ($row) => [
                $row->patio ?? 'Sin ubicacion',
                $row->bloque ?? '-',
                $row->contenedor ?? 'Eliminado',
                $row->tipo ?? '-',
                $row->modelo ?? '-',
                $this->formatDate($row->fecha_ingreso),
                $this->formatDate($row->fecha_despacho),
                $row->usuario,
                $row->observaciones,
            ]),
        ];
    }

    private function auditRows(int $branchId, string $start, string $end, int|array|null $patioId, int|array|null $blockId): array
    {
        $rows = DB::table('pyp_auditoria as pa')
            ->join('contenedores as c', 'pa.contenedor_id', '=', 'c.id')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('users as u', 'pa.usuario_id', '=', 'u.id')
            ->whereBetween('pa.fecha_registro', [$start.' 00:00:00', $end.' 23:59:59'])
            ->where('p.sucursal_id', $branchId)
            ->when($patioId, fn ($query) => $query->whereIn('p.id', (array) $patioId))
            ->when($blockId, fn ($query) => $query->whereIn('b.id', (array) $blockId))
            ->orderByDesc('pa.fecha_registro')
            ->limit(100)
            ->get([
                'p.nombre as patio',
                'b.numero as bloque',
                'c.nombre as contenedor',
                'pa.estatus_pyp',
                'pa.informacion_adicional',
                'pa.fecha_registro',
                'u.username as auditor',
            ]);

        return [
            ['Patio', 'Bloque', 'Contenedor', 'Estatus', 'Detalle', 'Fecha', 'Auditor'],
            $rows->map(fn ($row) => [
                $row->patio,
                $row->bloque,
                $row->contenedor,
                $row->estatus_pyp,
                $row->informacion_adicional,
                $this->formatDate($row->fecha_registro),
                $row->auditor ?? '-',
            ]),
        ];
    }

    private function transferRows(int $branchId, string $start, string $end, int|array|null $patioId, int|array|null $blockId, ?int $perPage = null): array
    {
        $rows = DB::table('logs_traslados as lt')
            ->join('logs_eventos as le', 'lt.evento_id', '=', 'le.id')
            ->leftJoin('contenedores as c', 'le.contenedor_id', '=', 'c.id')
            ->leftJoin('tipos_unidad as tu', 'c.tipo_unidad_id', '=', 'tu.id')
            ->join('users as u', 'le.usuario_id', '=', 'u.id')
            ->join('bloques as b_orig', 'lt.bloque_anterior_id', '=', 'b_orig.id')
            ->join('patios as p_orig', 'b_orig.patio_id', '=', 'p_orig.id')
            ->join('bloques as b_dest', 'lt.bloque_nuevo_id', '=', 'b_dest.id')
            ->join('patios as p_dest', 'b_dest.patio_id', '=', 'p_dest.id')
            ->whereBetween('le.fecha_evento', [$start.' 00:00:00', $end.' 23:59:59'])
            ->where('p_dest.sucursal_id', $branchId)
            ->whereNotNull('lt.bloque_anterior_id')
            ->when($patioId, fn ($query) => $query->whereIn('p_dest.id', (array) $patioId))
            ->when($blockId, fn ($query) => $query->whereIn('b_dest.id', (array) $blockId))
            ->orderByDesc('le.fecha_evento')
            ->orderByDesc('le.id')
            ->select([
                'le.fecha_evento',
                'c.nombre as contenedor',
                'tu.nombre as tipo',
                'p_orig.nombre as patio_origen',
                'b_orig.numero as bloque_origen',
                'lt.posicion_anterior',
                'p_dest.nombre as patio_destino',
                'b_dest.numero as bloque_destino',
                'lt.posicion_nueva',
                'u.username as usuario',
                'le.observaciones',
            ]);

        return [
            ['Fecha', 'Contenedor', 'Tipo', 'Origen', 'Destino', 'Operador', 'Detalle'],
            $this->mapQuery($rows, fn ($row) => [
                $this->formatDate($row->fecha_evento),
                $row->contenedor ?? 'Eliminado',
                $row->tipo ?? '-',
                "{$row->patio_origen} - B{$row->bloque_origen} (Pos {$row->posicion_anterior})",
                "{$row->patio_destino} - B{$row->bloque_destino} (Pos {$row->posicion_nueva})",
                $row->usuario,
                $row->observaciones,
            ], $perPage),
        ];
    }

    private function changeRows(int $branchId, string $start, string $end, int|array|null $patioId, int|array|null $blockId, ?int $perPage = null): array
    {
        $base = "
            SELECT * FROM (
                SELECT le.fecha_evento, IFNULL(c.nombre, 'Eliminado') AS contenedor, u.username AS usuario,
                       'Datos Generales' AS tipo_cambio, lm.campo_modificado AS campo,
                       lm.valor_anterior, lm.valor_nuevo, p.sucursal_id, p.id AS patio_id, b.id AS bloque_id
                FROM logs_modificaciones lm
                JOIN logs_eventos le ON lm.evento_id = le.id
                LEFT JOIN contenedores c ON le.contenedor_id = c.id
                LEFT JOIN users u ON le.usuario_id = u.id
                LEFT JOIN bloques b ON c.bloque_id = b.id
                LEFT JOIN patios p ON b.patio_id = p.id

                UNION ALL

                SELECT le.fecha_evento, IFNULL(c.nombre, 'Eliminado') AS contenedor, u.username AS usuario,
                       'Estatus Contenedor' AS tipo_cambio, te.nombre AS campo,
                       IFNULL(ea.nombre, 'N/A') AS valor_anterior, IFNULL(en.nombre, 'N/A') AS valor_nuevo,
                       p.sucursal_id, p.id AS patio_id, b.id AS bloque_id
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

                SELECT le.fecha_evento, IFNULL(c.nombre, 'Eliminado') AS contenedor, u.username AS usuario,
                       'Estatus IPSA' AS tipo_cambio, 'Condicion IPSA' AS campo,
                       IFNULL(ia.nombre, 'N/A') AS valor_anterior, IFNULL(inew.nombre, 'N/A') AS valor_nuevo,
                       p.sucursal_id, p.id AS patio_id, b.id AS bloque_id
                FROM logs_ipsa li
                JOIN logs_eventos le ON li.evento_id = le.id
                LEFT JOIN contenedores c ON le.contenedor_id = c.id
                LEFT JOIN users u ON le.usuario_id = u.id
                LEFT JOIN estatus_ipsa ia ON li.estatus_anterior_id = ia.id
                LEFT JOIN estatus_ipsa inew ON li.estatus_nuevo_id = inew.id
                LEFT JOIN bloques b ON c.bloque_id = b.id
                LEFT JOIN patios p ON b.patio_id = p.id
            ) AS changes
            WHERE changes.fecha_evento BETWEEN ? AND ?
              AND changes.sucursal_id = ?
        ";

        $params = [$start.' 00:00:00', $end.' 23:59:59', $branchId];
        foreach (['patio_id' => $patioId, 'bloque_id' => $blockId] as $column => $ids) {
            if ($ids) {
                $base .= ' AND changes.'.$column.' IN ('.implode(',', array_fill(0, count((array) $ids), '?')).')';
                array_push($params, ...(array) $ids);
            }
        }
        $rows = DB::query()->fromRaw('('.$base.') as report_changes', $params)->orderByDesc('fecha_evento')
            ->orderBy('contenedor')->orderBy('tipo_cambio')->orderBy('campo')->orderBy('valor_anterior')->orderBy('valor_nuevo');

        return [
            ['Fecha', 'Contenedor', 'Usuario', 'Tipo cambio', 'Campo', 'Anterior', 'Nuevo'],
            $this->mapQuery($rows, fn ($row) => [
                $this->formatDate($row->fecha_evento),
                $row->contenedor,
                $row->usuario,
                $row->tipo_cambio,
                $row->campo,
                $row->valor_anterior,
                $row->valor_nuevo,
            ], $perPage),
        ];
    }

    private function historyRows(int $branchId, ?int $containerId, ?int $perPage = null): array
    {
        if (! $containerId) {
            return [['Fecha', 'Operacion', 'Usuario', 'Detalle'], collect()];
        }

        $belongsToBranch = DB::table('contenedores as c')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->where('c.id', $containerId)
            ->where('p.sucursal_id', $branchId)
            ->exists();

        if (! $belongsToBranch) {
            return [['Fecha', 'Operacion', 'Usuario', 'Detalle'], collect()];
        }

        $rows = DB::table('logs_eventos as l')
            ->join('tipos_operacion as top', 'l.tipo_operacion_id', '=', 'top.id')
            ->join('users as u', 'l.usuario_id', '=', 'u.id')
            ->leftJoin('logs_traslados as lt', 'l.id', '=', 'lt.evento_id')
            ->leftJoin('bloques as b_ant', 'lt.bloque_anterior_id', '=', 'b_ant.id')
            ->leftJoin('patios as p_ant', 'b_ant.patio_id', '=', 'p_ant.id')
            ->leftJoin('bloques as b_new', 'lt.bloque_nuevo_id', '=', 'b_new.id')
            ->leftJoin('patios as p_new', 'b_new.patio_id', '=', 'p_new.id')
            ->where('l.contenedor_id', $containerId)
            ->orderBy('l.fecha_evento')
            ->orderBy('l.id')
            ->select([
                'l.fecha_evento',
                'top.nombre as operacion',
                'u.username as usuario',
                'l.observaciones',
                'p_ant.nombre as patio_anterior',
                'b_ant.numero as bloque_anterior',
                'lt.posicion_anterior',
                'p_new.nombre as patio_nuevo',
                'b_new.numero as bloque_nuevo',
                'lt.posicion_nueva',
            ]);

        return [
            ['Fecha', 'Operacion', 'Usuario', 'Detalle'],
            $this->mapQuery($rows, function ($row) {
                $detail = (string) $row->observaciones;
                if ($row->patio_nuevo) {
                    $origin = $row->patio_anterior
                        ? "{$row->patio_anterior} B{$row->bloque_anterior} P{$row->posicion_anterior}"
                        : 'Ingreso/Externo';
                    $detail .= " [Movido de: {$origin} -> A: {$row->patio_nuevo} B{$row->bloque_nuevo} P{$row->posicion_nueva}]";
                }

                return [
                    $this->formatDate($row->fecha_evento),
                    $row->operacion,
                    $row->usuario,
                    $detail,
                ];
            }, $perPage),
        ];
    }

    private function mapQuery(Builder $query, callable $map, ?int $perPage): Collection|LengthAwarePaginator
    {
        return $perPage ? $query->paginate($perPage)->withQueryString()->through($map) : $query->get()->map($map);
    }

    private function formatDate($value): string
    {
        return $value ? Carbon::parse($value)->format('d/m/Y H:i') : '-';
    }

    private function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
