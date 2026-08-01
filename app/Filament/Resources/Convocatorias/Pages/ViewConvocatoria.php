<?php

namespace App\Filament\Resources\Convocatorias\Pages;

use App\Filament\Resources\Convocatorias\ConvocatoriaResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewConvocatoria extends ViewRecord
{
    protected static string $resource = ConvocatoriaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConvocatoriaResource::issueAction(),
            // Edit is withheld by ConvocatoriaPolicy once issued.
            EditAction::make(),
            ConvocatoriaResource::pdfAction(),
        ];
    }
}
