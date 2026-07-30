<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MembershipStatus: string implements HasLabel
{
    case ACTIVE = 'ACTIVE';
    case EXPIRING_SOON = 'EXPIRING_SOON';
    case LAPSED = 'LAPSED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('Activa'),
            self::EXPIRING_SOON => __('Vence pronto'),
            self::LAPSED => __('Vencida'),
            self::CANCELLED => __('Cancelada'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
