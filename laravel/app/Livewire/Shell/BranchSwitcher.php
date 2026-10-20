<?php

namespace App\Livewire\Shell;

use App\Models\Sucursal;
use App\Services\ExternalAuditAccess;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class BranchSwitcher extends Component
{
    public int $branchId = 0;

    public function mount(): void
    {
        $this->branchId = (int) session('sucursal_activa');
    }

    public function selectBranch(int $branchId)
    {
        abort_unless(in_array((int) auth()->user()->rol_id, [1, 5], true), 403);
        app(ExternalAuditAccess::class)->authorizeBranch(auth()->user(), $branchId);
        $branch = Sucursal::activas()->find((int) $branchId);

        if (! $branch) {
            return;
        }

        $this->branchId = (int) $branch->id;
        if ((int) auth()->user()->rol_id === 5) {
            auth()->user()->forceFill(['last_active_branch_id' => $this->branchId])->save();
        }
        Session::put('sucursal_activa', $this->branchId);

        session()->flash('status', 'Sucursal activa actualizada.');

        return redirect()->to(request()->headers->get('referer', route('dashboard')));
    }

    public function render()
    {
        $branches = app(ExternalAuditAccess::class)->branches(auth()->user())->orderBy('nombre')->get();
        $current = $branches->firstWhere('id', $this->branchId);

        return view('livewire.shell.branch-switcher', [
            'branches' => $branches,
            'current' => $current,
        ]);
    }
}
