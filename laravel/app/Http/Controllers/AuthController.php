<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Models\User;
use App\Services\AccountRecovery;
use App\Services\ExternalAuditAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ], [
            'email.required' => 'Ingresa tu correo electronico.',
            'email.email' => 'El formato del correo electronico es incorrecto.',
            'password.required' => 'Ingresa tu contraseña.',
        ]);

        $email = strtolower(trim($credentials['email']));
        $key = 'login:'.hash('sha256', $email);
        $ipKey = 'login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10) || RateLimiter::tooManyAttempts($ipKey, 40)) {
            return back()->withErrors(['email' => 'Demasiados intentos. Espera un minuto.'])->onlyInput('email');
        }
        RateLimiter::hit($key, 60);
        RateLimiter::hit($ipKey, 60);
        $user = User::where('email', $email)->first();
        $recovery = app(AccountRecovery::class);
        if ($user && $recovery->claim($request, $user, $credentials['password'])) {
            return redirect()->route('account.password');
        }

        if (! $user || $user->requires_password_setup || ! Hash::check($credentials['password'], $user->password)) {
            return back()
                ->withErrors(['email' => 'Credenciales invalidas.'])
                ->onlyInput('email');
        }

        if ((int) $user->estado_id !== 1) {
            return back()
                ->withErrors(['email' => 'Usuario desactivado. Contacte al administrador.'])
                ->onlyInput('email');
        }

        Auth::login($user);
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->forget('credential_grant');
        $request->session()->put('credential_version', (int) ($user->credential_version ?? 1));
        $this->setInitialBranch($request, $user);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function setInitialBranch(Request $request, User $user): void
    {
        if ((int) $user->rol_id === 5) {
            $branches = app(ExternalAuditAccess::class)->branches($user)->orderBy('nombre')->pluck('id');
            $branchId = $branches->contains($user->last_active_branch_id)
                ? $user->last_active_branch_id : (int) $branches->first();
            $request->session()->put('sucursal_activa', $branchId);
            $user->forceFill(['last_active_branch_id' => $branchId ?: null])->save();

            return;
        }
        if ((int) $user->rol_id !== 1) {
            $request->session()->put('sucursal_activa', (int) $user->sucursal_id);

            return;
        }

        if ($user->sucursal_id) {
            $request->session()->put('sucursal_activa', (int) $user->sucursal_id);

            return;
        }

        $firstBranchId = Sucursal::activas()->orderBy('nombre')->value('id');

        if ($firstBranchId) {
            $request->session()->put('sucursal_activa', (int) $firstBranchId);
        }
    }
}
