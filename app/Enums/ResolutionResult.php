<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** The outcome of an agenda item put to the assembly (show of hands). */
enum ResolutionResult: string implements HasLabel
{
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case DEFERRED = 'DEFERRED';

    public function label(): string
    {
        return match ($this) {
            self::APPROVED => __('Aprobado'),
            self::REJECTED => __('Rechazado'),
            self::DEFERRED => __('Aplazado'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * The stable Spanish term written INTO an acta snapshot. An acta is a Spanish legal document, so its
     * stored content must not shift with the drafting user's UI locale — this is deliberately not __().
     */
    public function actaTerm(): string
    {
        return match ($this) {
            self::APPROVED => 'Aprobado',
            self::REJECTED => 'Rechazado',
            self::DEFERRED => 'Aplazado',
        };
    }
}
