<?php

namespace App\Console\Commands;

use App\Actions\RecordAuditLog;
use App\Models\HeartbeatLog;
use App\Models\Message;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Scheduled message-retention sweep (prompt 136, following the audit-retention pattern of prompt 112). A
 * contact channel must not keep its content forever: this REDACTS (not deletes) the body of every message
 * past `message_retention_days`, keeping the thread, authorship and timestamps so the club can still evidence
 * that a contact happened and when — while the words themselves are gone. The subject (a short label) is left
 * until the member's own erasure (AnonymiseMember) redacts it. Batched via chunkById; --dry-run reports
 * without writing; heartbeat so SystemHealth sees it ran; a single summary audit entry accounts for the gap.
 */
class PruneMemberMessages extends Command
{
    protected $signature = 'messages:prune-retention {--dry-run : Report what would be redacted without writing}';

    protected $description = 'Redact the bodies of member↔club messages past the configured retention period';

    public const ACTION = 'messages.retention.redacted';

    public const HEARTBEAT = 'message-retention-sweep';

    public const REDACTED = '[borrado]';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) Settings::get('message_retention_days', 730));

        // Past retention AND still holding a body (idempotent — an already-redacted message is skipped).
        $due = fn () => Message::query()
            ->where('created_at', '<', $cutoff)
            ->where('body', '!=', self::REDACTED);

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$due()->count()} message(s) past retention would be redacted (up to {$cutoff->toDateString()}).");
            HeartbeatLog::beat(self::HEARTBEAT);

            return self::SUCCESS;
        }

        $redacted = 0;
        $due()->chunkById(1000, function ($rows) use (&$redacted): void {
            foreach ($rows as $row) {
                $row->update(['body' => self::REDACTED]);
                $redacted++;
            }
        });

        if ($redacted > 0) {
            (new RecordAuditLog)->handle(self::ACTION, null, null, ['redacted' => $redacted, 'up_to' => $cutoff->toDateString()]);
        }

        HeartbeatLog::beat(self::HEARTBEAT);
        $this->info("Redacted {$redacted} message(s) past retention (up to {$cutoff->toDateString()}).");

        return self::SUCCESS;
    }
}
