<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $sucursalId = (int) $request->session()->get('sucursal_activa');

        return view('dashboard.index', [
            'sucursal' => $sucursalId ? Sucursal::find($sucursalId) : null,
            'sucursales' => Sucursal::activas()->orderBy('nombre')->get(),
        ]);
    }
}
