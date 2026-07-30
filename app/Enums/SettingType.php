<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SettingType: string implements HasLabel
{
    case STRING = 'STRING';
    case INT = 'INT';
    case BOOL = 'BOOL';
    case FLOAT = 'FLOAT';
    case JSON = 'JSON';
    case CENTS = 'CENTS';
    case CG = 'CG';
    case BP = 'BP';

    public function label(): string
    {
        return match ($this) {
            self::STRING => __('Texto'),
            self::INT => __('Entero'),
            self::BOOL => __('Booleano'),
            self::FLOAT => __('Decimal'),
            self::JSON => __('JSON'),
            self::CENTS => __('Céntimos'),
            self::CG => __('Centigramos'),
            self::BP => __('Puntos básicos'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
