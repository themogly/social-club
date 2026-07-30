<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockTakeStatus: string implements HasLabel
{
    case OPEN = 'OPEN';
    case COMMITTED = 'COMMITTED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => __('Abierto'),
            self::COMMITTED => __('Confirmado'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
