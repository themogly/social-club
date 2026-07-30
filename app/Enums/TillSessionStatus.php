<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TillSessionStatus: string implements HasLabel
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => __('Abierta'),
            self::CLOSED => __('Cerrada'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
