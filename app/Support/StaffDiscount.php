<?php

namespace App\Support;

use App\Models\Discount;
use App\Models\Member;
use App\Models\User;

/**
 * Prompt 347 — the club's *Descuento del personal* (`staff_discount_id`, Ajustes): applied by itself to any member
 * record linked to an ACTIVE staff account (*Sistema → Personal → Ficha de socio*), exactly as if it were assigned —
 * the same best-single-discount rule, the same sedes, the same reports. None chosen, or the account deactivated:
 * nothing.
 */
class StaffDiscount
{
    public static function for(Member $member): ?Discount
    {
        $id = (string) Settings::get('staff_discount_id', '');
        if ($id === '' || ! User::query()->where('member_id', $member->getKey())->where('active', true)->exists()) {
            return null;
        }

        $discount = Discount::query()->withoutGlobalScopes()->where('organisation_id', $member->organisation_id)->find($id);

        return $discount !== null && $discount->active ? $discount : null;
    }

    /** The staff account linked to this member, when it is active (the counter's «te estás atendiendo a ti mismo»). */
    public static function staffAccountOf(Member $member): ?User
    {
        return User::query()->where('member_id', $member->getKey())->where('active', true)->first();
    }
}
