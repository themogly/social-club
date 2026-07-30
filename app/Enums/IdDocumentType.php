<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IdDocumentType: string implements HasLabel
{
    case DNI = 'DNI';
    case NIE = 'NIE';
    case PASSPORT = 'PASSPORT';

    public function label(): string
    {
        return match ($this) {
            self::DNI => __('DNI'),
            self::NIE => __('NIE'),
            self::PASSPORT => __('Pasaporte'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
