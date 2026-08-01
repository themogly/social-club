<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// RGPD retention purge — anonymises members past the retention period nightly.
// (Requires the schedule:run cron in production — see SETUP.md.)
Schedule::command('members:purge')->dailyAt('04:00');

// Temporary / short-stay members past their window — anonymised via the SAME erasure
// Action as the purge (never a bespoke deletion). Idempotent. (prompt 31)
Schedule::command('members:remove-temporary')->dailyAt('04:15');

// Membership expiry sweep + renewal reminders (idempotent per member/period).
Schedule::command('memberships:sweep')->dailyAt('05:00');

// Event reminders — push attendees who RSVP'd GOING for events starting within the lead window.
// Idempotent per event (reminder_sent_at). (prompt 56)
Schedule::command('events:remind')->dailyAt('08:00');

// Close check-ins left open at closing time (business-day cutoff). Idempotent.
Schedule::command('checkins:auto-checkout')->dailyAt('06:00');

// Materialise due recurring overheads (rent, utilities). Idempotent per template/period.
Schedule::command('expenses:materialise-recurring')->dailyAt('05:30');

// Apply the declared audit-log retention (prompt 112): redact the payloads of entries past
// audit_retention_days. Redact-not-delete keeps the register inalterable; the ROPA promised
// this and nothing applied it. Idempotent (a redacted row holds nothing left to redact).
Schedule::command('audit:redact-retention')->dailyAt('05:45');

// Cross-location wallet settlement — credit at one unfenced sede clears debt at another.
// Reads live balances; a no-op when nothing to settle, so a double-fire moves no extra money.
Schedule::command('wallet:settle-cross-location')->dailyAt('03:30');

// Operational liveness — the scheduler stamps a heartbeat the health panel reads to
// prove the cron is alive. The failure mode of a broken scheduler is silence; this
// makes that silence visible (a stale heartbeat) instead of unnoticed.
Schedule::command('system:heartbeat')->everyFiveMinutes();
