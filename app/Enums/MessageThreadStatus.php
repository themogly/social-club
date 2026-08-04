<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** A message thread is OPEN until the club closes it (answered/resolved). Closed threads reopen if the member writes again. */
enum MessageThreadStatus: string implements HasColor, HasLabel
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => __('Abierto'),
            self::CLOSED => __('Cerrado'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OPEN => 'warning',
            self::CLOSED => 'gray',
        };
    }
}
