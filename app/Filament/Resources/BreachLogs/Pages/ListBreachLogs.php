<?php

namespace App\Filament\Resources\BreachLogs\Pages;

use App\Filament\Resources\BreachLogs\BreachLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBreachLogs extends ListRecords
{
    protected static string $resource = BreachLogResource::class;

    public function getSubheading(): ?string
    {
        return __('Registro de incidentes de seguridad. La hora de descubrimiento inicia el plazo de 72 h para notificar a la AEPD (Art. 33).');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Registrar brecha')),
        ];
    }
}
