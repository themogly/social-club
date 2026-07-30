<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ApplicationStatus: string implements HasLabel
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case WAITING_LIST = 'WAITING_LIST';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => __('Pendiente'),
            self::APPROVED => __('Aprobada'),
            self::REJECTED => __('Rechazada'),
            self::WAITING_LIST => __('Lista de espera'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
