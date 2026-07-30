<?php

namespace App\Support;

use App\Models\CheckIn;
use App\Models\Location;

/** Live occupancy (aforo). Always queried live — never cached (transactional data). */
class Occupancy
{
    public static function current(Location $location): int
    {
        return CheckIn::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->whereNull('checked_out_at')
            ->count();
    }

    public static function capacity(Location $location): ?int
    {
        return $location->capacity;
    }

    /** Fraction of capacity used (0.0–1.0+), or null when the location has no capacity set. */
    public static function fraction(Location $location): ?float
    {
        $capacity = self::capacity($location);

        return ($capacity !== null && $capacity > 0) ? self::current($location) / $capacity : null;
    }
}
