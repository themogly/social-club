<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SanctionType: string implements HasLabel
{
    case WARNING = 'WARNING';
    case SUSPENSION = 'SUSPENSION';
    case EXPULSION = 'EXPULSION';

    public function label(): string
    {
        return match ($this) {
            self::WARNING => __('Apercibimiento'),
            self::SUSPENSION => __('Suspensión'),
            self::EXPULSION => __('Expulsión'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
