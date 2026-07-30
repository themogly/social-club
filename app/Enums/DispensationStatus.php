<?php

namespace App\Enums;

enum DispensationStatus: string
{
    case COMPLETED = 'COMPLETED';
    case VOIDED = 'VOIDED';
    case CORRECTED = 'CORRECTED';
}
