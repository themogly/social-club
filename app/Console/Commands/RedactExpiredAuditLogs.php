<?php

namespace App\Console\Commands;

use App\Actions\Members\RedactMemberAuditLogs;
use App\Actions\RecordAuditLog;
use App\Models\AuditLog;
use App\Models\HeartbeatLog;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Scheduled audit-log retention sweep (prompt 112) — the ROPA declares `audit_retention_days` (10 years) and
 * nothing ever applied it, so the register grew forever. This REDACTS (not deletes) the before/after payloads
 * of entries past the retention period, keeping each row's identity (action, actor, subject, date) so the
 * register stays "inalterable" and the shape of past activity is preserved, while the special-category detail
 * is removed — reusing the same redaction machinery Art.17 erasure uses. The prune records its OWN summary
 * entry per run (itself exempt) so the register accounts for its gaps. Batched via chunkById; --dry-run reports
 * without writing; heartbeat so SystemHealth can see it ran. Scheduled-only — no user-facing delete/edit path.
 */
class RedactExpiredAuditLogs extends Command
{
    protected $signature = 'audit:redact-retention {--dry-run : Report what would be redacted without writing}';

    protected $description = 'Redact the payloads of audit-log entries past the configured retention period';

    public const ACTION = 'audit.retention.redacted';

    public const HEARTBEAT = 'audit-retention-sweep';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) Settings::get('audit_retention_days', 3650));

        // Past retention AND still holding detail; the register's own pruning summaries are exempt.
        $due = fn () => AuditLog::query()
            ->where('created_at', '<', $cutoff)
            ->where('action', '!=', self::ACTION)
            ->where(fn ($q) => $q->whereNotNull('before')->orWhereNotNull('after'));

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$due()->count()} audit entr(ies) past retention would be redacted (up to {$cutoff->toDateString()}).");
            HeartbeatLog::beat(self::HEARTBEAT);

            return self::SUCCESS;
        }

        $redacted = 0;
        RedactMemberAuditLogs::withRedaction(function () use ($due, &$redacted): void {
            $due()->chunkById(1000, function ($rows) use (&$redacted): void {
                foreach ($rows as $row) {
                    $row->update(['before' => null, 'after' => null]); // only the payloads — identity is frozen
                    $redacted++;
                }
            });
        });

        // A single summary entry so the register can account for its own gap (exempt from the next sweep).
        if ($redacted > 0) {
            (new RecordAuditLog)->handle(self::ACTION, null, null, ['redacted' => $redacted, 'up_to' => $cutoff->toDateString()]);
        }

        HeartbeatLog::beat(self::HEARTBEAT);
        $this->info("Redacted {$redacted} audit entr(ies) past retention (up to {$cutoff->toDateString()}).");

        return self::SUCCESS;
    }
}
