<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BreachStatus: string implements HasLabel
{
    case OPEN = 'OPEN';
    case NOTIFIED = 'NOTIFIED';
    case CLOSED = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => __('Abierta'),
            self::NOTIFIED => __('Notificada'),
            self::CLOSED => __('Cerrada'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
