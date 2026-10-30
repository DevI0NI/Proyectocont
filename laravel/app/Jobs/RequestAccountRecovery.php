<?php

namespace App\Jobs;

use App\Services\AccountRecovery;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RequestAccountRecovery implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public string $email, public string $reason)
    {
        $this->onConnection('database');
        $this->onQueue('account-recovery');
    }

    /**
     * Execute the job.
     */
    public function handle(AccountRecovery $recovery): void
    {
        $recovery->request($this->email, $this->reason);
    }
}
