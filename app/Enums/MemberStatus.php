<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MemberStatus: string implements HasLabel
{
    case APPLICANT = 'APPLICANT';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case EXPIRED = 'EXPIRED';
    case SUSPENDED = 'SUSPENDED';
    case EXPELLED = 'EXPELLED';

    public function label(): string
    {
        return match ($this) {
            self::APPLICANT => __('Solicitante'),
            self::ACTIVE => __('Activo'),
            self::INACTIVE => __('Inactivo'),
            self::EXPIRED => __('Caducado'),
            self::SUSPENDED => __('Suspendido'),
            self::EXPELLED => __('Expulsado'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
