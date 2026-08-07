<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Models\Location;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
                // Derived (prompt 93): a sede with no active price is a counter that sells nothing. Never stored.
                TextColumn::make('prices_gap')
                    ->label(__('Precios'))
                    ->badge()
                    ->state(fn (Location $record): string => $record->hasActivePrices() ? __('Con precios') : __('Sin precios'))
                    ->color(fn (Location $record): string => $record->hasActivePrices() ? 'success' : 'warning')
                    ->tooltip(fn (Location $record): ?string => $record->hasActivePrices() ? null : __('Sin precios, esta sede no puede dispensar nada. Añade precios por genética.')),
                TextColumn::make('address')->label(__('Dirección'))->toggleable(),
                TextColumn::make('capacity')->label(__('Aforo'))->sortable(),
                TextColumn::make('timezone')->label(__('Zona horaria'))->toggleable(),
                IconColumn::make('active')->label(__('Activo'))->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin sedes'))
            ->emptyStateDescription(__('Cada sede es un local con su propio stock, caja y aforo. Crea una para poder abrir caja y dispensar.'));
    }
}
