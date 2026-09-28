<?php

namespace App\Console\Commands;

use App\Actions\RecordAuditLog;
use App\Models\HeartbeatLog;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The registro de jornada's retention end (post-296 audit, P2-1). Spanish law keeps it at least 4 years (art. 34.9 ET);
 * GDPR's storage limitation needs an end, so events whose business day is older than `staff_clock_retention_years`
 * (never under 4) are deleted. The model is append-only on purpose; this command is its ONE documented carve-out, a raw
 * delete in one audited run. An old event that a kept correction points at stays until the correction goes too (the
 * table restricts that delete). Idempotent; --dry-run reports; a heartbeat so SystemHealth can see it ran.
 */
class PruneStaffClockEvents extends Command
{
    protected $signature = 'staff:prune-clock-events {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete registro de jornada events past the configured retention (at least 4 years)';

    public const ACTION = 'staff.clock.retention.pruned';

    public const HEARTBEAT = 'staff-clock-retention';

    public const MIN_YEARS = 4;

    public function handle(): int
    {
        $cutoff = now()->subYears(self::years())->toDateString();

        // Old events a KEPT correction still points at. Read first, as a list: MySQL refuses a DELETE whose subquery reads
        // the same table (error 1093).
        $stillReferenced = DB::table('staff_clock_events')->whereNotNull('corrects_event_id')
            ->where('business_date', '>=', $cutoff)->pluck('corrects_event_id')->all();
        $due = fn () => DB::table('staff_clock_events')
            ->where('business_date', '<', $cutoff)
            ->whereNotIn('id', $stillReferenced);

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$due()->count()} clock event(s) before {$cutoff} would be deleted.");
            HeartbeatLog::beat(self::HEARTBEAT);

            return self::SUCCESS;
        }

        $deleted = DB::transaction(function () use ($due): int {
            // Corrections first: they point at the events they correct, and the table refuses the other order.
            $corrections = $due()->whereNotNull('corrects_event_id')->delete();

            return $corrections + $due()->delete();
        });

        if ($deleted > 0) {
            (new RecordAuditLog)->handle(self::ACTION, null, null, ['deleted' => $deleted, 'before' => $cutoff]);
        }
        HeartbeatLog::beat(self::HEARTBEAT);
        $this->info("Deleted {$deleted} clock event(s) before {$cutoff}.");

        return self::SUCCESS;
    }

    /** The configured retention in years, never under the legal minimum. */
    public static function years(): int
    {
        return max(self::MIN_YEARS, (int) Settings::get('staff_clock_retention_years', 5));
    }
}
