<?php

namespace App\Support;

use App\Models\Member;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Age + carencia gates — server-side, enforced at approval, check-in (prompt 09) and dispensing (prompt 11). */
class MemberEligibility
{
    public static function minimumAge(): int
    {
        return (int) Settings::get('min_age', 18);
    }

    public static function isOldEnough(DateTimeInterface|string|null $dateOfBirth): bool
    {
        if ($dateOfBirth === null || $dateOfBirth === '') {
            return false;
        }

        return CarbonImmutable::parse($dateOfBirth)->age >= self::minimumAge();
    }

    /**
     * Has the member's carencia (waiting period) passed? A member inside carencia
     * may check in but MAY NOT be dispensed to.
     */
    public static function carenciaPassed(Member $member, DateTimeInterface|string|null $at = null): bool
    {
        if ($member->carencia_ends_at === null) {
            return true;
        }

        $moment = $at !== null ? CarbonImmutable::parse($at) : CarbonImmutable::now();

        return $moment->greaterThanOrEqualTo($member->carencia_ends_at);
    }
}
