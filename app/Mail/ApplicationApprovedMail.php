<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when a pre-registration is APPROVED (App\Actions\Members\ApproveApplication).
 * Operational, member-to-member onboarding — not marketing (the club may not advertise).
 */
class ApplicationApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $memberName, public string $memberNo) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Tu solicitud ha sido aprobada'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.application-approved',
            with: [
                'memberName' => $this->memberName,
                'memberNo' => $this->memberNo,
                'loginUrl' => route('socio.login'),
            ],
        );
    }
}
