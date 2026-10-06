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
    // Prompt 359 — moves WITHIN a batch at its sede, between the jar and the sealed reserve: each is a pair of rows (one on
    // each figure) that nets to zero, so stock and register totals never change. IN = into the reserve («Pasar a
    // reserva»), OUT = out of it into the jar («Rellenar»).
    case RESERVE_IN = 'RESERVE_IN';
    case RESERVE_OUT = 'RESERVE_OUT';

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
            self::RESERVE_IN => __('A reserva'),
            self::RESERVE_OUT => __('Relleno desde reserva'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
