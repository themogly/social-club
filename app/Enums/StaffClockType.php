<?php

namespace App\Enums;

/** A registro de jornada event (prompt 281): the start or end of a working period, or the annulment of an earlier event. */
enum StaffClockType: string
{
    case IN = 'IN';
    case OUT = 'OUT';
    case ANNUL = 'ANNUL';

    public function label(): string
    {
        return match ($this) {
            self::IN => __('Entrada'),
            self::OUT => __('Salida'),
            self::ANNUL => __('Anulación'),
        };
    }
}
