<?php

namespace App\Mail;

use App\Models\Member;
use App\Support\Qr;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The member's QR card. The QR encodes the (non-guessable) card token and is
 * embedded inline via CID — never hot-linked. Resent via the resend-QR action.
 */
class MemberCardMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Member $member, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Tu carné de socio/a'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.member-card',
            text: 'mail.text.member-card',
            with: [
                'memberName' => $this->member->fullName(),
                'memberNo' => $this->member->member_no,
                'qrPng' => Qr::png($this->token),
            ],
        );
    }
}
