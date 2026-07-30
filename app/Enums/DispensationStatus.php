<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DispensationStatus: string implements HasLabel
{
    case COMPLETED = 'COMPLETED';
    case VOIDED = 'VOIDED';
    case CORRECTED = 'CORRECTED';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETED => __('Completada'),
            self::VOIDED => __('Anulada'),
            self::CORRECTED => __('Corregida'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
