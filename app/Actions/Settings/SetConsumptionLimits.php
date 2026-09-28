<?php

namespace App\Actions\Settings;

use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\RecordAuditLog;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\SettingType;
use App\Models\Member;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 296 (Shane) — switch consumption limits off, or back on with the default limits the owner chose.
 *
 * A switch, not a deletion: off, the counter neither checks nor shows a limit (`Settings::enforcement()` reads OFF and
 * `ResolveMemberLimits::shown()` returns nothing); every gram is still recorded, the stock ceiling still reads the daily
 * limit, and each member's and tier's own limit stays stored, so switching back on restores it. Owner only — the same
 * `settings.manage` that edits the default limits.
 */
class SetConsumptionLimits
{
    public function disable(User $actor): void
    {
        $this->authorise($actor);

        DB::transaction(function (): void {
            Settings::set('consumption_limits_enabled', false, SettingType::BOOL);
            (new RecordAuditLog)->handle('settings.consumption_limits.disabled', null, ['consumption_limits_enabled' => true], ['consumption_limits_enabled' => false]);
        });
    }

    /** On, with the org's default limits — both saved, with the flag, in one transaction and one audit entry. */
    public function enable(User $actor, int $dailyLimitCg, int $monthlyLimitCg): void
    {
        $this->authorise($actor);

        DB::transaction(function () use ($dailyLimitCg, $monthlyLimitCg): void {
            $before = [
                'consumption_limits_enabled' => Settings::limitsEnabled(),
                'daily_limit_cg' => (int) Settings::get('daily_limit_cg'),
                'monthly_limit_cg' => (int) Settings::get('monthly_limit_cg'),
            ];

            Settings::set('daily_limit_cg', $dailyLimitCg, SettingType::CG);
            Settings::set('monthly_limit_cg', $monthlyLimitCg, SettingType::CG);
            Settings::set('consumption_limits_enabled', true, SettingType::BOOL);

            (new RecordAuditLog)->handle('settings.consumption_limits.enabled', null, $before, [
                'consumption_limits_enabled' => true,
                'daily_limit_cg' => $dailyLimitCg,
                'monthly_limit_cg' => $monthlyLimitCg,
            ]);
        });
    }

    /**
     * What switching on with these defaults would mean for the next shift: the active members with no limit of their
     * own (no per-member limit, no tier limit), who will use them; and the members already over the proposed monthly
     * limit this month — counted by `ResolveMemberLimits`, the counter's own resolver, never a second formula.
     *
     * @return array{without_own: int, over_monthly: int}
     */
    public static function impact(int $dailyLimitCg, int $monthlyLimitCg): array
    {
        $resolver = new ResolveMemberLimits;
        $withoutOwn = 0;
        $overMonthly = 0;

        $members = Member::query()->where('status', MemberStatus::ACTIVE->value)
            ->with(['memberships' => fn ($query) => $query->withoutGlobalScopes()->where('status', MembershipStatus::ACTIVE->value)->with(['tier', 'location'])])
            ->get();

        foreach ($members as $member) {
            $membership = $member->memberships->first();
            if ($membership === null || $membership->location === null) {
                continue;
            }

            $tier = $membership->tier;
            if ($member->daily_limit_cg === null && $member->monthly_limit_cg === null && $tier?->daily_limit_cg === null && $tier?->monthly_limit_cg === null) {
                $withoutOwn++;
            }

            $snapshot = $resolver->handle($member, $membership->location, defaults: ['daily_limit_cg' => $dailyLimitCg, 'monthly_limit_cg' => $monthlyLimitCg]);
            if ($snapshot->monthlyUsedCg > $snapshot->monthlyLimitCg) {
                $overMonthly++;
            }
        }

        return ['without_own' => $withoutOwn, 'over_monthly' => $overMonthly];
    }

    private function authorise(User $actor): void
    {
        if (! $actor->can('settings.manage')) {
            throw new AuthorizationException(__('Solo el propietario puede activar o desactivar los límites de consumo.'));
        }
    }
}
