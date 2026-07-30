<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DiscountKind: string implements HasLabel
{
    case STAFF = 'STAFF';
    case LOCAL = 'LOCAL';
    case CONCESSION = 'CONCESSION';
    case THERAPEUTIC = 'THERAPEUTIC';
    case CUSTOM = 'CUSTOM';

    public function label(): string
    {
        return match ($this) {
            self::STAFF => __('Personal'),
            self::LOCAL => __('Local'),
            self::CONCESSION => __('Concesión'),
            self::THERAPEUTIC => __('Terapéutico'),
            self::CUSTOM => __('Personalizado'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
