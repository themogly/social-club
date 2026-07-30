<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventRsvpStatus: string implements HasLabel
{
    case GOING = 'GOING';
    case MAYBE = 'MAYBE';
    case DECLINED = 'DECLINED';

    public function label(): string
    {
        return match ($this) {
            self::GOING => __('Asistiré'),
            self::MAYBE => __('Quizás'),
            self::DECLINED => __('No asistiré'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
