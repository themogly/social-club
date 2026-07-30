<?php

namespace App\Enums;

enum SettingType: string
{
    case STRING = 'STRING';
    case INT = 'INT';
    case BOOL = 'BOOL';
    case FLOAT = 'FLOAT';
    case JSON = 'JSON';
    case CENTS = 'CENTS';
    case CG = 'CG';
    case BP = 'BP';
}
