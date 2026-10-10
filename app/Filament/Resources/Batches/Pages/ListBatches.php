<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Pages\PreciosSede;
use App\Filament\Resources\Batches\BatchResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBatches extends ListRecords
{
    protected static string $resource = BatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Prompt 382 — price every batch of a sede in one screen.
            Action::make('sedePrices')
                ->label(__('Precios de la sede'))
                ->icon(Heroicon::OutlinedTag)
                ->color('gray')
                ->url(fn (): string => PreciosSede::getUrl())
                ->visible(fn (): bool => PreciosSede::canAccess()),
            CreateAction::make(),
        ];
    }
}
