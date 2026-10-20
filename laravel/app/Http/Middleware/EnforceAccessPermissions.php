<?php

namespace App\Http\Middleware;

use App\Services\AccessPermissions;
use App\Services\ExternalAuditAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceAccessPermissions
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $request->routeIs('logout') && ! $request->is('logout.php')) {
            abort_unless((int) $user->estado_id === 1, 403, 'Esta cuenta esta desactivada.');
            if ((int) $user->rol_id === 4) {
                app(ExternalAuditAccess::class)->authorizeBranch($user, (int) session('sucursal_activa'));
                if ($request->routeIs('dashboard', 'containers.index', 'pyp-audits.index') || $request->is('index.php', 'bloques/bloques.php', 'bloques/contenedor.php')) {
                    return redirect()->route('maintenance.orders');
                }
                abort_unless($request->routeIs('maintenance.*', '*livewire.update') || $request->is('mantenimiento/ordenes.php'), 403, 'Esta vista no pertenece a la interfaz de mantenimiento.');
            }
            if ((int) $user->rol_id === 5) {
                app(ExternalAuditAccess::class)->authorizeBranch($user, (int) session('sucursal_activa'));
                if ($request->routeIs('dashboard', 'containers.index') || $request->is('index.php', 'bloques/bloques.php', 'bloques/contenedor.php')) {
                    return redirect()->route('pyp-audits.index');
                }
                abort_unless($request->routeIs('pyp-audits.index', 'pyp-audits.edit', 'pyp-audits.update', 'branches.active.update', '*livewire.update') || $request->is('cambiar_sucursal.php'), 403, 'Esta vista no pertenece a la interfaz de tu empresa auditora.');
            }
        }
        if ($user && in_array((int) $user->rol_id, [2, 3], true)) {
            $permission = AccessPermissions::required($request);
            if ($request->routeIs('dashboard') && ! $user->hasPermission('dashboard.view')) {
                foreach (['containers' => 'containers.index', 'users' => 'users.index', 'reports' => 'reports.index', 'transfers' => 'transfers.create', 'dispatches' => 'dispatches.create', 'pyp' => 'pyp-audits.index', 'unit-types' => 'unit-types.index', 'statuses' => 'statuses.index', 'yard-layout' => 'yard-layout.index'] as $module => $route) {
                    if ($user->hasPermission($module.'.view')) {
                        return redirect()->route($route);
                    }
                }
                foreach (['navieras', 'auditoras', 'repuestos', 'reparaciones'] as $catalog) {
                    if ($user->hasPermission($catalog.'.view')) {
                        return redirect()->route('catalogs.index', $catalog);
                    }
                }
            }
            abort_if($permission && ! $user->hasPermission($permission), 403, 'No tienes permiso para realizar esta accion.');
            if (in_array($request->route()?->getName(), ['parameters.index', 'catalogs.overview'], true)) {
                $modules = $request->routeIs('parameters.index') ? ['users', 'unit-types', 'statuses', 'yard-layout'] : ['navieras', 'auditoras', 'repuestos', 'reparaciones'];
                abort_unless(collect($modules)->contains(fn (string $module): bool => $user->hasPermission($module.'.view')), 403);
            }
        }

        return $next($request);
    }
}
