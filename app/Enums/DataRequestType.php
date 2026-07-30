<?php

namespace App\Enums;

enum DataRequestType: string
{
    case ACCESS = 'ACCESS';
    case RECTIFY = 'RECTIFY';
    case ERASE = 'ERASE';
    case PORTABILITY = 'PORTABILITY';
    case OBJECT = 'OBJECT';
    case RESTRICT = 'RESTRICT';
}
