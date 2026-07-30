<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockMovementType: string implements HasLabel
{
    case INTAKE = 'INTAKE';
    case DISPENSE = 'DISPENSE';
    case SALE = 'SALE';
    case ADJUSTMENT = 'ADJUSTMENT';
    case MERMA = 'MERMA';
    case TRANSFER_IN = 'TRANSFER_IN';
    case TRANSFER_OUT = 'TRANSFER_OUT';

    public function label(): string
    {
        return match ($this) {
            self::INTAKE => __('Entrada'),
            self::DISPENSE => __('Dispensación'),
            self::SALE => __('Venta'),
            self::ADJUSTMENT => __('Ajuste'),
            self::MERMA => __('Merma'),
            self::TRANSFER_IN => __('Transferencia entrante'),
            self::TRANSFER_OUT => __('Transferencia saliente'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
