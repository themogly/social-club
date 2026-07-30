<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MembershipPeriod: string implements HasLabel
{
    case MONTHLY = 'MONTHLY';
    case YEARLY = 'YEARLY';
    case LIFETIME = 'LIFETIME';
    case CUSTOM = 'CUSTOM';

    public function label(): string
    {
        return match ($this) {
            self::MONTHLY => __('Mensual'),
            self::YEARLY => __('Anual'),
            self::LIFETIME => __('Vitalicia'),
            self::CUSTOM => __('Personalizado'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
