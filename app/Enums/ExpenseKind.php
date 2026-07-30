<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExpenseKind: string implements HasLabel
{
    case TILL = 'TILL';
    case OVERHEAD = 'OVERHEAD';

    public function label(): string
    {
        return match ($this) {
            self::TILL => __('Caja'),
            self::OVERHEAD => __('Gasto general'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
