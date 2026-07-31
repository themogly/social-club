<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where returned product goes on a refund (prompt 65). An explicit operator choice, never a default:
 * good product goes back to the originating batch (sellable again); mouldy/unsellable product is written
 * off as MERMA (a returned-then-written-off pair of movements — it must NOT re-enter sellable stock).
 */
enum RefundDestination: string implements HasLabel
{
    case STOCK = 'STOCK';
    case MERMA = 'MERMA';

    public function label(): string
    {
        return match ($this) {
            self::STOCK => __('Devolver a stock'),
            self::MERMA => __('Merma (no vendible)'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
