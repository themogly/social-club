<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How the money side of a refund is settled (prompt 65). WALLET credits the member's wallet (no drawer
 * impact — like a void). CASH pays out of the OPEN till session as an OUT movement, so the arqueo sees
 * it and the expected drawer figure drops by exactly the refund — a cash refund with no open till is refused.
 */
enum RefundMethod: string implements HasLabel
{
    case WALLET = 'WALLET';
    case CASH = 'CASH';

    public function label(): string
    {
        return match ($this) {
            self::WALLET => __('Monedero'),
            self::CASH => __('Efectivo'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
