<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A member's kind. STANDARD is a normal, stable association member; TEMPORARY is a
 * short-stay member who auto-expires after a configured window and is hidden from the
 * everyday members list (prompt 31). The distinction affects ONLY list visibility and
 * retention timing — never any compliance check.
 */
enum MemberKind: string implements HasLabel
{
    case STANDARD = 'STANDARD';
    case TEMPORARY = 'TEMPORARY';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => __('Estándar'),
            self::TEMPORARY => __('Temporal'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
