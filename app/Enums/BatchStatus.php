<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BatchStatus: string implements HasLabel
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
    case QUARANTINED = 'QUARANTINED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => __('Abierto'),
            self::CLOSED => __('Cerrado'),
            self::QUARANTINED => __('En cuarentena'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
