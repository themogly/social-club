<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DiscountMode: string implements HasLabel
{
    case PERCENT = 'PERCENT';
    case FIXED = 'FIXED';

    public function label(): string
    {
        return match ($this) {
            self::PERCENT => __('Porcentaje'),
            self::FIXED => __('Importe fijo'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
