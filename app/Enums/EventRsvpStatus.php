<?php

namespace App\Enums;

enum EventRsvpStatus: string
{
    case GOING = 'GOING';
    case MAYBE = 'MAYBE';
    case DECLINED = 'DECLINED';
}
