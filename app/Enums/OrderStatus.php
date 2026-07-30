<?php

namespace App\Enums;

enum OrderStatus: string
{
    case COMPLETED = 'COMPLETED';
    case VOIDED = 'VOIDED';
}
