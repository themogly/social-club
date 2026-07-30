<?php

namespace App\Enums;

enum MemberDocumentType: string
{
    case ID = 'ID';
    case CONSENT = 'CONSENT';
    case DECLARATION = 'DECLARATION';
    case MEDICAL = 'MEDICAL';
    case REGISTRATION_FORM = 'REGISTRATION_FORM';
    case SANCTION_ACT = 'SANCTION_ACT';
    case OTHER = 'OTHER';
}
