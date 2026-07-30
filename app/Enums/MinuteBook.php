<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MinuteBook: string implements HasLabel
{
    case ASSEMBLY = 'ASSEMBLY';
    case BOARD = 'BOARD';

    public function label(): string
    {
        return match ($this) {
            self::ASSEMBLY => __('Asamblea'),
            self::BOARD => __('Junta directiva'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
