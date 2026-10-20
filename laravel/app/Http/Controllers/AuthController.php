<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Models\User;
use App\Services\ExternalAuditAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Ingresa tu correo electronico.',
            'email.email' => 'El formato del correo electronico es incorrecto.',
            'password.required' => 'Ingresa tu contrasena.',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
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
        $request->session()->regenerate();
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

    public function legacyLogout(Request $request): RedirectResponse
    {
        return $this->logout($request);
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
