<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Renewal reminder sent N days before a membership expires (idempotent per period). */
class MembershipReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $memberName, public string $expiresOn) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Tu membresía vence pronto'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.membership-reminder',
            with: ['memberName' => $this->memberName, 'expiresOn' => $this->expiresOn],
        );
    }
}
