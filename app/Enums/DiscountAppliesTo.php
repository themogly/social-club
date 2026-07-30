<?php

namespace App\Enums;

enum DiscountAppliesTo: string
{
    case GENETIC = 'GENETIC';
    case ARTICLE = 'ARTICLE';
    case BOTH = 'BOTH';
}
