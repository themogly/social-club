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
