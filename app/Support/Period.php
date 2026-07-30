<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateInterval;

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

    /** Resolve one of the toggle keys (today | week | month), defaulting to today. */
    public static function fromKey(?string $key): self
    {
        return match ($key) {
            'week' => self::thisWeek(),
            'month' => self::thisMonth(),
            default => self::today(),
        };
    }

    /** The previous equivalent window (yesterday / last week / last month / shifted custom). */
    public function previous(): self
    {
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
