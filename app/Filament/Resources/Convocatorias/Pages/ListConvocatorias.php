<?php

namespace App\Filament\Resources\Convocatorias\Pages;

use App\Filament\Resources\Convocatorias\ConvocatoriaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConvocatorias extends ListRecords
{
    protected static string $resource = ConvocatoriaResource::class;

    public function getSubheading(): ?string
    {
        return __('Convocatoria de asambleas y notificación a la membresía. No constituye asesoramiento legal.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Nueva convocatoria')),
        ];
    }
}
