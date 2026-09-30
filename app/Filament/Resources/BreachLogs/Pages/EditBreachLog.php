<?php

namespace App\Filament\Resources\BreachLogs\Pages;

use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\BreachLogs\BreachLogResource;
use Filament\Resources\Pages\EditRecord;

class EditBreachLog extends EditRecord
{
    use ReturnsToList;

    protected static string $resource = BreachLogResource::class;
}
