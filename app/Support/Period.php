<?php

namespace App\Support;

use App\Models\Location;
use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeInterface;

/**
 * A dashboard / report period and its previous-equivalent window. One date control
 * drives every widget on the page; delta cards compare the current window against
 * previous(). Bounds are half-open [start, end) and expressed in the app (storage)
 * timezone so a whereBetween string-compares like-for-like against stored
 * timestamps (the same normalisation BusinessDay::window applies).
 */
class Period
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $type,   // day | week | month | custom
        // The location whose business-day config resolved this window (prompt 105). When set, previous()
        // recomputes in that location's timezone so it stays coherent across a DST transition; when null the
        // window is a naive app-tz calendar period (the legacy, location-agnostic behaviour).
        public readonly ?Location $location = null,
    ) {}

    private static function tz(): string
    {
        return config('app.timezone') ?: 'UTC';
    }

    public static function today(): self
    {
        $start = CarbonImmutable::now(self::tz())->startOfDay();

        return new self($start, $start->addDay(), 'day');
    }

    public static function thisWeek(): self
    {
        $start = CarbonImmutable::now(self::tz())->startOfWeek();

        return new self($start, $start->addWeek(), 'week');
    }

    public static function thisMonth(): self
    {
        $start = CarbonImmutable::now(self::tz())->startOfMonth();

        return new self($start, $start->addMonth(), 'month');
    }

    public static function custom(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return new self($start->startOfDay(), $end->startOfDay()->addDay(), 'custom');
    }

    /**
     * Resolve one of the toggle keys (today | week | month), defaulting to today. When a $location is given
     * the window is its BUSINESS day/week/month (prompt 105) — the same definition the gram cap and the
     * Z-report use — so a report and the cap never disagree by the cutoff offset. Without a location it is the
     * legacy naive app-tz calendar window (kept for callers with no location in scope).
     */
    public static function fromKey(?string $key, ?Location $location = null): self
    {
        $type = match ($key) {
            'week' => 'week',
            'month' => 'month',
            default => 'day',
        };

        if ($location === null) {
            return match ($type) {
                'week' => self::thisWeek(),
                'month' => self::thisMonth(),
                default => self::today(),
            };
        }

        return self::businessWindow($location, $type);
    }

    /**
     * The BUSINESS day/week/month window for a location containing the instant $at (default now), returned as
     * half-open [start, end) in the STORAGE timezone. Computed in the location's own timezone so addWeek/
     * addMonth cross a DST boundary correctly (the window is then a genuinely different absolute length),
     * then converted to storage-tz instants so a whereBetween string-compares like-for-like — the same
     * normalisation BusinessDay::window applies.
     */
    public static function businessWindow(Location $location, string $type, DateTimeInterface|string|null $at = null): self
    {
        [$hour, $minute] = self::cutoffOf($location);
        $businessDate = BusinessDay::date($location, $at); // local midnight of the business day containing $at

        $startLocal = (match ($type) {
            'week' => $businessDate->startOfWeek(),
            'month' => $businessDate->startOfMonth(),
            default => $businessDate,
        })->setTime($hour, $minute, 0);

        $endLocal = match ($type) {
            'week' => $startLocal->addWeek(),
            'month' => $startLocal->addMonth(),
            default => $startLocal->addDay(),
        };

        $storageTz = self::tz();

        return new self($startLocal->setTimezone($storageTz), $endLocal->setTimezone($storageTz), $type, $location);
    }

    /** @return array{0: int, 1: int} the location's business-day cutoff as [hour, minute]. */
    private static function cutoffOf(Location $location): array
    {
        $cutoff = substr((string) ($location->business_day_cutoff ?: '06:00'), 0, 5);
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $cutoff)), 2, 0);

        return [$hour, $minute];
    }

    /** The previous equivalent window (yesterday / last week / last month / shifted custom). */
    public function previous(): self
    {
        // A business-day window recomputes in the location's timezone (prompt 105): the previous window is
        // the one containing the instant just before this one's start, so across a DST transition the two
        // windows are correctly different absolute lengths (a 23h or 25h day), not a naive UTC subtraction.
        if ($this->location !== null && $this->type !== 'custom') {
            return self::businessWindow($this->location, $this->type, $this->start->subSecond());
        }

        return match ($this->type) {
            'week' => new self($this->start->subWeek(), $this->start, 'week'),
            'month' => new self($this->start->subMonth(), $this->start, 'month'),
            'custom' => new self($this->start->sub($this->length()), $this->start, 'custom'),
            default => new self($this->start->subDay(), $this->start, 'day'),
        };
    }

    private function length(): DateInterval
    {
        return $this->start->diff($this->end);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function bounds(): array
    {
        return [$this->start, $this->end];
    }
}
