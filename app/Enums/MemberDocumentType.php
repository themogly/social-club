<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MemberDocumentType: string implements HasLabel
{
    case ID = 'ID';
    case CONSENT = 'CONSENT';
    case DECLARATION = 'DECLARATION';
    case MEDICAL = 'MEDICAL';
    case REGISTRATION_FORM = 'REGISTRATION_FORM';
    case SANCTION_ACT = 'SANCTION_ACT';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::ID => __('Documento de identidad'),
            self::CONSENT => __('Consentimiento'),
            self::DECLARATION => __('Previsión de consumo'),
            self::MEDICAL => __('Documentación médica'),
            self::REGISTRATION_FORM => __('Solicitud de alta'),
            self::SANCTION_ACT => __('Acta de sanción'),
            self::OTHER => __('Otro'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
