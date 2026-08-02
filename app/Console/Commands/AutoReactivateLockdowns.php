<?php

namespace App\Console\Commands;

use App\Actions\Lockdown\ReactivateOrganisation;
use App\Models\OrganisationLockdown;
use App\Support\ActiveScope;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Prompt 121 — the time-delayed "way back" (no human, so a locked-out club always regains access to their own
 * statutory register). Reactivates any open lockdown older than the per-org `lockdown_auto_reactivate_minutes`.
 * Idempotent: a lockdown reactivated by an owner link first is simply no longer open. Scheduled every five
 * minutes in routes/console.php.
 */
class AutoReactivateLockdowns extends Command
{
    protected $signature = 'lockdown:auto-reactivate';

    protected $description = 'Reactivate org lockdowns older than the configured safety-net delay';

    public function handle(ReactivateOrganisation $reactivate): int
    {
        $open = OrganisationLockdown::query()->withoutGlobalScopes()->open()->get();

        foreach ($open as $lockdown) {
            // Resolve the threshold in the lockdown's OWN org scope (per-org setting; single-org today).
            app(ActiveScope::class)->setOrganisation($lockdown->organisation_id);
            $minutes = (int) Settings::get('lockdown_auto_reactivate_minutes', 1440);

            if ($lockdown->locked_at->addMinutes($minutes)->isPast()) {
                $reactivate->handle($lockdown, 'auto_delay');
                $this->info("Auto-reactivated lockdown {$lockdown->id} (org {$lockdown->organisation_id}).");
            }
        }

        return self::SUCCESS;
    }
}
