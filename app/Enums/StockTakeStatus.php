<?php

namespace App\Enums;

enum StockTakeStatus: string
{
    case OPEN = 'OPEN';
    case COMMITTED = 'COMMITTED';
}
