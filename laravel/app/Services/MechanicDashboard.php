<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MechanicDashboard
{
    /**
     * Labels shown for each job state in the mechanic's own words.
     *
     * @var array<string, array{label: string, color: string}>
     */
    public const SEGMENTS = [
        'returned_for_repair' => ['label' => 'Corrección pedida', 'color' => '#ef625c'],
        'in_progress' => ['label' => 'En reparación', 'color' => '#00897b'],
        'pending_review' => ['label' => 'En revisión interna', 'color' => '#f2b84b'],
        'completed' => ['label' => 'Finalizados', 'color' => '#7b8ca5'],
    ];

    /**
     * @var array<string, string>
     */
    public const ACTIONS = [
        'started' => 'Inició la reparación',
        'saved' => 'Guardó avances',
        'review_requested' => 'Envió a revisión interna',
        'repair_confirmed' => 'Reparación confirmada',
        'correction_requested' => 'La auditora pidió una corrección',
        'correction_saved' => 'Guardó la corrección',
        'correction_submitted' => 'Entregó la corrección',
        'ingress_linked' => 'Trabajo vinculado al ingreso',
    ];

    public function __construct(private MaintenanceWorkflow $workflow) {}

    /**
     * @return array{counts: array<string, int>, segments: list<array{key: string, label: string, value: int, color: string, percent: int}>, total: int, nextJobs: Collection<int, object>, waitingCount: int, waitingContainers: Collection<int, object>, activity: Collection<int, object>}
     */
    public function summary(User $user, int $branchId): array
    {
        $this->workflow->authorize($user, $branchId);

        $byStatus = DB::table('maintenance_jobs')->where('branch_id', $branchId)->where('mechanic_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($value): int => (int) $value);
        $total = (int) $byStatus->sum();
        $segments = collect(self::SEGMENTS)->map(fn (array $segment, string $key): array => $segment + [
            'key' => $key,
            'value' => $byStatus[$key] ?? 0,
            'percent' => $total > 0 ? (int) round((($byStatus[$key] ?? 0) / $total) * 100) : 0,
        ])->values()->all();

        $waiting = $this->waitingContainers($branchId);

        return [
            'counts' => [
                'in_progress' => $byStatus['in_progress'] ?? 0,
                'returned_for_repair' => $byStatus['returned_for_repair'] ?? 0,
                'pending_review' => $byStatus['pending_review'] ?? 0,
                'completed_this_month' => DB::table('maintenance_jobs')->where('branch_id', $branchId)->where('mechanic_id', $user->id)
                    ->where('status', 'completed')->where('updated_at', '>=', now()->startOfMonth())->count(),
            ],
            'segments' => $segments,
            'total' => $total,
            'nextJobs' => $this->workflow->jobs($branchId)->where('j.mechanic_id', $user->id)
                ->whereIn('j.status', ['returned_for_repair', 'in_progress'])
                ->orderByRaw("CASE WHEN j.status = 'returned_for_repair' THEN 0 ELSE 1 END")->orderBy('j.updated_at')
                ->limit(6)->get(),
            'waitingCount' => (clone $waiting)->count(),
            'waitingContainers' => $waiting->orderBy('c.fecha_ingreso')->limit(5)->get(),
            'activity' => DB::table('maintenance_events as e')->join('maintenance_jobs as j', 'j.id', '=', 'e.job_id')
                ->join('contenedores as c', 'c.id', '=', 'j.container_id')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                ->where('j.branch_id', $branchId)->where('j.mechanic_id', $user->id)->whereIn('e.action', array_keys(self::ACTIONS))
                ->orderByDesc('e.created_at')->orderByDesc('e.id')->limit(6)
                ->get(['e.action', 'e.created_at', 'c.nombre as container_name', 'u.username as actor']),
        ];
    }

    /**
     * Containers the mechanic can start a repair on in this branch.
     */
    private function waitingContainers(int $branchId): Builder
    {
        return $this->workflow->startableContainers($branchId)->whereNotExists(function (Builder $query): void {
            MaintenanceWorkflow::scopeCurrentIngress($query->selectRaw('1')->from('maintenance_jobs as j'))
                ->whereIn('j.status', MaintenanceWorkflow::OPEN_STATES);
        });
    }
}
