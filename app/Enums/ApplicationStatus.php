<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case WAITING_LIST = 'WAITING_LIST';
}
