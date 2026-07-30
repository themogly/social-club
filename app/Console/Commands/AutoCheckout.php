<?php

namespace App\Console\Commands;

use App\Models\CheckIn;
use App\Models\Location;
use Illuminate\Console\Command;

/**
 * Closes any check-in still open at each location's closing time, flagging
 * `auto_checked_out = true` so genuine dwell time is not overstated. Idempotent —
 * only open sessions are touched, so a retry is a no-op.
 */
class AutoCheckout extends Command
{
    protected $signature = 'checkins:auto-checkout';

    protected $description = 'Close check-ins left open at closing time';

    public function handle(): int
    {
        $closed = 0;

        Location::query()->withoutGlobalScopes()->get()->each(function (Location $location) use (&$closed): void {
            $closed += CheckIn::query()->withoutGlobalScopes()
                ->where('location_id', $location->id)
                ->whereNull('checked_out_at')
                ->update(['checked_out_at' => now(), 'auto_checked_out' => true]);
        });

        $this->info("Auto-checked-out: {$closed}");

        return self::SUCCESS;
    }
}
