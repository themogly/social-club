<?php

namespace App\Filament\Resources\Convocatorias\Pages;

use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\Convocatorias\ConvocatoriaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditConvocatoria extends EditRecord
{
    use ReturnsToList;

    protected static string $resource = ConvocatoriaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Delete is withheld by ConvocatoriaPolicy once issued.
            DeleteAction::make(),
        ];
    }
}
