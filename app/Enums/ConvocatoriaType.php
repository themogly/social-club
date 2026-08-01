<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** The two statutory kinds of general assembly a Spanish asociación convenes. */
enum ConvocatoriaType: string implements HasLabel
{
    case ORDINARY = 'ORDINARY';
    case EXTRAORDINARY = 'EXTRAORDINARY';

    public function label(): string
    {
        return match ($this) {
            self::ORDINARY => __('Ordinaria'),
            self::EXTRAORDINARY => __('Extraordinaria'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
