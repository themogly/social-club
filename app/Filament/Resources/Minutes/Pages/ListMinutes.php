<?php

namespace App\Filament\Resources\Minutes\Pages;

use App\Filament\Resources\Minutes\MinuteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMinutes extends ListRecords
{
    protected static string $resource = MinuteResource::class;

    public function getSubheading(): ?string
    {
        return __('Registro interno de la asociación. No constituye asesoramiento legal.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Nueva acta')),
        ];
    }
}
