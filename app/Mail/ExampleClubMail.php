<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reference mailable — the canonical pattern later prompts copy.
 *
 * Every new mailable: (1) subject via __() so it localises (es default), (2) the
 * logo embedded inline via CID in the Blade view ($message->embed) — never a
 * hot-linked asset() URL, which would resolve to the dev APP_URL and break for
 * real recipients — and (3) registered in App\Support\DevMail so it renders in
 * both the /dev/mail preview and the permanent MailRenderTest.
 */
class ExampleClubMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $memberName = 'Socio/a') {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Ejemplo de comunicación del club'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.example',
            text: 'mail.text.example',
            with: ['memberName' => $this->memberName],
        );
    }
}
