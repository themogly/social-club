<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The delivery standing of one member on a convocatoria's frozen roll. NO_EMAIL is recorded, not hidden:
 * the club must be able to see (and reach by other means) the members it could not email.
 */
enum ConvocatoriaRecipientStatus: string implements HasLabel
{
    case NOTIFIED = 'NOTIFIED';
    case NO_EMAIL = 'NO_EMAIL';

    public function label(): string
    {
        return match ($this) {
            self::NOTIFIED => __('Notificado por email'),
            self::NO_EMAIL => __('Sin email — no notificado'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
