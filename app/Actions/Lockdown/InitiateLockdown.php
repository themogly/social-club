<?php

namespace App\Actions\Lockdown;

use App\Actions\RecordAuditLog;
use App\Enums\Role;
use App\Mail\LockdownReactivationMail;
use App\Models\LockdownReactivationToken;
use App\Models\Organisation;
use App\Models\OrganisationLockdown;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Trip the org-wide panic lockdown (prompt 121). The order is deliberate and is the security-critical part:
 *
 *   1. AUDIT FIRST — record who/when/why before the surfaces lock, so the evidence exists even if everything
 *      after it fails (a raider pulling the power does not erase who pressed the button).
 *   2. Open the lockdown row (idempotent: one open lockdown per org).
 *   3. Notify the owners AND hand them a way back — a single-use link emailed to each owner's own inbox, so an
 *      owner can reactivate OFF the (possibly coerced) terminal. The other ways back — a time-delayed
 *      auto-reactivation and a break-glass CLI — need nothing from here.
 *
 * A DRILL trips exactly the same machinery so a club can rehearse the real thing, but everything downstream
 * knows it is a drill: the email says so and an owner may end it in-app (a real one may not — see the middleware
 * and {@see ReactivateOrganisation}).
 *
 * @phpstan-type LockdownOptions array{is_drill?: bool, reason?: ?string, actor?: ?User}
 */
class InitiateLockdown
{
    /** @param  LockdownOptions  $options */
    public function handle(Organisation $organisation, array $options = []): OrganisationLockdown
    {
        // Already locked → idempotent no-op (a second panic press must not re-audit, re-notify or reset the clock).
        $open = OrganisationLockdown::active($organisation->id);
        if ($open !== null) {
            return $open;
        }

        $actor = $options['actor'] ?? (Auth::user() instanceof User ? Auth::user() : null);
        $isDrill = (bool) ($options['is_drill'] ?? false);
        $reason = $options['reason'] ?? null;

        // 1. Audit BEFORE the lock lands.
        (new RecordAuditLog)->handle(
            $isDrill ? 'org.lockdown.drill_started' : 'org.lockdown.initiated',
            $organisation,
            null,
            ['is_drill' => $isDrill, 'reason' => $reason],
        );

        // 2. Open the lockdown (re-check inside the txn to close the double-press race).
        $lockdown = DB::transaction(fn (): OrganisationLockdown => OrganisationLockdown::active($organisation->id)
            ?? OrganisationLockdown::create([
                'organisation_id' => $organisation->id,
                'locked_at' => now(),
                'locked_by' => $actor?->id,
                'is_drill' => $isDrill,
                'reason' => $reason,
            ]));

        // 3. Notify owners + issue the off-premises way back.
        if ($lockdown->wasRecentlyCreated) {
            $this->notifyOwners($lockdown);
        }

        return $lockdown;
    }

    private function notifyOwners(OrganisationLockdown $lockdown): void
    {
        $ttlHours = (int) Settings::get('lockdown_reactivation_link_ttl_hours', 48);

        $owners = User::query()->role(Role::OWNER->value)->where('active', true)->get();

        foreach ($owners as $owner) {
            $raw = Str::random(64);

            LockdownReactivationToken::create([
                'organisation_lockdown_id' => $lockdown->id,
                'user_id' => $owner->id,
                'token_hash' => hash('sha256', $raw),
                'expires_at' => now()->addHours($ttlHours),
            ]);

            if (filled($owner->email)) {
                Mail::to((string) $owner->email)->queue(new LockdownReactivationMail($owner, $lockdown, $raw));
            }
        }
    }
}
