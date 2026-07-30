<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FeePaymentMethod: string implements HasLabel
{
    case CASH = 'CASH';
    case WALLET = 'WALLET';
    case BANK = 'BANK';
    case CARD = 'CARD';

    public function label(): string
    {
        return match ($this) {
            self::CASH => __('Efectivo'),
            self::WALLET => __('Monedero'),
            self::BANK => __('Banco'),
            self::CARD => __('Tarjeta'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
