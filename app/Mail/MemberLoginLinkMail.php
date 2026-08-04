<?php

namespace App\Mail;

use App\Models\Member;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Passwordless access to the member PWA — a single-use, short-lived magic link. The
 * raw token lives only in this email (its hash is what the database stores).
 */
class MemberLoginLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Member $member, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Tu enlace de acceso'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.member-login-link',
            text: 'mail.text.member-login-link',
            with: [
                'memberName' => $this->member->fullName(),
                'url' => route('socio.login.verify', ['token' => $this->token]),
                'ttl' => (int) Settings::get('member_login_link_ttl_minutes', 15),
            ],
        );
    }
}
