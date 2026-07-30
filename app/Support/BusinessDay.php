<?php

namespace App\Support;

use App\Models\Location;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Resolves "which business day" a moment belongs to for a location, from its
 * timezone and business-day cutoff (e.g. 06:00). The daily gram cap, the
 * calendar-month reset, auto-checkout, the entry–exit sheet and every Z-report
 * resolve their day through here — nothing computes a day boundary inline.
 *
 * A club whose legal defence is "the daily cap blocked it" cannot have an
 * undefined day.
 */
class BusinessDay
{
    /**
     * The business date (local midnight of the business day) containing $at.
     * A moment before the cutoff belongs to the PREVIOUS business day.
     */
    public static function date(Location $location, DateTimeInterface|string|null $at = null): CarbonImmutable
    {
        $tz = $location->timezone ?: 'Europe/Madrid';

        $local = match (true) {
            $at instanceof DateTimeInterface => CarbonImmutable::parse($at)->setTimezone($tz),
            $at === null => CarbonImmutable::now($tz),
            default => CarbonImmutable::parse($at, $tz),
        };

        [$hour, $minute] = self::cutoff($location);
        $cutoff = $local->setTime($hour, $minute, 0);

        $businessLocal = $local->lessThan($cutoff) ? $local->subDay() : $local;

        return $businessLocal->startOfDay();
    }

    /**
     * The [start, end) instants of the business day containing $at, as UTC-safe
     * CarbonImmutable in the location timezone. Use for `whereBetween` queries.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(Location $location, DateTimeInterface|string|null $at = null): array
    {
        [$hour, $minute] = self::cutoff($location);
        $date = self::date($location, $at);

        $start = $date->setTime($hour, $minute, 0);
        $end = $start->addDay();

        return [$start, $end];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function cutoff(Location $location): array
    {
        $cutoff = substr((string) ($location->business_day_cutoff ?: '06:00'), 0, 5);
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $cutoff)), 2, 0);

        return [$hour, $minute];
    }
}
