<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    private const ACTIVE = 1;

    public function rows(int $branchId, string $type, string $start, string $end, ?int $patioId, ?int $blockId, ?int $containerId): array
    {
        return match ($type) {
            'movimientos' => $this->movementRows($branchId, $start, $end, $patioId, $blockId),
            'despachos' => $this->dispatchRows($branchId, $start, $end, $patioId, $blockId),
            'auditorias' => $this->auditRows($branchId, $start, $end, $patioId, $blockId),
            'traslados' => $this->transferRows($branchId, $start, $end, $patioId, $blockId),
            'cambios' => $this->changeRows($branchId, $start, $end, $patioId, $blockId),
            'historial' => $this->historyRows($branchId, $containerId),
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
            'movimientos' => 'Bitacora de Movimientos',
            'despachos' => 'Contenedores Despachados',
            'auditorias' => 'Auditorias PYP',
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

    public function pdfBinary(string $title, string $branchName, string $start, string $end, array $headers, $rows): string
    {
        $cells = '';
        foreach ($headers as $header) {
            $cells .= '<th>'.$this->escape($header).'</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach (array_values($row) as $value) {
                $body .= '<td>'.$this->escape($value).'</td>';
            }
            $body .= '</tr>';
        }

        if ($body === '') {
            $body = '<tr><td colspan="'.max(count($headers), 1).'" class="empty">No se encontraron registros.</td></tr>';
        }

        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,Arial,sans-serif;color:#172033;font-size:10px}
            h1{background:#0f766e;color:#fff;padding:14px;margin:0 0 10px;font-size:18px}
            .meta{margin-bottom:12px;color:#475467}
            table{width:100%;border-collapse:collapse;table-layout:fixed}
            th{background:#115e59;color:#fff;padding:7px;border:1px solid #0f766e}
            td{border:1px solid #d9e0ea;padding:6px;vertical-align:top;word-wrap:break-word}
            tr:nth-child(even) td{background:#f8fafc}
            .empty{text-align:center;color:#667085;font-style:italic}
        </style></head><body>
            <h1>'.$this->escape($title).'</h1>
            <div class="meta">Sucursal: '.$this->escape($branchName).' | Periodo: '.$this->escape($start).' al '.$this->escape($end).' | Generado: '.now()->format('d/m/Y H:i').'</div>
            <table><thead><tr>'.$cells.'</tr></thead><tbody>'.$body.'</tbody></table>
        </body></html>';

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    private function inventoryRows(int $branchId, string $start, string $end, ?int $patioId, ?int $blockId): array
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
            ->when($patioId, fn ($query) => $query->where('p.id', $patioId))
            ->when($blockId, fn ($query) => $query->where('b.id', $blockId))
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
            ['Patio', 'Bloque', 'Posicion', 'Contenedor', 'Tipo', 'Modelo', 'Unidad', 'Box', 'IPSA', 'PYP', 'Ingreso'],
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

    private function movementRows(int $branchId, string $start, string $end, ?int $patioId, ?int $blockId): array
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
            ->when($patioId, fn ($query) => $query->where('p.id', $patioId))
            ->when($blockId, fn ($query) => $query->where('b.id', $blockId))
            ->orderByDesc('l.fecha_evento')
            ->limit(100)
            ->get([
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
            $rows->map(fn ($row) => [
                $row->patio,
                $row->bloque,
                $this->formatDate($row->fecha_evento),
                $row->operacion,
                $row->contenedor ?? 'Eliminado',
                $row->tipo ?? '-',
                $row->modelo ?? '-',
                $row->usuario,
                $row->observaciones,
            ]),
        ];
    }

    private function dispatchRows(int $branchId, string $start, string $end, ?int $patioId, ?int $blockId): array
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
            ->when($patioId, fn ($query) => $query->where('p.id', $patioId))
            ->when($blockId, fn ($query) => $query->where('b.id', $blockId))
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

    private function auditRows(int $branchId, string $start, string $end, ?int $patioId, ?int $blockId): array
    {
        $rows = DB::table('pyp_auditoria as pa')
            ->join('contenedores as c', 'pa.contenedor_id', '=', 'c.id')
            ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
            ->join('patios as p', 'b.patio_id', '=', 'p.id')
            ->leftJoin('users as u', 'pa.usuario_id', '=', 'u.id')
            ->whereBetween('pa.fecha_registro', [$start.' 00:00:00', $end.' 23:59:59'])
            ->where('p.sucursal_id', $branchId)
            ->when($patioId, fn ($query) => $query->where('p.id', $patioId))
            ->when($blockId, fn ($query) => $query->where('b.id', $blockId))
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

    private function transferRows(int $branchId, string $start, string $end, ?int $patioId, ?int $blockId): array
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
            ->when($patioId, fn ($query) => $query->where('p_dest.id', $patioId))
            ->when($blockId, fn ($query) => $query->where('b_dest.id', $blockId))
            ->orderByDesc('le.fecha_evento')
            ->limit(100)
            ->get([
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
            $rows->map(fn ($row) => [
                $this->formatDate($row->fecha_evento),
                $row->contenedor ?? 'Eliminado',
                $row->tipo ?? '-',
                "{$row->patio_origen} - B{$row->bloque_origen} (Pos {$row->posicion_anterior})",
                "{$row->patio_destino} - B{$row->bloque_destino} (Pos {$row->posicion_nueva})",
                $row->usuario,
                $row->observaciones,
            ]),
        ];
    }

    private function changeRows(int $branchId, string $start, string $end, ?int $patioId, ?int $blockId): array
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
        if ($patioId) {
            $base .= ' AND changes.patio_id = ?';
            $params[] = $patioId;
        }
        if ($blockId) {
            $base .= ' AND changes.bloque_id = ?';
            $params[] = $blockId;
        }
        $base .= ' ORDER BY changes.fecha_evento DESC LIMIT 100';

        $rows = collect(DB::select($base, $params));

        return [
            ['Fecha', 'Contenedor', 'Usuario', 'Tipo cambio', 'Campo', 'Anterior', 'Nuevo'],
            $rows->map(fn ($row) => [
                $this->formatDate($row->fecha_evento),
                $row->contenedor,
                $row->usuario,
                $row->tipo_cambio,
                $row->campo,
                $row->valor_anterior,
                $row->valor_nuevo,
            ]),
        ];
    }

    private function historyRows(int $branchId, ?int $containerId): array
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
            ->limit(100)
            ->get([
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
            $rows->map(function ($row) {
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
            }),
        ];
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
