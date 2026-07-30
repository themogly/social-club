<?php

namespace App\Enums;

enum SanctionType: string
{
    case WARNING = 'WARNING';
    case SUSPENSION = 'SUSPENSION';
    case EXPULSION = 'EXPULSION';
}
