<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Services\ExternalAuditAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sucursal_id' => ['required', 'integer', 'exists:sucursales,id'],
        ], [
            'sucursal_id.required' => 'Selecciona una sucursal valida.',
            'sucursal_id.exists' => 'Selecciona una sucursal valida.',
        ]);

        $sucursal = Sucursal::activas()->findOrFail($data['sucursal_id']);
        app(ExternalAuditAccess::class)->authorizeBranch($request->user(), (int) $sucursal->id);

        if ((int) $request->user()->rol_id === 5) {
            $request->user()->forceFill(['last_active_branch_id' => (int) $sucursal->id])->save();
        }
        $request->session()->put('sucursal_activa', (int) $sucursal->id);

        return back()->with('status', 'Sucursal activa actualizada.');
    }
}
