<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CheckInMethod: string implements HasLabel
{
    case QR = 'QR';
    case MANUAL = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::QR => __('Código QR'),
            self::MANUAL => __('Manual'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
