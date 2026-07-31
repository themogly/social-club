<?php

namespace App\Filament\Resources\Dispensations\Pages;

use App\Filament\Resources\Dispensations\DispensationResource;
use Filament\Resources\Pages\ListRecords;

class ListDispensations extends ListRecords
{
    protected static string $resource = DispensationResource::class;

    /** No create action — withdrawals are recorded at the counter, not the panel. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
