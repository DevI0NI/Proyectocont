<?php

namespace App\Livewire\ExternalAudits;

use App\Services\ExternalAuditAccess;
use App\Services\MaintenanceWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;

class Overview extends Component
{
    public function render(): View
    {
        $user = auth()->user();
        $access = app(ExternalAuditAccess::class);
        abort_unless($access->isSupervisor($user), 403);
        $access->authorizeBranch($user, (int) session('sucursal_activa'));
        $branches = $access->branches($user)->orderBy('nombre')->get();
        $rows = $branches->map(function ($branch) use ($access, $user): array {
            $query = DB::table('contenedores as c')->join('bloques as b', 'b.id', '=', 'c.bloque_id')
                ->join('patios as p', 'p.id', '=', 'b.patio_id')
                ->join('pyp_auditoria as pa', function ($join): void {
                    $join->on('pa.contenedor_id', '=', 'c.id')->where('pa.estado_id', 1);
                })
                ->leftJoin('maintenance_jobs as mj', function ($join) use ($branch): void {
                    $join->on('mj.container_id', '=', 'c.id')->where('mj.id', '=', function ($latest) use ($branch): void {
                        MaintenanceWorkflow::scopeCurrentIngress($latest->selectRaw('MAX(j.id)')->from('maintenance_jobs as j'))
                            ->where('j.branch_id', $branch->id);
                    });
                })->where('c.estado_id', 1)->where('p.sucursal_id', $branch->id);
            $ready = "c.estatus_ipsa_id = 1 AND (mj.id IS NULL OR mj.status = 'completed')";
            $row = $access->scope($query, $user, (int) $branch->id)->selectRaw("COUNT(*) as total,
                COALESCE(SUM(CASE WHEN pa.estatus_pyp = 'Aprobado' AND {$ready} THEN 1 ELSE 0 END), 0) as approved,
                COALESCE(SUM(CASE WHEN pa.estatus_pyp = 'Rechazado' THEN 1 ELSE 0 END), 0) as rejected,
                COALESCE(SUM(CASE WHEN pa.estatus_pyp = 'En revision' AND {$ready} THEN 1 ELSE 0 END), 0) as in_review,
                COALESCE(SUM(CASE WHEN pa.estatus_pyp NOT IN ('Aprobado', 'Rechazado', 'En revision') AND {$ready} THEN 1 ELSE 0 END), 0) as ready,
                COALESCE(SUM(CASE WHEN NOT ({$ready}) AND pa.estatus_pyp != 'Rechazado' THEN 1 ELSE 0 END), 0) as waiting")->first();

            return ['id' => (int) $branch->id, 'name' => $branch->nombre,
                'total' => (int) $row->total, 'approved' => (int) $row->approved, 'rejected' => (int) $row->rejected,
                'in_review' => (int) $row->in_review, 'ready' => (int) $row->ready, 'waiting' => (int) $row->waiting];
        });

        return view('livewire.external-audits.overview', [
            'company' => DB::table('external_audit_companies')->where('id', $user->external_audit_company_id)->value('name'),
            'rows' => $rows,
            'totals' => collect(['total', 'ready', 'in_review', 'approved', 'rejected', 'waiting'])->mapWithKeys(fn ($key): array => [$key => $rows->sum($key)])->all(),
        ]);
    }
}
