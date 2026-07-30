<?php

namespace App\Support;

use App\Models\CheckIn;
use App\Models\Location;
use DateTimeInterface;

/** Footfall by hour × weekday — drives the dashboard heatmap (prompt 14) and staffing. */
class Footfall
{
    /**
     * @return array<int, array<int, int>> [weekday 0=Sun..6=Sat][hour 0..23] => check-in count
     */
    public static function byHourAndWeekday(Location $location, DateTimeInterface|string $from, DateTimeInterface|string $to): array
    {
        $matrix = array_fill(0, 7, array_fill(0, 24, 0));

        CheckIn::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->whereBetween('checked_in_at', [$from, $to])
            ->get(['checked_in_at'])
            ->each(function (CheckIn $checkIn) use (&$matrix): void {
                $matrix[(int) $checkIn->checked_in_at->dayOfWeek][(int) $checkIn->checked_in_at->hour]++;
            });

        return $matrix;
    }
}
