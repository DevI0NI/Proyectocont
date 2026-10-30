<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountCredentialMail extends Mailable
{
    /**
     * Create a new message instance.
     */
    public function __construct(private string $temporaryCode) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.mailers.account_credentials.username'), 'PROPORTSA'),
            subject: 'PROPORTSA - Clave temporal de acceso',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'auth.credential-email-html',
            text: 'auth.credential-email',
            with: ['temporaryCode' => $this->temporaryCode, 'minutes' => config('account-recovery.code_minutes')],
        );
    }
}
