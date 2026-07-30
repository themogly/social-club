<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case TOPUP = 'TOPUP';
    case CONTRIBUTION = 'CONTRIBUTION';
    case FEE = 'FEE';
    case REFUND = 'REFUND';
    case ADJUSTMENT = 'ADJUSTMENT';
    case TRANSFER_IN = 'TRANSFER_IN';
    case TRANSFER_OUT = 'TRANSFER_OUT';
}
