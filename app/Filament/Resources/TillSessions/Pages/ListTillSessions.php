<?php

namespace App\Filament\Resources\TillSessions\Pages;

use App\Filament\Resources\TillSessions\TillSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListTillSessions extends ListRecords
{
    protected static string $resource = TillSessionResource::class;

    /** No create action — sessions are opened at the counter, not the panel. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
