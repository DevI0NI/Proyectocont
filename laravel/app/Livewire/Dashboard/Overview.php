<?php

namespace App\Livewire\Dashboard;

use App\Models\Sucursal;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Overview extends Component
{
    /**
     * Readable names and colors for the recent activity operations.
     *
     * @var array<string, array{label: string, color: string}>
     */
    public const OPERATIONS = [
        'INGRESO' => ['label' => 'Ingreso', 'color' => '#00897b'],
        'TRASLADO' => ['label' => 'Traslado', 'color' => '#4f8fd6'],
        'MODIFICACION_ESTATUS' => ['label' => 'Cambio de estatus', 'color' => '#f2b84b'],
        'MODIFICACION_DATOS' => ['label' => 'Datos modificados', 'color' => '#7b8ca5'],
        'RETIRO' => ['label' => 'Despacho', 'color' => '#ef625c'],
    ];

    public int $branchId = 0;

    public function mount(): void
    {
        $this->branchId = (int) session('sucursal_activa');
    }

    public function render()
    {
        abort_if(in_array((int) auth()->user()->rol_id, [4, 5], true), 403);
        if (in_array((int) auth()->user()->rol_id, [1, 2, 3], true)) {
            abort_unless(auth()->user()->hasPermission('dashboard.view'), 403);
        }
        $user = auth()->user();
        $sucursal = $this->branchId > 0 ? Sucursal::find($this->branchId) : null;

        $stats = [
            'activos' => 0,
            'despachados' => 0,
            'disponibles' => 0,
            'pendientes' => 0,
            'oos' => 0,
            'auditados' => 0,
            'ocupados' => 0,
            'capacidad' => 0,
        ];
        $yardOccupancy = collect();
        $recentActivity = collect();

        if ($sucursal) {
            $summary = DB::table('contenedores as c')
                ->join('bloques as b', 'c.bloque_id', '=', 'b.id')
                ->join('patios as p', 'b.patio_id', '=', 'p.id')
                ->leftJoin('estatus_ipsa as ei', 'c.estatus_ipsa_id', '=', 'ei.id')
                ->leftJoin('pyp_auditoria as pa', function ($join): void {
                    $join->on('c.id', '=', 'pa.contenedor_id')->where('pa.estado_id', 1);
                })
                ->where('p.sucursal_id', $sucursal->id)
                ->selectRaw("
                    SUM(CASE WHEN c.estado_id = 1 THEN 1 ELSE 0 END) AS activos,
                    SUM(CASE WHEN c.estado_id = 2 THEN 1 ELSE 0 END) AS despachados,
                    SUM(CASE WHEN c.estado_id = 1 AND ei.nombre = 'Disponible' THEN 1 ELSE 0 END) AS disponibles,
                    SUM(CASE WHEN c.estado_id = 1 AND ei.nombre = 'Pendiente' THEN 1 ELSE 0 END) AS pendientes,
                    SUM(CASE WHEN c.estado_id = 1 AND ei.nombre = 'OOS' THEN 1 ELSE 0 END) AS oos,
                    SUM(CASE WHEN c.estado_id = 1 AND pa.estatus_pyp IS NOT NULL AND pa.estatus_pyp <> '' THEN 1 ELSE 0 END) AS auditados
                ")
                ->first();

            foreach (['activos', 'despachados', 'disponibles', 'pendientes', 'oos', 'auditados'] as $key) {
                $stats[$key] = (int) ($summary->{$key} ?? 0);
            }

            $stats['ocupados'] = $stats['activos'];
            $stats['capacidad'] = (int) DB::table('bloques as b')
                ->join('patios as p', 'b.patio_id', '=', 'p.id')
                ->where('p.sucursal_id', $sucursal->id)
                ->where('b.estado_id', 1)
                ->where('p.estado_id', 1)
                ->sum('b.capacidad_maxima');

            $occupiedByYard = DB::table('bloques as b')
                ->leftJoin('contenedores as c', function ($join): void {
                    $join->on('b.id', '=', 'c.bloque_id')->where('c.estado_id', 1);
                })
                ->where('b.estado_id', 1)
                ->groupBy('b.patio_id')
                ->select('b.patio_id', DB::raw('COUNT(c.id) AS ocupados'));

            $capacityByYard = DB::table('bloques as b')
                ->where('b.estado_id', 1)
                ->groupBy('b.patio_id')
                ->select('b.patio_id', DB::raw('SUM(b.capacidad_maxima) AS capacidad'));

            $yardOccupancy = DB::table('patios as p')
                ->leftJoinSub($occupiedByYard, 'ocupacion', 'p.id', '=', 'ocupacion.patio_id')
                ->leftJoinSub($capacityByYard, 'capacidad', 'p.id', '=', 'capacidad.patio_id')
                ->where('p.sucursal_id', $sucursal->id)
                ->where('p.estado_id', 1)
                ->orderBy('p.nombre')
                ->get([
                    'p.nombre',
                    DB::raw('COALESCE(ocupacion.ocupados, 0) as ocupados'),
                    DB::raw('COALESCE(capacidad.capacidad, 0) as capacidad'),
                ]);

            if (in_array((int) $user->rol_id, [1, 2, 3], true)) {
                $recentActivity = DB::table('logs_eventos as le')
                    ->join('tipos_operacion as t', 'le.tipo_operacion_id', '=', 't.id')
                    ->leftJoin('contenedores as c', 'le.contenedor_id', '=', 'c.id')
                    ->leftJoin('users as u', 'le.usuario_id', '=', 'u.id')
                    ->leftJoin('bloques as b', 'c.bloque_id', '=', 'b.id')
                    ->leftJoin('patios as p', 'b.patio_id', '=', 'p.id')
                    ->where('p.sucursal_id', $sucursal->id)
                    ->orderByDesc('le.fecha_evento')
                    ->limit(8)
                    ->get([
                        'le.fecha_evento',
                        't.nombre as operacion',
                        'c.nombre as contenedor',
                        'u.username as usuario',
                        'le.observaciones',
                    ]);
            }
        }

        $occupancyPercent = $stats['capacidad'] > 0 ? (int) round(($stats['ocupados'] / $stats['capacidad']) * 100) : 0;
        $freeCapacity = max($stats['capacidad'] - $stats['ocupados'], 0);
        $pypCoverage = $stats['activos'] > 0 ? (int) round(($stats['auditados'] / $stats['activos']) * 100) : 0;
        $statusSegments = $this->statusSegments($stats);
        $isInternalDashboard = in_array((int) $user->rol_id, [1, 2, 3], true);

        return view('livewire.dashboard.overview', [
            'sucursal' => $sucursal,
            'stats' => $stats,
            'occupancyPercent' => $occupancyPercent,
            'freeCapacity' => $freeCapacity,
            'pypCoverage' => $pypCoverage,
            'statusSegments' => $statusSegments,
            'statusChartGradient' => $this->statusChartGradient($statusSegments),
            'yardOccupancy' => $yardOccupancy,
            'recentActivity' => $recentActivity,
            'isInternalDashboard' => $isInternalDashboard,
        ]);
    }

    private function statusSegments(array $stats): array
    {
        $otherActive = max($stats['activos'] - $stats['disponibles'] - $stats['pendientes'] - $stats['oos'], 0);
        $total = max($stats['activos'], 0);

        return collect([
            ['key' => 'disponibles', 'label' => 'Disponibles', 'value' => $stats['disponibles'], 'color' => '#00897b'],
            ['key' => 'pendientes', 'label' => 'Pendientes', 'value' => $stats['pendientes'], 'color' => '#f2b84b'],
            ['key' => 'oos', 'label' => 'OOS / reparacion', 'value' => $stats['oos'], 'color' => '#ef625c'],
            ['key' => 'otros', 'label' => 'Otros activos', 'value' => $otherActive, 'color' => '#7b8ca5'],
        ])
            ->map(function (array $segment) use ($total): array {
                $segment['percent'] = $total > 0 ? (int) round(($segment['value'] / $total) * 100) : 0;

                return $segment;
            })
            ->all();
    }

    private function statusChartGradient(array $segments): string
    {
        $cursor = 0;
        $parts = [];

        foreach ($segments as $segment) {
            if ($segment['value'] <= 0) {
                continue;
            }

            $next = min($cursor + $segment['percent'], 100);
            $parts[] = "{$segment['color']} {$cursor}% {$next}%";
            $cursor = $next;
        }

        if ($cursor < 100 && $cursor > 0) {
            $lastColor = collect($segments)->filter(fn ($segment) => $segment['value'] > 0)->last()['color'] ?? '#00897b';
            $parts[] = "{$lastColor} {$cursor}% 100%";
        }

        return $parts ? 'conic-gradient('.implode(', ', $parts).')' : 'conic-gradient(#e7eff2 0% 100%)';
    }
}
