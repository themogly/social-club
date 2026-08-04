<?php

namespace App\Filament\Resources\MessageThreads\Pages;

use App\Filament\Resources\MessageThreads\MessageThreadResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMessageThread extends ViewRecord
{
    protected static string $resource = MessageThreadResource::class;

    protected function getHeaderActions(): array
    {
        return MessageThreadResource::recordActions();
    }
}
