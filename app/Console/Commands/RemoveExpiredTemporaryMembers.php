<?php

namespace App\Console\Commands;

use App\Actions\Members\AnonymiseMember;
use App\Enums\MemberKind;
use App\Models\HeartbeatLog;
use App\Models\Member;
use App\Notifications\TemporaryAccessEndingNotification;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * The temporary-member lifecycle sweep (prompts 31, 111). Two idempotent steps each nightly run:
 *
 *   1. REMIND — a temporary member gets one heads-up push `temporary_reminder_lead_days` before their
 *      window closes, so they can ask to continue before they are removed (marker: temporary_reminder_sent_at).
 *   2. REMOVE — a temporary member past their window is anonymised through the SAME anonymise-not-delete
 *      erasure Action as the retention purge (prompt 17), never a bespoke deletion path, so financial and
 *      consumption ledger totals stay intact in anonymised form (marker: anonymised_at).
 *
 * Both steps skip already-processed rows, are dry-run capable, and stamp a per-job heartbeat so the health
 * panel tracks this sweep specifically.
 */
class RemoveExpiredTemporaryMembers extends Command
{
    protected $signature = 'members:remove-temporary {--dry-run : Report without notifying or anonymising}';

    protected $description = 'Remind, then anonymise, temporary members around their expiry window';

    public function handle(AnonymiseMember $anonymise): int
    {
        $reminders = $this->remindExpiringSoon();

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

        $this->info("Temporary members reminded: {$reminders}. Removed: {$due->count()}.");

        return self::SUCCESS;
    }

    /**
     * Push a one-time "your access ends soon" reminder to temporary members whose window closes within the
     * configured lead. Idempotent via temporary_reminder_sent_at; the push itself is a silent no-op for a
     * member with no subscription or a channel opt-out (the notification's via() gates both). Returns the
     * count reminded (0 under --dry-run, which only reports).
     */
    private function remindExpiringSoon(): int
    {
        $lead = (int) Settings::get('temporary_reminder_lead_days', 3);
        $now = now();

        $soon = Member::withoutGlobalScopes()
            ->where('kind', MemberKind::TEMPORARY->value)
            ->whereNull('anonymised_at')
            ->whereNull('temporary_reminder_sent_at')   // idempotent: one reminder per temporary window
            ->whereNotNull('temporary_expires_at')
            ->where('temporary_expires_at', '>=', $now)  // not already expired (that is the removal step)
            ->where('temporary_expires_at', '<', $now->copy()->addDays($lead))
            ->get();

        foreach ($soon as $member) {
            if ($this->option('dry-run')) {
                $this->line("would remind: {$member->member_no}");

                continue;
            }

            $member->notify(new TemporaryAccessEndingNotification($member->temporary_expires_at?->toDateString() ?? ''));
            $member->forceFill(['temporary_reminder_sent_at' => $now])->save();
        }

        return $this->option('dry-run') ? 0 : $soon->count();
    }
}
