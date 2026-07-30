<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DiscountAppliesTo: string implements HasLabel
{
    case GENETIC = 'GENETIC';
    case ARTICLE = 'ARTICLE';
    case BOTH = 'BOTH';

    public function label(): string
    {
        return match ($this) {
            self::GENETIC => __('Genéticas'),
            self::ARTICLE => __('Artículos'),
            self::BOTH => __('Ambos'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
