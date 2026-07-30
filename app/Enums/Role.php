<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case OWNER = 'OWNER';
    case MANAGER = 'MANAGER';
    case STAFF = 'STAFF';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => __('Propietario'),
            self::MANAGER => __('Gerente'),
            self::STAFF => __('Personal'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
