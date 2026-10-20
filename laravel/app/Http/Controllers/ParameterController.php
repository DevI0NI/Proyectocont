<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class ParameterController extends Controller
{
    public function index(): RedirectResponse
    {
        foreach (['users', 'unit-types', 'statuses', 'yard-layout'] as $module) {
            if (auth()->user()->hasPermission($module.'.view')) {
                return redirect()->route($module.'.index');
            }
        }
        abort(403);
    }
}
