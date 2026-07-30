<?php

namespace App\Enums;

enum FeePaymentMethod: string
{
    case CASH = 'CASH';
    case WALLET = 'WALLET';
    case BANK = 'BANK';
    case CARD = 'CARD';
}
