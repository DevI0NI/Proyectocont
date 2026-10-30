<?php

namespace App\Http\Middleware;

use App\Services\AccountRecovery;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ValidateCredentialSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && app(AccountRecovery::class)->enabled()) {
            $version = (int) ($user->credential_version ?? 1);
            $sessionVersion = $request->session()->get('credential_version');
            if ($user->requires_password_setup || ($sessionVersion !== null && (int) $sessionVersion !== $version)
                || ($sessionVersion === null && $version > 1)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['email' => 'Vuelve a iniciar sesión con tu contraseña actual.']);
            }
            $request->session()->put('credential_version', $version);
        }

        return $next($request);
    }
}
