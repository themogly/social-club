<?php

namespace App\Enums;

enum CheckInMethod: string
{
    case QR = 'QR';
    case MANUAL = 'MANUAL';
}
