<?php

use App\Http\Middleware\EnforceAccessPermissions;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [EnforceAccessPermissions::class]);
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'authenticate.php',
            'cambiar_sucursal.php',
            'parametros/usuarios/verificar_usuario.php',
            'parametros/sucursales/verificar_sucursal.php',
            'parametros/tiposunidad/verificar_nombre.php',
            'parametros/estados/verificar_estatus.php',
            'parametros/estados/verificar_nombre_estatus.php',
            'bloques/verificar_contenedor_mod.php',
            'ingresos/verificar_contenedor.php',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
