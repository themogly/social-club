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

    /**
     * The till form's own word for the type (prompt 279) — what the movement select offers and what its
     * confirmation reads back, so the operator reads the word they picked. The amount field clears on success,
     * so the type is half of what tells them what they just posted.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::IN => __('Entrada'),
            self::OUT => __('Salida'),
            self::BANKED => __('Ingreso en banco'),
            self::PETTY_CASH => __('Caja chica'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
