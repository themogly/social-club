<?php

namespace App\Enums;

enum BreachStatus: string
{
    case OPEN = 'OPEN';
    case NOTIFIED = 'NOTIFIED';
    case CLOSED = 'CLOSED';
}
