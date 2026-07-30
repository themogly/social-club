<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel
{
    case COMPLETED = 'COMPLETED';
    case VOIDED = 'VOIDED';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETED => __('Completada'),
            self::VOIDED => __('Anulada'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
