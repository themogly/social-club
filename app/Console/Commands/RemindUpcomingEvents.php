<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Notifications\EventReminderNotification;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Nightly event-reminder sweep (prompt 56) — wires the previously unwired EventReminderNotification.
 * For each event starting within the lead window that hasn't been reminded yet, push a reminder to the
 * socios who RSVP'd GOING (opt-out applied in the notification's via()). IDEMPOTENT: a per-event
 * `reminder_sent_at` marker makes a retry or a double-fire a no-op.
 */
class RemindUpcomingEvents extends Command
{
    protected $signature = 'events:remind';

    protected $description = 'Push a reminder to attendees of events starting within the lead window';

    public function handle(): int
    {
        $now = now();
        $leadHours = max(1, (int) Settings::get('event_reminder_lead_hours', 24));

        $reminded = 0;
        Event::query()->withoutGlobalScopes()
            ->whereNull('reminder_sent_at')
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$now, $now->copy()->addHours($leadHours)])
            ->each(function (Event $event) use (&$reminded): void {
                EventReminderNotification::dispatchFor($event);
                $event->update(['reminder_sent_at' => now()]);
                $reminded++;
            });

        $this->info("Event reminders sent for {$reminded} event(s).");

        return self::SUCCESS;
    }
}
