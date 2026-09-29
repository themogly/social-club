<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Prompt 325 — who may correct a membership: the permission (`membership.manage` for the corrections; the fee
 * permissions for collecting and waiving), AND the membership's sede among the sedes this person may work at (a
 * manager only at their own; the owner at all). Asked by the panel to show an action and by every writer again.
 */
final class MembershipCorrections
{
    public static function may(?User $actor, Membership $membership, string $permission = 'membership.manage'): bool
    {
        return $actor instanceof User && $actor->can($permission)
            && app(LocationSwitcher::class)->canAccess($actor, (string) $membership->location_id);
    }

    /** @throws AuthorizationException */
    public static function authorize(?User $actor, Membership $membership, string $permission = 'membership.manage'): void
    {
        if (! self::may($actor, $membership, $permission)) {
            throw new AuthorizationException(__('No puedes corregir esta membresía.'));
        }
    }
}
