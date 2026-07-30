<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CategoryAppliesTo: string implements HasLabel
{
    case GENETIC = 'GENETIC';
    case ARTICLE = 'ARTICLE';

    public function label(): string
    {
        return match ($this) {
            self::GENETIC => __('Genética'),
            self::ARTICLE => __('Artículo'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
