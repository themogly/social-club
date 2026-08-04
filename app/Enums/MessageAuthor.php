<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Who wrote a message in a member↔club thread. */
enum MessageAuthor: string implements HasLabel
{
    case MEMBER = 'MEMBER';
    case STAFF = 'STAFF';

    public function label(): string
    {
        return match ($this) {
            self::MEMBER => __('Socio'),
            self::STAFF => __('Club'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
