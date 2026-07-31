<?php

namespace App\ViewModels;

use App\Models\HeartbeatLog;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The operational health snapshot — because the failure mode of a broken cron or queue is
 * SILENCE. It reads the scheduler heartbeat (age vs a stale threshold), the queue depth and
 * dead-letter count, and surfaces backup/restore placeholders. Every figure is live-queried;
 * none of this is cached (it is exactly the transactional state you must not stale-cache).
 */
class SystemHealth
{
    /** Default staleness window if unset — the heartbeat runs every 5 min, so 15 min = 3 missed. */
    public const DEFAULT_STALE_SECONDS = 900;

    /** A daily job (05:00) is stale if unseen within ~26h — one missed run plus grace. */
    public const DAILY_STALE_SECONDS = 93600;

    /**
     * The generic scheduler heartbeat (system:heartbeat every 5 min) — proves the cron is alive.
     *
     * @return array{last_at: ?CarbonInterface, age_seconds: ?int, stale: bool, threshold_seconds: int}
     */
    public function scheduler(): array
    {
        return $this->component('scheduler', (int) Settings::get('heartbeat_stale_seconds', self::DEFAULT_STALE_SECONDS));
    }

    /**
     * The nightly membership expiry sweep SPECIFICALLY (memberships:sweep). It stamps its own
     * heartbeat on success, so this goes stale — red — if the sweep silently stops running even
     * while the generic scheduler heartbeat above stays fresh. That gap is the whole point.
     *
     * @return array{last_at: ?CarbonInterface, age_seconds: ?int, stale: bool, threshold_seconds: int}
     */
    public function expirySweep(): array
    {
        return $this->component('memberships-sweep', self::DAILY_STALE_SECONDS);
    }

    /**
     * The temporary-member auto-removal sweep (members:remove-temporary). Same per-job
     * heartbeat discipline as the expiry sweep — only surfaced when the feature is on.
     *
     * @return array{last_at: ?CarbonInterface, age_seconds: ?int, stale: bool, threshold_seconds: int}
     */
    public function temporarySweep(): array
    {
        return $this->component('temporary-sweep', self::DAILY_STALE_SECONDS);
    }

    /**
     * @return array{last_at: ?CarbonInterface, age_seconds: ?int, stale: bool, threshold_seconds: int}
     */
    private function component(string $component, int $threshold): array
    {
        $last = HeartbeatLog::query()->component($component)->latest('ran_at')->first();
        $ranAt = $last?->ran_at;
        $age = $ranAt !== null ? max(0, now()->getTimestamp() - $ranAt->getTimestamp()) : null;

        return [
            'last_at' => $ranAt,
            'age_seconds' => $age,
            'stale' => $age === null || $age > $threshold,
            'threshold_seconds' => $threshold,
        ];
    }

    /**
     * @return array{pending: int, failed: int}
     */
    public function queue(): array
    {
        return [
            'pending' => (int) DB::table('jobs')->count(),
            'failed' => (int) DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * Last backup / restore. No backup system is wired in-app, so these are placeholders
     * (Settings keys that stay null until a backup pipeline writes them).
     *
     * @return array{last_backup: ?string, last_restore: ?string}
     */
    public function backups(): array
    {
        $backup = Settings::get('last_backup_at');
        $restore = Settings::get('last_restore_at');

        return [
            'last_backup' => is_string($backup) ? $backup : null,
            'last_restore' => is_string($restore) ? $restore : null,
        ];
    }

    public function auditRetentionDays(): int
    {
        return (int) Settings::get('audit_retention_days', 3650);
    }

    public function dataRetentionDays(): int
    {
        return (int) Settings::get('data_retention_days', 1825);
    }
}
