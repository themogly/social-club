<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockTakeStatus: string implements HasLabel
{
    case OPEN = 'OPEN';
    case COMMITTED = 'COMMITTED';
    case CANCELLED = 'CANCELLED'; // prompt 318 — a full count discarded without touching the ledger

    public function label(): string
    {
        return match ($this) {
            self::OPEN => __('Abierto'),
            self::COMMITTED => __('Confirmado'),
            self::CANCELLED => __('Cancelado'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
