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

    /**
     * The sede whose business day a period is measured in when the caller names none (prompt 271): the active sede, or
     * — for the organisation rollup — its canonical (first by name) sede, the rule the dashboard and reports already
     * used. Null only when there is no organisation or it has no sede; the window is then the naive calendar one.
     */
    public static function sedeInScope(): ?Location
    {
        $scope = app(ActiveScope::class);
        $id = $scope->locationId();

        if ($id === null && $scope->organisationId() !== null) {
            $id = Location::query()->withoutGlobalScopes()
                ->where('organisation_id', $scope->organisationId())->orderBy('name')->value('id');
        }

        return $id !== null ? Location::query()->withoutGlobalScopes()->find($id) : null;
    }

    /** The timezone a person reads times in: the sede's (prompt 271), else the app's. */
    public static function displayTimezone(?Location $location = null): string
    {
        return ($location ?? self::sedeInScope())?->timezone ?: self::tz();
    }

    /**
     * Today, this week, this month — always the BUSINESS window of a sede (prompt 271). These used to be naive UTC
     * calendar windows: the counter hub's takings reset at 02:00 Madrid time and the dashboard's charts disagreed with
     * its own cards. The sede defaults to {@see self::sedeInScope()}.
     */
    public static function today(?Location $location = null): self
    {
        return self::window('day', $location);
    }

    public static function thisWeek(?Location $location = null): self
    {
        return self::window('week', $location);
    }

    public static function thisMonth(?Location $location = null): self
    {
        return self::window('month', $location);
    }

    private static function window(string $type, ?Location $location): self
    {
        $location ??= self::sedeInScope();

        if ($location !== null) {
            return self::businessWindow($location, $type);
        }

        $now = CarbonImmutable::now(self::tz());
        $start = match ($type) {
            'week' => $now->startOfWeek(),
            'month' => $now->startOfMonth(),
            default => $now->startOfDay(),
        };

        return new self($start, match ($type) {
            'week' => $start->addWeek(),
            'month' => $start->addMonth(),
            default => $start->addDay(),
        }, $type);
    }

    /**
     * A custom date range, as BUSINESS days (prompt 271): "27/09 – 28/09" runs from the 27th at the sede's cutoff to the
     * 29th at its cutoff, the same days the "today" key would give — not a UTC calendar span.
     */
    public static function custom(CarbonImmutable $start, CarbonImmutable $end, ?Location $location = null): self
    {
        $location ??= self::sedeInScope();

        if ($location === null) {
            return new self($start->startOfDay(), $end->startOfDay()->addDay(), 'custom');
        }

        $tz = $location->timezone ?: 'Europe/Madrid';
        [$from] = BusinessDay::periodWindow($location, 'day', CarbonImmutable::parse($start->toDateString().' 12:00:00', $tz));
        [, $to] = BusinessDay::periodWindow($location, 'day', CarbonImmutable::parse($end->toDateString().' 12:00:00', $tz));

        return new self($from, $to, 'custom', $location);
    }

    /**
     * Resolve one of the toggle keys (today | week | month), defaulting to today, as the BUSINESS window of $location
     * (prompt 105), or of the sede in scope when none is given (prompt 271).
     */
    public static function fromKey(?string $key, ?Location $location = null): self
    {
        return self::window(match ($key) {
            'week' => 'week',
            'month' => 'month',
            default => 'day',
        }, $location);
    }

    /**
     * The BUSINESS day/week/month window for a location containing the instant $at (default now), half-open
     * [start, end) in the STORAGE timezone — {@see BusinessDay::periodWindow()}, the one definition.
     */
    public static function businessWindow(Location $location, string $type, DateTimeInterface|string|null $at = null): self
    {
        [$start, $end] = BusinessDay::periodWindow($location, $type, $at);

        return new self($start, $end, $type, $location);
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
