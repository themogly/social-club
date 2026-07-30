<?php

namespace App\Support;

use App\Models\CheckIn;
use App\Models\Location;
use DateTimeInterface;

/**
 * The daily entry–exit sheet for a location — one of the artifacts a CSC must be
 * able to produce. Rows are the check-ins for the location's business day (member
 * number, name, in, out, duration, operator). Viewable/printable/exportable (14).
 */
class EntryExitSheet
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forDay(Location $location, DateTimeInterface|string|null $date = null): array
    {
        [$start, $end] = BusinessDay::window($location, $date);

        return CheckIn::query()->withoutGlobalScopes()
            ->with(['member', 'operator'])
            ->where('location_id', $location->id)
            ->whereBetween('checked_in_at', [$start, $end])
            ->orderBy('checked_in_at')
            ->get()
            ->map(fn (CheckIn $c): array => [
                'member_no' => $c->member?->member_no,
                'name' => $c->member?->fullName(),
                'checked_in_at' => $c->checked_in_at,
                'checked_out_at' => $c->checked_out_at,
                'duration_min' => $c->checked_out_at !== null ? $c->checked_in_at->diffInMinutes($c->checked_out_at) : null,
                'operator' => $c->operator?->name,
                'auto' => $c->auto_checked_out,
            ])
            ->all();
    }
}
