<?php

namespace App\Enums;

enum ExpensePaidFrom: string
{
    case TILL_CASH = 'TILL_CASH';
    case BANK = 'BANK';
    case CARD = 'CARD';
    case OTHER = 'OTHER';
}
