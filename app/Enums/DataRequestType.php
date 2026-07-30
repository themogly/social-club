<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DataRequestType: string implements HasLabel
{
    case ACCESS = 'ACCESS';
    case RECTIFY = 'RECTIFY';
    case ERASE = 'ERASE';
    case PORTABILITY = 'PORTABILITY';
    case OBJECT = 'OBJECT';
    case RESTRICT = 'RESTRICT';

    public function label(): string
    {
        return match ($this) {
            self::ACCESS => __('Acceso'),
            self::RECTIFY => __('Rectificación'),
            self::ERASE => __('Supresión'),
            self::PORTABILITY => __('Portabilidad'),
            self::OBJECT => __('Oposición'),
            self::RESTRICT => __('Limitación'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
