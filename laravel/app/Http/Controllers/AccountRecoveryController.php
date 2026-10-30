<?php

namespace App\Http\Controllers;

use App\Jobs\RequestAccountRecovery;
use App\Models\User;
use App\Rules\MeaningfulTechnicalText;
use App\Services\AccountRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountRecoveryController extends Controller
{
    public function request(Request $request, AccountRecovery $recovery): RedirectResponse
    {
        abort_unless($recovery->enabled(), 503, 'La recuperación aún no está habilitada.');
        $emailInput = $request->input('email');
        $reasonInput = $request->input('reason');
        $request->merge(['email' => is_string($emailInput) ? strtolower(trim($emailInput)) : $emailInput,
            'reason' => is_string($reasonInput) ? trim($reasonInput) : $reasonInput]);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:100'],
            'reason' => ['bail', 'required', 'string', 'min:60', 'max:150', new MeaningfulTechnicalText],
        ], [
            'reason.min' => 'Explica el motivo con al menos 60 caracteres.',
            'reason.max' => 'Utiliza como máximo 150 caracteres para el motivo.',
        ]);
        $email = strtolower(trim($data['email']));
        $key = 'recovery-email:'.hash('sha256', $email);
        $ipKey = 'recovery-ip:'.$request->ip();
        if (! RateLimiter::tooManyAttempts($key, 3) && ! RateLimiter::tooManyAttempts($ipKey, 10)) {
            RateLimiter::hit($key, 900);
            RateLimiter::hit($ipKey, 900);
            RequestAccountRecovery::dispatch($email, trim($data['reason']));
        }

        return redirect()->route('login')->with('status', 'Si la cuenta esta habilitada, procesaremos tu solicitud. Revisa tu correo cuando se autorice.');
    }

    public function password(Request $request, AccountRecovery $recovery): View|RedirectResponse
    {
        if (! $recovery->grantUser($request)) {
            $request->session()->forget('credential_grant');

            return redirect()->route('login')->withErrors(['email' => 'El acceso temporal venció. Solicita una nueva clave.']);
        }

        return view('auth.set-password');
    }

    public function finish(Request $request, AccountRecovery $recovery): RedirectResponse
    {
        abort_unless($recovery->grantUser($request), 403);
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:64', 'regex:/^(?=.*[A-Z])(?=.*[0-9])(?=.*[\pP\pS]).+$/u', 'confirmed']], [
            'password.min' => 'Usa al menos 8 caracteres.', 'password.max' => 'Usa como máximo 64 caracteres.',
            'password.regex' => 'Incluye una mayúscula, un número y un símbolo.', 'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);
        $recovery->finish($request, $data['password']);

        return redirect()->route('login')->with('status', 'Contraseña actualizada. Inicia sesión con tu nueva contraseña.');
    }

    public function index(Request $request, AccountRecovery $recovery): RedirectResponse
    {
        $this->authorizeSupervisor($request, $recovery);

        return redirect()->route('notifications.index', ['category' => 'account-recovery']);
    }

    public function review(Request $request, int $recoveryRequest, AccountRecovery $recovery): RedirectResponse
    {
        $this->authorizeSupervisor($request, $recovery);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        $recovery->review($recoveryRequest, $request->user(), $data['decision']);

        return back()->with('status', $data['decision'] === 'approved' ? 'Solicitud aprobada. El envío de la clave quedó en cola.' : 'Solicitud rechazada. La contraseña no cambió.');
    }

    public function send(Request $request, User $user, AccountRecovery $recovery): RedirectResponse
    {
        $this->authorizeSupervisor($request, $recovery);
        abort_unless((int) $user->rol_id !== 1 && (int) $user->estado_id === 1, 403);
        $key = 'credential-send:'.$user->id;
        abort_if(RateLimiter::tooManyAttempts($key, 1), 429, 'Espera un minuto antes de reenviar.');
        RateLimiter::hit($key, 60);
        DB::transaction(function () use ($user, $request, $recovery): void {
            $user = User::active()->lockForUpdate()->findOrFail($user->id);
            $recovery->invalidate($user);
            $recovery->queueCredential($user, 'recovery');
            $recovery->record($user, 'reset_authorized', $request->user());
        });

        return back()->with('status', 'El envío de la clave temporal quedó en cola. La contraseña actual no cambió.');
    }

    private function authorizeSupervisor(Request $request, AccountRecovery $recovery): void
    {
        abort_unless((int) $request->user()?->rol_id === 1 && (int) $request->user()?->estado_id === 1, 403);
        abort_unless($recovery->enabled(), 503);
    }
}
