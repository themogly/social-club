<?php

namespace App\Enums;

enum Role: string
{
    case OWNER = 'OWNER';
    case MANAGER = 'MANAGER';
    case STAFF = 'STAFF';
}
