<?php

namespace App\Enums;

/**
 * Prompt 360 — the end-of-day weigh's ONE question, asked only when the count is off: what happened? A quick pick covers
 * the whole count (Ben: "it just gives them one reason to fill out if wrong"); «Otro» takes a short line. Stored as the
 * label on the stock take and copied onto its adjustments, so it reads the same wherever the manager meets it.
 */
enum CloseCountReason: string
{
    case WEIGHING_ERROR = 'WEIGHING_ERROR';
    case SPILL = 'SPILL';
    case UNRECORDED_TOPUP = 'UNRECORDED_TOPUP';
    case JAR_UNAVAILABLE = 'JAR_UNAVAILABLE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::WEIGHING_ERROR => __('Error al pesar'),
            self::SPILL => __('Derrame / merma'),
            self::UNRECORDED_TOPUP => __('Rellené sin registrar'),
            self::JAR_UNAVAILABLE => __('Bote no disponible'),
            self::OTHER => __('Otro'),
        };
    }
}
