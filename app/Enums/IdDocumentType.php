<?php

namespace App\Enums;

enum IdDocumentType: string
{
    case DNI = 'DNI';
    case NIE = 'NIE';
    case PASSPORT = 'PASSPORT';
}
