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

// Membership expiry sweep + renewal reminders (idempotent per member/period).
Schedule::command('memberships:sweep')->dailyAt('05:00');

// Close check-ins left open at closing time (business-day cutoff). Idempotent.
Schedule::command('checkins:auto-checkout')->dailyAt('06:00');

// Materialise due recurring overheads (rent, utilities). Idempotent per template/period.
Schedule::command('expenses:materialise-recurring')->dailyAt('05:30');
