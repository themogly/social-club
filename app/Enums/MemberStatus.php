<?php

namespace App\Enums;

enum MemberStatus: string
{
    case APPLICANT = 'APPLICANT';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case EXPIRED = 'EXPIRED';
    case SUSPENDED = 'SUSPENDED';
    case EXPELLED = 'EXPELLED';
}
