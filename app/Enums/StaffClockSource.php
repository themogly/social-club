<?php

namespace App\Enums;

/** How a registro de jornada event came to be written (prompt 281). Only PIN and TILL_CLOSE are clocked live. */
enum StaffClockSource: string
{
    case PIN = 'PIN';
    case TILL_CLOSE = 'TILL_CLOSE';
    case TILL_OPEN = 'TILL_OPEN'; // prompt 338 — opening the till clocked the opener in (their own PIN, their own act)
    case SELF_DECLARED = 'SELF_DECLARED';
    case MANAGER_CORRECTION = 'MANAGER_CORRECTION';

    public function label(): string
    {
        return match ($this) {
            self::PIN => __('Fichado con PIN'),
            self::TILL_CLOSE => __('Al cerrar la caja'),
            self::TILL_OPEN => __('Al abrir la caja'),
            self::SELF_DECLARED => __('Hora declarada'),
            self::MANAGER_CORRECTION => __('Corrección'),
        };
    }

    /** A time somebody typed rather than one clocked live — needs a reason and is flagged on the report. */
    public function isDeclared(): bool
    {
        return $this === self::SELF_DECLARED || $this === self::MANAGER_CORRECTION;
    }
}
