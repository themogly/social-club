<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Age gate — server-side, enforced at application approval AND every check-in (prompt 09). */
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
}
