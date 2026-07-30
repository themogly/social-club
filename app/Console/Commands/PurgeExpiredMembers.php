<?php

namespace App\Console\Commands;

use App\Actions\Members\AnonymiseMember;
use App\Models\Member;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Scheduled retention purge (RGPD): anonymises members whose `left_at` is older
 * than the configured retention period and are not yet anonymised. Anonymise-not-
 * delete keeps the ledger whole. Reads retention through Settings (safe default).
 */
class PurgeExpiredMembers extends Command
{
    protected $signature = 'members:purge {--dry-run : Report without anonymising}';

    protected $description = 'Anonymise members past the configured data-retention period';

    public function handle(AnonymiseMember $anonymise): int
    {
        $cutoff = now()->subDays((int) Settings::get('data_retention_days', 1825));

        $due = Member::withoutGlobalScopes()
            ->whereNotNull('left_at')
            ->where('left_at', '<', $cutoff)
            ->whereNull('anonymised_at')
            ->get();

        foreach ($due as $member) {
            if ($this->option('dry-run')) {
                $this->line("would anonymise: {$member->member_no}");

                continue;
            }

            $anonymise->handle($member);
        }

        $this->info("Members processed: {$due->count()}");

        return self::SUCCESS;
    }
}
