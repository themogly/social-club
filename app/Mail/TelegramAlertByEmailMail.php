<?php

namespace App\Mail;

use App\Support\OrganisationIdentity;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Prompt 363 — the alert that should have gone by Telegram, by email instead, because Telegram rejected the bot token. The
 * same text the person would have read in Telegram (already in their language), so an urgent alert never waits for the
 * morning summary while the bot is broken.
 */
class TelegramAlertByEmailMail extends ClubMail
{
    public function __construct(public string $text) {}

    public function envelope(): Envelope
    {
        return new Envelope(replyTo: OrganisationIdentity::replyTo(), subject: __('Aviso del club'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.telegram-alert-by-email', text: 'mail.text.telegram-alert-by-email', with: ['text' => $this->text]);
    }
}
