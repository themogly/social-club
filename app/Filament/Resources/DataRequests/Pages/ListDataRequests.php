<?php

namespace App\Filament\Resources\DataRequests\Pages;

use App\Filament\Resources\DataRequests\DataRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDataRequests extends ListRecords
{
    protected static string $resource = DataRequestResource::class;

    public function getSubheading(): ?string
    {
        return __('Deja constancia de que el club atendió cada derecho en plazo. La supresión anonimiza sin borrar el libro; el acceso y la portabilidad generan el paquete de datos del socio.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Registrar solicitud')),
        ];
    }
}
