<?php

namespace App\Enums;

use App\Support\CashBoxes;
use App\Support\TillSummary;
use Filament\Support\Contracts\HasLabel;

/**
 * Prompt 349 — the club keeps its cash in separate pots: the dispensary's (the till: the one counted every night, the one the
 * float belongs to), the bar's, and the membership fees'. Prompt 373 — and the edibles', and each sede chooses which of the
 * three optional ones is its own box ({@see CashBoxes}); a kind of money without its own box goes in the till. Which pot
 * each source feeds is decided in ONE place: {@see TillSummary}.
 */
enum CashPot: string implements HasLabel
{
    case DISPENSARY = 'DISPENSARY';
    case BAR = 'BAR';
    case FEES = 'FEES';
    case EDIBLES = 'EDIBLES'; // prompt 373

    public function label(): string
    {
        return match ($this) {
            self::DISPENSARY => __('Dispensario'),
            self::BAR => __('Barra'),
            self::FEES => __('Cuotas'),
            self::EDIBLES => __('Comestibles'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * The pots that may be a box of their own and left uncounted at a close (the dispensary is the till, always counted).
     *
     * @return list<self>
     */
    public static function optional(): array
    {
        return [self::BAR, self::FEES, self::EDIBLES];
    }

    /** Prompt 373 — «10.00 € en el bote de comestibles»: one part of the counter's «Pon …» sentence. */
    public function boxPhrase(string $amount): string
    {
        return match ($this) {
            self::DISPENSARY => __(':amount en la caja', ['amount' => $amount]),
            self::BAR => __(':amount en el bote de la barra', ['amount' => $amount]),
            self::FEES => __(':amount en el bote de cuotas', ['amount' => $amount]),
            self::EDIBLES => __(':amount en el bote de comestibles', ['amount' => $amount]),
        };
    }

    /** Prompt 373 — the automatic entry's note when a box is merged into the till at opening. */
    public function mergedNote(): string
    {
        return match ($this) {
            self::DISPENSARY => '',
            self::BAR => __('Bote de la barra unido a la caja'),
            self::FEES => __('Bote de cuotas unido a la caja'),
            self::EDIBLES => __('Bote de comestibles unido a la caja'),
        };
    }

    /** Prompt 373 — «el bote de la barra»: the box named in the merge and settings messages. */
    public function boxName(): string
    {
        return match ($this) {
            self::DISPENSARY => __('la caja'),
            self::BAR => __('el bote de la barra'),
            self::FEES => __('el bote de cuotas'),
            self::EDIBLES => __('el bote de comestibles'),
        };
    }

    /** The column prefix on till_sessions for an optional pot: bar_*, fees_*, edibles_*. */
    public function column(): string
    {
        return strtolower($this->value);
    }
}
