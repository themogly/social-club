<?php

namespace App\Enums;

enum MembershipStatus: string
{
    case ACTIVE = 'ACTIVE';
    case EXPIRING_SOON = 'EXPIRING_SOON';
    case LAPSED = 'LAPSED';
    case CANCELLED = 'CANCELLED';
}
