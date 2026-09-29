<?php

namespace App\Support;

use App\Console\Commands\Install;
use App\Console\Commands\ResetForLaunch;

/**
 * Prompt 306 — what to do after installing (`csc:install`) or resetting for launch (`csc:reset-for-launch`), in ONE list
 * so the two never drift apart again. `csc:install` still told people to "price every genetic at each sede" long after
 * prices moved onto batches (278).
 *
 * @see Install
 * @see ResetForLaunch
 */
final class NextSteps
{
    /** @return list<string> */
    public static function setUp(): array
    {
        return [
            __('Crea las sedes y el almacén.'),
            __('Da a cada persona su PIN.'),
            __('Añade el stock con «:action» (el precio va en cada lote).', ['action' => __('Crear lote')]),
            __('Después importa los socios.'),
        ];
    }
}
