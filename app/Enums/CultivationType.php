<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CultivationType: string implements HasLabel
{
    case INDOOR = 'INDOOR';
    case OUTDOOR = 'OUTDOOR';
    case GREENHOUSE = 'GREENHOUSE';

    public function label(): string
    {
        return match ($this) {
            self::INDOOR => __('Interior'),
            self::OUTDOOR => __('Exterior'),
            self::GREENHOUSE => __('Invernadero'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
