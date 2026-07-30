<?php

namespace App\Enums;

enum MembershipPeriod: string
{
    case MONTHLY = 'MONTHLY';
    case YEARLY = 'YEARLY';
    case LIFETIME = 'LIFETIME';
    case CUSTOM = 'CUSTOM';
}
