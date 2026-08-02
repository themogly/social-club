<?php

namespace App\Mail;

use App\Models\OrganisationLockdown;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The owner "way back" (prompt 121): a single-use reactivation link sent to an owner's own inbox, so a lockdown
 * can be lifted OFF the terminal. The raw token lives only in this email (its hash is what the DB stores). A
 * drill says so in the subject and body so a rehearsal is never mistaken for the real thing.
 */
class LockdownReactivationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $owner,
        public OrganisationLockdown $lockdown,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->lockdown->is_drill
                ? __('[Simulacro] Reactivar el acceso del club')
                : __('Reactivar el acceso del club'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.lockdown-reactivation',
            with: [
                'ownerName' => $this->owner->name,
                'isDrill' => (bool) $this->lockdown->is_drill,
                'url' => route('lockdown.reactivate', ['token' => $this->token]),
                'ttlHours' => (int) Settings::get('lockdown_reactivation_link_ttl_hours', 48),
            ],
        );
    }
}
