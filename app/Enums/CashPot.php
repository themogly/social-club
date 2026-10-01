<?php

namespace App\Enums;

use App\Support\TillSummary;
use Filament\Support\Contracts\HasLabel;

/**
 * Prompt 349 — the club keeps its cash in separate pots: the dispensary's (the one counted every night, the one the float
 * belongs to), the bar's, and the membership fees'. With *Botes de efectivo separados* on at a sede, every cash figure of
 * a till is per pot. Which pot each source feeds is decided in ONE place: {@see TillSummary}.
 */
enum CashPot: string implements HasLabel
{
    case DISPENSARY = 'DISPENSARY';
    case BAR = 'BAR';
    case FEES = 'FEES';

    public function label(): string
    {
        return match ($this) {
            self::DISPENSARY => __('Dispensario'),
            self::BAR => __('Barra'),
            self::FEES => __('Cuotas'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * The two pots that may be left uncounted at a close (the dispensary is always counted).
     *
     * @return list<self>
     */
    public static function optional(): array
    {
        return [self::BAR, self::FEES];
    }

    /** The column prefix on till_sessions for an optional pot: bar_*, fees_*. */
    public function column(): string
    {
        return strtolower($this->value);
    }
}
