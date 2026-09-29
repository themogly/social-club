<?php

namespace App\Actions\Alerts;

use App\Actions\ResolveLocale;
use App\Mail\AlertSummaryMail;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\OwnerAlertState;
use App\Support\Alerts\AlertRecipients;
use Illuminate\Support\Facades\Mail;

/**
 * Prompt 311 — the morning email. From 08:00 in the person's sede's timezone (their first sede, alphabetically), once a
 * day, everyone who chose email gets ONE email of every alert still active for their sedes. Nothing is sent when nothing
 * is active. Runs inside `alerts:evaluate` (every 15 minutes), so it needs no Telegram and no scheduler of its own; the
 * per-person `alert_summary_sent_on` makes a second run the same morning a no-op.
 */
class SendMorningSummaries
{
    public const HOUR = 8;

    public function handle(Organisation $organisation): void
    {
        $active = OwnerAlertState::query()->withoutGlobalScopes()->where('organisation_id', $organisation->id)->active()->with('location')->get();

        foreach (AlertRecipients::for($organisation) as $recipient) {
            $user = $recipient['user'];
            if (! in_array('email', $user->alertChannels(), true)) {
                continue;
            }

            $timezone = (string) (Location::query()->withoutGlobalScopes()->whereIn('id', $recipient['locations'])->whereNotNull('timezone')
                ->orderBy('name')->value('timezone') ?: config('app.timezone'));
            $local = now($timezone);
            if ($local->hour < self::HOUR || $user->alert_summary_sent_on?->toDateString() === $local->toDateString()) {
                continue;
            }

            $mine = AlertRecipients::relevant($recipient, $active);
            if ($mine->isNotEmpty()) {
                Mail::to($user)->locale((new ResolveLocale)->handle($user))->queue(new AlertSummaryMail($mine));
            }
            $user->forceFill(['alert_summary_sent_on' => $local->toDateString()])->save();
        }
    }
}
