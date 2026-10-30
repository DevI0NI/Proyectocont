<?php

namespace App\Http\Middleware;

use App\Services\AccessPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $allowedRoles = array_map('intval', $roles);
        $permission = AccessPermissions::required($request);
        if (in_array((int) $user->rol_id, [1, 2, 3], true) && $request->routeIs('parameters.index', 'catalogs.overview')) {
            return $next($request);
        }
        if ($permission && in_array((int) $user->rol_id, [1, 2, 3], true)) {
            abort_unless($user->hasPermission($permission), 403, 'No tienes permiso para realizar esta accion.');

            return $next($request);
        }

        if ($allowedRoles !== [] && ! in_array((int) $user->rol_id, $allowedRoles, true)) {
            abort(403, 'No tienes permiso para acceder a esta seccion.');
        }

        return $next($request);
    }
}
