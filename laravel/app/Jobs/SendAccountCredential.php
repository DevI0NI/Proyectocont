<?php

namespace App\Jobs;

use App\Mail\AccountCredentialMail;
use App\Models\User;
use App\Services\AccountRecovery;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class SendAccountCredential implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $userId, public string $fingerprint, public string $purpose, public string $deliveryKey)
    {
        $this->onConnection('database');
        $this->onQueue('account-recovery');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Execute the job.
     */
    public function handle(AccountRecovery $recovery): void
    {
        if (! $recovery->enabled()) {
            return;
        }
        $mailer = config('account-recovery.mailer');
        if (config("mail.mailers.{$mailer}.transport") !== 'smtp') {
            throw new RuntimeException('Las claves temporales requieren SMTP seguro; no se permite log, array ni failover.');
        }
        DB::transaction(function () use ($recovery, $mailer): void {
            $user = User::active()->lockForUpdate()->find($this->userId);
            $challenge = DB::table('account_credential_challenges')->where('user_id', $this->userId)->first();
            if (! $user || ! $challenge || $challenge->delivery_key !== $this->deliveryKey || $challenge->code_hash || $challenge->grant_hash
                || ! hash_equals($this->fingerprint, $recovery->fingerprint($user))) {
                return;
            }
            $code = Str::random(24);
            DB::table('account_credential_challenges')->where('user_id', $user->id)->update([
                'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(config('account-recovery.code_minutes')),
            ]);
            try {
                Mail::mailer($mailer)->to($user->email)->send(new AccountCredentialMail($code));
            } catch (\Throwable) {
                // SMTP diagnostics can contain the message. Never persist them with a credential.
                throw new RuntimeException('No se pudo enviar la clave temporal. Revisa la configuracion SMTP.');
            }
            $recovery->record($user, 'credential_sent');
        });
    }
}
