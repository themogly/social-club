<?php

namespace App\Console\Commands;

use App\Actions\Members\AnonymiseMember;
use App\Enums\MemberKind;
use App\Models\HeartbeatLog;
use App\Models\Member;
use Illuminate\Console\Command;

/**
 * Scheduled auto-removal of temporary members past their window (prompt 31). Routes
 * EVERY removal through the same anonymise-not-delete erasure Action as the retention
 * purge (prompt 17) — never a bespoke deletion path — so financial and consumption
 * ledger totals stay intact in anonymised form. Idempotent (skips already-anonymised),
 * dry-run capable, and each removal is audited by AnonymiseMember. Stamps its own
 * heartbeat so the health panel tracks this job specifically.
 */
class RemoveExpiredTemporaryMembers extends Command
{
    protected $signature = 'members:remove-temporary {--dry-run : Report without anonymising}';

    protected $description = 'Anonymise temporary members past their expiry window';

    public function handle(AnonymiseMember $anonymise): int
    {
        $due = Member::withoutGlobalScopes()
            ->where('kind', MemberKind::TEMPORARY->value)
            ->whereNotNull('temporary_expires_at')
            ->where('temporary_expires_at', '<', now())
            ->whereNull('anonymised_at')            // idempotent: never re-process an anonymised member
            ->get();

        foreach ($due as $member) {
            if ($this->option('dry-run')) {
                $this->line("would remove: {$member->member_no}");

                continue;
            }

            $anonymise->handle($member);
        }

        if (! $this->option('dry-run')) {
            HeartbeatLog::beat('temporary-sweep');
        }

        $this->info("Temporary members processed: {$due->count()}");

        return self::SUCCESS;
    }
}
