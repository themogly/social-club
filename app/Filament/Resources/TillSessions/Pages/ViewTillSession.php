<?php

namespace App\Filament\Resources\TillSessions\Pages;

use App\Filament\Resources\TillSessions\TillSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTillSession extends ViewRecord
{
    protected static string $resource = TillSessionResource::class;

    /** No edit action — a closed session is immutable; corrections are new entries. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
