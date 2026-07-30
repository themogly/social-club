<?php

namespace App\Filament\Resources\Minutes\Pages;

use App\Filament\Resources\Minutes\MinuteResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMinute extends ViewRecord
{
    protected static string $resource = MinuteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MinuteResource::signAction(),
            // Edit is withheld by MinutePolicy once the acta is signed.
            EditAction::make(),
            MinuteResource::correctAction(),
            MinuteResource::pdfAction(),
        ];
    }
}
