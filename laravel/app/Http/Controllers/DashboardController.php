<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Services\MechanicDashboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, MechanicDashboard $mechanicDashboard): View
    {
        $sucursalId = (int) $request->session()->get('sucursal_activa');
        $context = [
            'sucursal' => $sucursalId ? Sucursal::find($sucursalId) : null,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
        ];

        if ((int) $request->user()->rol_id === 4) {
            abort_unless($context['sucursal'], 403);

            return view('dashboard.mechanic', $context + $mechanicDashboard->summary($request->user(), (int) $context['sucursal']->id));
        }

        return view('dashboard.index', $context);
    }
}
