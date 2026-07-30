<?php

namespace App\Filament\Resources\Genetics\Pages;

use App\Filament\Resources\Genetics\GeneticResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGenetics extends ListRecords
{
    protected static string $resource = GeneticResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
