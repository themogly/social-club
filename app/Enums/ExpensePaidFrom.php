<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExpensePaidFrom: string implements HasLabel
{
    case TILL_CASH = 'TILL_CASH';
    case CASH = 'CASH';
    case BANK = 'BANK';
    case CARD = 'CARD';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::TILL_CASH => __('Caja'),
            self::CASH => __('Efectivo (fuera de caja)'),
            self::BANK => __('Banco'),
            self::CARD => __('Tarjeta'),
            self::OTHER => __('Otro'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
