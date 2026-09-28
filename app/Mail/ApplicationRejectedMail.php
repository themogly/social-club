<?php

namespace App\Mail;

use App\Support\OrganisationIdentity;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent when a pre-registration is REJECTED. A reason is included where one was
 * recorded; the tone is factual, never promotional.
 */
class ApplicationRejectedMail extends ClubMail
{
    public function __construct(public string $applicantName, public ?string $reason = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: OrganisationIdentity::replyTo(), subject: __('Sobre tu solicitud de socio/a'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.application-rejected',
            text: 'mail.text.application-rejected',
            with: [
                'applicantName' => $this->applicantName,
                'reason' => $this->reason,
            ],
        );
    }
}
