<?php

namespace App\Console\Commands;

use App\Actions\ResolveLocale;
use App\Enums\MembershipStatus;
use App\Mail\MembershipReminderMail;
use App\Models\HeartbeatLog;
use App\Models\Membership;
use App\Notifications\MembershipExpiringNotification;
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
        $mailFailures = 0;
        Membership::query()->withoutGlobalScopes()
            ->with('member')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now, $now->copy()->addDays($reminderLead)])
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::EXPIRING_SOON->value])
            ->each(function (Membership $membership) use (&$reminders, &$mailFailures): void {
                $member = $membership->member;
                $period = $membership->expires_at?->toDateString() ?? '';

                if ($member === null || $membership->reminder_sent_for === $period) {
                    return;
                }

                if (filled($member->email)) {
                    // Queued + guarded (prompt 149): one bad address must NOT abort the nightly run for every
                    // remaining member. A delivery problem surfaces in Horizon's failed jobs; a queue-push
                    // failure is reported and counted, never thrown up through the sweep.
                    try {
                        Mail::to($member->email)
                            ->locale((new ResolveLocale)->handle($member))
                            ->queue(new MembershipReminderMail($member->fullName(), $period));
                    } catch (\Throwable $e) {
                        report($e);
                        $mailFailures++;
                    }
                }

                // Push reminder too (prompt 56) — same once-per-period marker, no second sweep. The
                // per-channel opt-out is applied in the notification's via(), so a no-subscription /
                // opted-out socio is a silent no-op.
                $member->notify(new MembershipExpiringNotification($period));

                $membership->update(['reminder_sent_for' => $period]);
                $reminders++;
            });

        // Per-job heartbeat: proves THIS sweep completed, so the health panel goes red on a
        // silently-broken sweep even while the generic scheduler heartbeat stays green.
        HeartbeatLog::beat('memberships-sweep');

        $this->info("Lapsed: {$lapsed}. Reminders sent: {$reminders}.".($mailFailures > 0 ? " Mail queue failures: {$mailFailures}." : ''));

        return self::SUCCESS;
    }
}
