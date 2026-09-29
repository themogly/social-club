<?php

namespace App\Mail;

use App\Support\OrganisationIdentity;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Prompt 311 — the bot was blocked on Telegram: the link is cleared and alerts come by email from now on. Sent once. */
class TelegramDisconnectedMail extends ClubMail
{
    public function __construct(public string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(replyTo: OrganisationIdentity::replyTo(), subject: __('Tus avisos llegarán por correo'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.telegram-disconnected', text: 'mail.text.telegram-disconnected', with: ['name' => $this->name]);
    }
}
