<?php

namespace App\Enums;

enum BatchStatus: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
    case QUARANTINED = 'QUARANTINED';
}
