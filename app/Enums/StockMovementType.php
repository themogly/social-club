<?php

namespace App\Enums;

enum StockMovementType: string
{
    case INTAKE = 'INTAKE';
    case DISPENSE = 'DISPENSE';
    case SALE = 'SALE';
    case ADJUSTMENT = 'ADJUSTMENT';
    case MERMA = 'MERMA';
    case TRANSFER_IN = 'TRANSFER_IN';
    case TRANSFER_OUT = 'TRANSFER_OUT';
}
