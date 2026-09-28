<?php

namespace App\Enums;

/**
 * What a location IS (prompt 277, Ben's 270). A SEDE is a premises with a counter — members, tills, dispensing. An
 * ALMACEN is the grow / central store: stock is received and held there and assigned to sedes a bit at a time, but it
 * has no counter, no members and no till, and it never appears in the counter's sede picker.
 */
enum LocationKind: string
{
    case SEDE = 'SEDE';
    case ALMACEN = 'ALMACEN';

    public function label(): string
    {
        return match ($this) {
            self::SEDE => __('Sede'),
            self::ALMACEN => __('Almacén / cultivo'),
        };
    }
}
