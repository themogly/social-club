<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Prompt 45 — emails a prospect their tokenised pre-registration invite link (the ONE public member
 * route). Modelled on MemberLoginLinkMail: the raw link lives only in the email; the DB stores only the
 * token hash. Carries scalars so the /dev/mail preview + render test need no database, and a resend
 * reuses the SAME token by rebuilding the URL.
 */
class ApplicationInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $url, public string $expiresOn) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Invitación para asociarte'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.application-invite',
            text: 'mail.text.application-invite',
            with: ['url' => $this->url, 'expiresOn' => $this->expiresOn],
        );
    }
}
