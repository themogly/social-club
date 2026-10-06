<?php

namespace App\Enums;

/**
 * Prompt 318 — why a counted line differs from the system beyond the tolerance. OVERNIGHT-DEFAULT — CONFIRM with Ben (the
 * list, and the tolerance it applies above). Carried onto the ADJUSTMENT's reason.
 */
enum StockCountReason: string
{
    case MERMA = 'MERMA';
    case RECORDING_ERROR = 'RECORDING_ERROR';
    case THEFT_OR_LOSS = 'THEFT_OR_LOSS';
    case OTHER = 'OTHER';
    // Prompt 360 — the go-live cleanup: putting the sealed bags 359 introduced into the system, in one pass per sede.
    case RESERVE_REGULARISATION = 'RESERVE_REGULARISATION';

    public function label(): string
    {
        return match ($this) {
            self::MERMA => __('Merma'),
            self::RECORDING_ERROR => __('Error de registro'),
            self::THEFT_OR_LOSS => __('Robo o pérdida'),
            self::OTHER => __('Otro'),
            self::RESERVE_REGULARISATION => __('Regularización: alta de la reserva sellada'),
        };
    }
}
