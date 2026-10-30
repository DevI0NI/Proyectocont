<?php

namespace App\Services;

use App\Jobs\SendAccountCredential;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountRecovery
{
    public function enabled(): bool
    {
        return (bool) config('account-recovery.enabled');
    }

    public function fingerprint(User $user): string
    {
        return hash('sha256', implode('|', [$user->email, $user->password, $user->rol_id, $user->estado_id, $user->credential_version ?? 1]));
    }

    public function request(string $email, string $reason): void
    {
        if (! $this->enabled()) {
            return;
        }
        DB::transaction(function () use ($email, $reason): void {
            $user = User::active()->where('email', $email)->lockForUpdate()->first();
            if (! $user) {
                return;
            }
            if (DB::table('account_security_events')->where('user_id', $user->id)->where('action', 'credential_requested')->where('created_at', '>', now()->subMinutes(5))->exists()) {
                return;
            }
            $this->record($user, 'credential_requested');
            if ((int) $user->rol_id === 1) {
                $this->queueCredential($user, 'recovery');

                return;
            }
            if (DB::table('account_recovery_requests')->where('user_id', $user->id)->where('status', 'pending')->exists()) {
                return;
            }
            $id = DB::table('account_recovery_requests')->insertGetId([
                'user_id' => $user->id, 'reason' => $reason, 'status' => 'pending',
                'fingerprint' => $this->fingerprint($user), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(SystemNotifications::class)->accountRecoveryRequested($user, $id);
        });
    }

    public function review(int $id, User $actor, string $decision): void
    {
        abort_unless((int) $actor->rol_id === 1 && (int) $actor->estado_id === 1, 403);
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);
        $targetId = DB::table('account_recovery_requests')->where('id', $id)->value('user_id');
        abort_unless($targetId, 404);
        DB::transaction(function () use ($id, $targetId, $actor, $decision): void {
            $user = User::query()->lockForUpdate()->findOrFail($targetId);
            $record = DB::table('account_recovery_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($record->status === 'pending', 409, 'Esta solicitud ya fue atendida.');
            abort_unless((int) $user->rol_id !== 1 && (int) $user->estado_id === 1 && hash_equals($record->fingerprint, $this->fingerprint($user)), 409, 'La cuenta cambió. Solicita una nueva recuperación.');
            DB::table('account_recovery_requests')->where('id', $id)->update([
                'status' => $decision, 'reviewed_by' => $actor->id, 'updated_at' => now(),
            ]);
            $this->record($user, 'recovery_'.$decision, $actor);
            if ($decision === 'approved') {
                $this->queueCredential($user, 'recovery');
            }
        });
    }

    public function queueCredential(User $user, string $purpose): void
    {
        abort_unless($this->enabled(), 503, 'La activación por correo aún no está habilitada.');
        $key = (string) Str::uuid();
        DB::table('account_credential_challenges')->updateOrInsert(['user_id' => $user->id], [
            'delivery_key' => $key, 'fingerprint' => $this->fingerprint($user),
            'code_hash' => null, 'grant_hash' => null, 'grant_expires_at' => null,
            'expires_at' => now()->addMinutes(config('account-recovery.code_minutes')),
        ]);
        SendAccountCredential::dispatch($user->id, $this->fingerprint($user), $purpose, $key)->afterCommit();
    }

    public function claim(Request $request, User $user, string $code): bool
    {
        if (! $this->enabled() || (int) $user->estado_id !== 1) {
            return false;
        }

        return DB::transaction(function () use ($request, $user, $code): bool {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $challenge = DB::table('account_credential_challenges')->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $challenge || ! $challenge->code_hash || now()->gte($challenge->expires_at)
                || ! hash_equals($challenge->fingerprint, $this->fingerprint($user)) || ! Hash::check($code, $challenge->code_hash)) {
                return false;
            }
            $grant = Str::random(64);
            DB::table('account_credential_challenges')->where('user_id', $user->id)->update([
                'code_hash' => null, 'grant_hash' => hash('sha256', $grant),
                'grant_expires_at' => now()->addMinutes(10)->min($challenge->expires_at),
            ]);
            $request->session()->regenerate();
            $request->session()->forget('url.intended');
            $request->session()->put('credential_grant', ['user_id' => $user->id, 'secret' => $grant]);

            return true;
        });
    }

    public function grantUser(Request $request): ?User
    {
        if (! $this->enabled()) {
            return null;
        }
        $grant = $request->session()->get('credential_grant');
        $user = isset($grant['user_id']) ? User::active()->find($grant['user_id']) : null;
        $challenge = $user ? DB::table('account_credential_challenges')->where('user_id', $user->id)->first() : null;
        if (! $challenge || ! $challenge->grant_hash || ! $challenge->grant_expires_at || now()->gte($challenge->grant_expires_at)
            || ! hash_equals($challenge->fingerprint, $this->fingerprint($user))
            || ! hash_equals($challenge->grant_hash, hash('sha256', $grant['secret'] ?? ''))) {
            return null;
        }

        return $user;
    }

    public function finish(Request $request, string $password): void
    {
        $id = $request->session()->get('credential_grant.user_id');
        DB::transaction(function () use ($request, $password, $id): void {
            User::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $user = $this->grantUser($request);
            if (! $user) {
                throw ValidationException::withMessages(['password' => 'La clave temporal venció. Solicita otra.']);
            }
            if (Hash::check($password, $user->password)) {
                throw ValidationException::withMessages(['password' => 'Elige una contraseña diferente de la anterior.']);
            }
            $user->forceFill(['password' => Hash::make($password), 'requires_password_setup' => false,
                'credential_version' => ($user->credential_version ?? 1) + 1])->save();
            $this->invalidate($user);
            $this->record($user, 'password_changed');
        });
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function invalidate(User $user): void
    {
        DB::table('account_credential_challenges')->where('user_id', $user->id)->delete();
        DB::table('account_recovery_requests')->where('user_id', $user->id)->where('status', 'pending')
            ->update(['status' => 'obsolete', 'updated_at' => now()]);
    }

    public function record(User $user, string $action, ?User $actor = null): void
    {
        DB::table('account_security_events')->insert(['user_id' => $user->id, 'actor_id' => $actor?->id,
            'action' => $action, 'created_at' => now()]);
    }
}
