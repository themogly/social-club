<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Prompt 341 — what the counter's operator chip says about the hours record: clocked in (green, "Fichado 18:02"), not
 * clocked in (amber, "Sin fichar" — with *Fichar entrada* beside it), or clocked in on an EARLIER business day and never
 * out (amber, "Fichado ayer 23:40": 281's forgotten period, which wants a clock-out). The time is the sede's wall clock.
 * Read live from the record on every render — never cached.
 */
final class ClockState
{
    /**
     * `short` (prompt 343) is the phone chip's compact form — the time alone, or «Sin fichar» — which never truncates.
     *
     * @return array{state: 'in'|'out'|'stale', label: string, short: string}
     */
    public static function for(User $operator): array
    {
        $open = WorkedHours::openPeriodFor($operator);

        if ($open === null) {
            return ['state' => 'out', 'label' => __('Sin fichar'), 'short' => __('Sin fichar')];
        }

        $time = local_datetime($open->occurred_at, 'H:i', $open->location);
        $today = $open->location !== null ? BusinessDay::today($open->location) : now()->toDateString();
        $day = $open->business_date->toDateString();

        if ($day >= $today) {
            return ['state' => 'in', 'label' => __('Fichado :time', ['time' => $time]), 'short' => $time];
        }

        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();

        return ['state' => 'stale', 'label' => $day === $yesterday
            ? __('Fichado ayer :time', ['time' => $time])
            : __('Fichado el :date :time', ['date' => local_datetime($open->occurred_at, 'd/m', $open->location), 'time' => $time]),
            'short' => $day === $yesterday ? __('ayer :time', ['time' => $time]) : local_datetime($open->occurred_at, 'd/m', $open->location).' '.$time];
    }
}
