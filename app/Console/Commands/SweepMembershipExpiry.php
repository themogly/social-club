<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Mail\MembershipReminderMail;
use App\Models\Membership;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Nightly membership sweep: flips genuinely-lapsed memberships to LAPSED, marks
 * those inside the expiring-soon window, and sends renewal reminders — idempotent
 * per member per period (the `reminder_sent_for` marker), so a retry never
 * double-sends. Reads windows through Settings (safe defaults, never throws).
 */
class SweepMembershipExpiry extends Command
{
    protected $signature = 'memberships:sweep';

    protected $description = 'Flip lapsed/expiring memberships and send renewal reminders';

    public function handle(): int
    {
        $now = now();
        $expiringWindow = (int) Settings::get('expiring_soon_days', 30);
        $reminderLead = (int) Settings::get('renewal_reminder_lead_days', 7);

        // 1) Lapse memberships whose expiry has passed.
        $lapsed = Membership::query()->withoutGlobalScopes()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::EXPIRING_SOON->value])
            ->update(['status' => MembershipStatus::LAPSED->value]);

        // 2) Flag memberships entering the expiring-soon window.
        Membership::query()->withoutGlobalScopes()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now, $now->copy()->addDays($expiringWindow)])
            ->where('status', MembershipStatus::ACTIVE->value)
            ->update(['status' => MembershipStatus::EXPIRING_SOON->value]);

        // 3) Send renewal reminders once per member per period.
        $reminders = 0;
        Membership::query()->withoutGlobalScopes()
            ->with('member')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now, $now->copy()->addDays($reminderLead)])
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::EXPIRING_SOON->value])
            ->each(function (Membership $membership) use (&$reminders): void {
                $period = $membership->expires_at?->toDateString() ?? '';

                if ($membership->reminder_sent_for === $period || blank($membership->member?->email)) {
                    return;
                }

                Mail::to($membership->member->email)->send(
                    new MembershipReminderMail($membership->member->fullName(), $period),
                );
                $membership->update(['reminder_sent_for' => $period]);
                $reminders++;
            });

        $this->info("Lapsed: {$lapsed}. Reminders sent: {$reminders}.");

        return self::SUCCESS;
    }
}
