<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CashMovementType: string implements HasLabel
{
    case IN = 'IN';
    case OUT = 'OUT';
    case BANKED = 'BANKED';
    case PETTY_CASH = 'PETTY_CASH';

    public function label(): string
    {
        return match ($this) {
            self::IN => __('Entrada de efectivo'),
            self::OUT => __('Salida de efectivo'),
            self::BANKED => __('Ingreso en banco'),
            self::PETTY_CASH => __('Caja chica'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
