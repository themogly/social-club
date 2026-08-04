<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a member is present at an assembly. Both count toward the quorum — representation (proxy) is a valid
 * presence in a Spanish asociación, so a represented member is as "present" for quorum as one in the room.
 */
enum AttendanceMode: string implements HasLabel
{
    case PRESENT = 'PRESENT';
    case PROXY = 'PROXY';

    public function label(): string
    {
        return match ($this) {
            self::PRESENT => __('Presente'),
            self::PROXY => __('Representado'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
