<?php

namespace App\Mail;

use App\Models\OwnerAlertState;
use App\Support\Alerts\AlertMessage;
use App\Support\OrganisationIdentity;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Prompt 311 — the morning email: every alert still active for the person's sedes, grouped by sede, in their language
 * (queued to the User, whose preferred locale the mailer pins). Only sent when something is active. No member data.
 */
class AlertSummaryMail extends ClubMail
{
    /** @param  Collection<int, OwnerAlertState>  $states */
    public function __construct(public Collection $states) {}

    public function envelope(): Envelope
    {
        return new Envelope(replyTo: OrganisationIdentity::replyTo(), subject: __('Avisos de hoy'));
    }

    public function content(): Content
    {
        $bySede = $this->states
            ->sortBy(fn (OwnerAlertState $state): string => $state->location_id === null ? '~' : $state->location->name)
            ->groupBy(fn (OwnerAlertState $state): string => $state->location_id === null ? (string) __('Sistema') : $state->location->name)
            ->map(fn (Collection $group): Collection => AlertMessage::sections($group));

        return new Content(view: 'mail.alert-summary', text: 'mail.text.alert-summary', with: ['bySede' => $bySede]);
    }
}
