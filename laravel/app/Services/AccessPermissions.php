<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

final class AccessPermissions
{
    public static function modules(): array
    {
        $modules = [
            'dashboard' => ['Dashboard', ['view' => 'Consultar']],
            'containers' => ['Contenedores', ['view' => 'Consultar', 'create' => 'Registrar ingresos', 'edit' => 'Modificar informacion', 'technical' => 'Cambiar estados tecnicos', 'ipsa' => 'Cambiar disponibilidad IPSA']],
            'transfers' => ['Traslados', ['view' => 'Consultar', 'operate' => 'Realizar traslados']],
            'dispatches' => ['Despachos', ['view' => 'Consultar', 'operate' => 'Realizar despachos']],
            'reports' => ['Reportes', ['view' => 'Consultar', 'export' => 'Exportar']],
            'pyp' => ['Seguimiento de auditoria', ['view' => 'Consultar', 'assign' => 'Asignar revisiones']],
            'maintenance' => ['Revisiones de reparaciones', ['view' => 'Consultar solicitudes', 'confirm' => 'Confirmar trabajos realizados']],
        ];
        foreach (['users' => 'Usuarios', 'unit-types' => 'Tipos de unidad', 'statuses' => 'Estatus', 'yard-layout' => 'Patios y bloques', 'navieras' => 'Navieras', 'auditoras' => 'Auditoras externas', 'repuestos' => 'Repuestos', 'reparaciones' => 'Reparaciones'] as $key => $label) {
            $modules[$key] = [$label, ['view' => 'Consultar', 'manage' => 'Administrar']];
        }

        return $modules;
    }

    public static function keys(): array
    {
        $keys = [];
        foreach (self::modules() as $module => [, $actions]) {
            foreach ($actions as $action => $label) {
                $keys[] = $module.'.'.$action;
            }
        }

        return $keys;
    }

    public static function defaults(int $role): array
    {
        if (in_array($role, [1, 2], true)) {
            return self::keys();
        }
        if ($role !== 3) {
            return [];
        }

        return ['dashboard.view', 'containers.view', 'containers.create', 'containers.edit', 'containers.technical', 'containers.ipsa', 'transfers.view', 'transfers.operate', 'dispatches.view', 'dispatches.operate', 'reports.view', 'reports.export', 'pyp.view'];
    }

    public static function effective(User $user): array
    {
        if (! in_array((int) $user->rol_id, [1, 2, 3], true)) {
            return [];
        }

        return (int) $user->rol_id === 1 || $user->permissions === null ? self::defaults((int) $user->rol_id) : self::normalize($user->permissions);
    }

    public static function normalize(array $permissions): array
    {
        $permissions = array_values(array_intersect(self::keys(), $permissions));
        foreach ($permissions as $permission) {
            $permissions[] = explode('.', $permission)[0].'.view';
            if (in_array($permission, ['containers.technical', 'containers.ipsa'], true)) {
                $permissions[] = 'containers.edit';
            }
        }

        return array_values(array_unique($permissions));
    }

    public static function forRoute(?string $name, string $method = 'GET', ?string $catalog = null): ?string
    {
        if (! $name) {
            return null;
        }
        if ($name === 'dashboard') {
            return 'dashboard.view';
        }
        if (str_starts_with($name, 'ingresos.')) {
            return 'containers.create';
        }
        if (str_starts_with($name, 'containers.')) {
            return in_array($name, ['containers.edit', 'containers.update'], true) ? 'containers.edit' : 'containers.view';
        }
        if (str_starts_with($name, 'documents.ingress.')) {
            return 'containers.view';
        }
        if (str_starts_with($name, 'documents.dispatch.')) {
            return 'dispatches.view';
        }
        if (str_starts_with($name, 'reports.')) {
            return $name === 'reports.index' ? 'reports.view' : 'reports.export';
        }
        if (in_array($name, ['pyp-audits.edit', 'pyp-audits.update'], true)) {
            return null;
        }
        if (str_starts_with($name, 'pyp-audits.')) {
            return $name === 'pyp-audits.assign' ? 'pyp.assign' : 'pyp.view';
        }
        foreach (['transfers', 'dispatches'] as $module) {
            if (str_starts_with($name, $module.'.')) {
                return $module.'.'.($method === 'GET' ? 'view' : 'operate');
            }
        }
        if (str_starts_with($name, 'catalogs.') && in_array($catalog, ['navieras', 'auditoras', 'repuestos', 'reparaciones'], true)) {
            return $catalog.'.'.($name === 'catalogs.index' ? 'view' : 'manage');
        }
        foreach (['users', 'unit-types', 'statuses', 'yard-layout'] as $module) {
            if (str_starts_with($name, $module.'.')) {
                return $module.'.'.(in_array($name, [$module.'.index', 'yard-layout.blocks'], true) ? 'view' : 'manage');
            }
        }

        return null;
    }

    public static function required(Request $request): ?string
    {
        if ($request->routeIs('ingresos.models') && $request->user()?->hasPermission('containers.edit')) {
            return 'containers.edit';
        }
        $name = $request->route()?->getName();
        $catalog = $request->route('catalog');
        if ($name) {
            return self::forRoute($name, $request->method(), is_string($catalog) ? $catalog : null);
        }
        foreach (['ingresos' => 'containers.create', 'traslados' => 'transfers', 'despachos' => 'dispatches', 'reportes' => 'reports.export', 'bloques' => 'containers'] as $prefix => $permission) {
            if (str_starts_with($request->path(), $prefix.'/')) {
                return str_contains($permission, '.') ? $permission : $permission.'.'.($request->isMethod('GET') ? 'view' : ($prefix === 'bloques' ? 'edit' : 'operate'));
            }
        }
        foreach (['usuarios' => 'users', 'tiposunidad' => 'unit-types', 'estados' => 'statuses', 'bloques' => 'yard-layout'] as $pathPart => $module) {
            if (str_starts_with($request->path(), 'parametros/'.$pathPart.'/')) {
                return $module.'.'.($request->isMethod('GET') ? 'view' : 'manage');
            }
        }
        if (str_starts_with($request->path(), 'parametros/catalogos/') && is_string($catalog) && isset(self::modules()[$catalog])) {
            return $catalog.'.'.($request->isMethod('GET') ? 'view' : 'manage');
        }

        return null;
    }
}
