<?php

namespace App\Enums;

/**
 * A shift within an open till session (prompt 186).
 *
 * Deliberately NOT a third case on `TillSessionStatus`. The owner's fork is that the drawer belongs to the
 * till and the session continues through a handover as one arqueo — so the session is never "between
 * people"; the SHIFT is what begins and ends. Toast's middle state is expressed here as a session that is
 * OPEN with no OPEN shift: nobody holds the drawer, so nothing may be charged to it.
 */
enum TillShiftStatus: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => __('En curso'),
            self::CLOSED => __('Entregado'),
        };
    }
}
