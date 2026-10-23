<?php

namespace App\Services;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ExternalAuditAccess
{
    public function isSupervisor(User $user): bool
    {
        return (int) $user->rol_id === 5 && $user->external_access_level === 'supervisor' && $this->configured($user);
    }

    public function eligibleBranchMap(): array
    {
        return DB::table('shipping_line_branch_auditor as authorization')
            ->join('shipping_lines as naviera', 'naviera.id', '=', 'authorization.shipping_line_id')
            ->join('external_audit_companies as auditora', 'auditora.id', '=', 'authorization.auditor_id')
            ->join('sucursales as branch', 'branch.id', '=', 'authorization.branch_id')
            ->where('naviera.status', 1)->where('auditora.status', 1)->where('branch.estado_id', 1)
            ->select('authorization.auditor_id', 'authorization.branch_id')->distinct()->get()
            ->groupBy('auditor_id')->map(fn ($links): array => $links->pluck('branch_id')->map(fn ($id): int => (int) $id)->all())->all();
    }

    public function branches(User $user): EloquentBuilder
    {
        $query = Sucursal::activas();
        if ((int) $user->rol_id !== 5) {
            return (int) $user->rol_id === 1 ? $query : $query->where('id', $user->sucursal_id);
        }

        if (! $this->configured($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', DB::table('external_audit_user_branch')->where('user_id', $user->id)->select('branch_id'));
    }

    public function configured(User $user): bool
    {
        return (int) $user->estado_id === 1 && $user->external_audit_company_id
            && in_array($user->external_access_level, ['inspector', 'supervisor'], true)
            && DB::table('external_audit_companies')->where('id', $user->external_audit_company_id)->where('status', 1)->exists();
    }

    public function authorizeBranch(User $user, int $branchId): void
    {
        abort_unless($branchId > 0 && ((int) $user->rol_id !== 5 || $this->configured($user)), 403, 'Tu cuenta externa esta pendiente de configuracion o no tiene una sucursal autorizada.');
        abort_unless($this->branches($user)->where('id', $branchId)->exists(), 403, 'No tienes acceso a esta sucursal o tu cuenta externa esta pendiente de configuracion.');
    }

    public function scope(Builder $query, User $user, int $branchId, string $auditAlias = 'pa'): Builder
    {
        if ((int) $user->rol_id === 5) {
            $this->authorizeBranch($user, $branchId);
            $query->where($auditAlias.'.external_audit_company_id', $user->external_audit_company_id);
            $query->where(function (Builder $visibility) use ($auditAlias, $branchId): void {
                $visibility->whereNull('c.current_ingress_cycle_id')->orWhereExists(function (Builder $assignment) use ($auditAlias, $branchId): void {
                    $assignment->selectRaw('1')->from('container_ingress_cycles as assigned_cycle')
                        ->join('shipping_lines as assigned_line', 'assigned_line.id', '=', 'assigned_cycle.shipping_line_id')
                        ->join('shipping_line_branch_auditor as assigned_link', function ($join) use ($branchId): void {
                            $join->on('assigned_link.shipping_line_id', '=', 'assigned_cycle.shipping_line_id')
                                ->on('assigned_link.auditor_id', '=', 'assigned_cycle.audit_company_id')->where('assigned_link.branch_id', $branchId);
                        })
                        ->whereColumn('assigned_cycle.id', 'c.current_ingress_cycle_id')
                        ->whereColumn('assigned_cycle.container_id', 'c.id')
                        ->whereColumn('assigned_cycle.audit_company_id', $auditAlias.'.external_audit_company_id')
                        ->whereColumn('assigned_cycle.id', $auditAlias.'.ingress_cycle_id')
                        ->whereNull('assigned_cycle.closed_at')->where('assigned_line.status', 1);
                });
            });
        }

        return $query;
    }
}
