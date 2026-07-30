<?php

namespace App\Console\Commands;

use App\Models\HeartbeatLog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Writes a scheduler heartbeat. Scheduled every few minutes (routes/console.php); the
 * health panel reads the latest and alerts when it goes stale. The failure mode of a
 * broken cron is silence, so this makes "the scheduler is alive" an observable fact.
 *
 * It must never throw — a heartbeat that crashes the scheduler run would defeat its own
 * purpose — so a write failure is swallowed and reported as a non-zero exit only.
 */
class SystemHeartbeat extends Command
{
    protected $signature = 'system:heartbeat {--component=scheduler : Which component is reporting alive}';

    protected $description = 'Record an operational heartbeat for the health panel';

    public function handle(): int
    {
        try {
            $beat = HeartbeatLog::beat((string) $this->option('component'));
            $this->info("heartbeat: {$beat->component} @ {$beat->ran_at}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("heartbeat failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
