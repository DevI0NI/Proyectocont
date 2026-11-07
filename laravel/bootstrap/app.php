<?php

use App\Http\Middleware\EnforceAccessPermissions;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidateCredentialSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->trustHosts(at: fn (): array => array_map(
            fn (string $host): string => '^'.preg_quote(strtolower($host)).'$',
            config('app.trusted_hosts', []),
        ));
        $middleware->web(append: [ValidateCredentialSession::class, EnforceAccessPermissions::class]);
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
        $middleware->validateCsrfTokens(except: [
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
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            $status = $exception->getStatusCode();
            $isPageNavigation = $request->isMethod('GET') && $request->header('Sec-Fetch-Mode') === 'navigate';
            if (! in_array($status, [403, 404], true) || ! $isPageNavigation || ! $request->user() || $request->routeIs('dashboard')) {
                return null;
            }
            $previous = (string) $request->headers->get('referer');
            $returnsToPrevious = $previous !== ''
                && parse_url($previous, PHP_URL_HOST) === $request->getHost()
                && rtrim(strtok($previous, '?#'), '/') !== rtrim($request->url(), '/');

            return redirect()->to($returnsToPrevious ? $previous : route('dashboard'))->with(
                'access_denied',
                $status === 403 ? 'No tienes permiso para entrar a esa sección.' : 'Esa página no existe o no pertenece a tu sucursal.',
            );
        });
    })->create();
