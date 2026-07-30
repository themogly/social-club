<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case TOPUP = 'TOPUP';
    case CONTRIBUTION = 'CONTRIBUTION';   // cannabis aportación (dispensary)
    case PURCHASE = 'PURCHASE';           // bar / merch sale paid from the wallet
    case FEE = 'FEE';
    case REFUND = 'REFUND';
    case ADJUSTMENT = 'ADJUSTMENT';
    case TRANSFER_IN = 'TRANSFER_IN';
    case TRANSFER_OUT = 'TRANSFER_OUT';
}
