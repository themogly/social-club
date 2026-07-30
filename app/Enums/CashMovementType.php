<?php

namespace App\Enums;

enum CashMovementType: string
{
    case IN = 'IN';
    case OUT = 'OUT';
    case BANKED = 'BANKED';
    case PETTY_CASH = 'PETTY_CASH';
}
