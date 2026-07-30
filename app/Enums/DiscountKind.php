<?php

namespace App\Enums;

enum DiscountKind: string
{
    case STAFF = 'STAFF';
    case LOCAL = 'LOCAL';
    case CONCESSION = 'CONCESSION';
    case THERAPEUTIC = 'THERAPEUTIC';
    case CUSTOM = 'CUSTOM';
}
