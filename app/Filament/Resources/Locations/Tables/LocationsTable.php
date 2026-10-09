<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Enums\LocationKind;
use App\Filament\Resources\Batches\BatchResource;
use App\Models\Location;
use Filament\Actions\ActionGroup;
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
            // Prompt 354 (Ben: "the latest first on all the entries") — newest first; every header still sorts.
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
                TextColumn::make('kind')->label(__('Tipo'))->badge()->formatStateUsing(fn (LocationKind $state): string => $state->label()), // 277
                // Prompt 376 — how many strains in stock the counter can price here, by the counter's own rule
                // (Location::priceCoverage, through ResolvePrice): «Precios: 6 de 8». Never stored. Blank for a store (283).
                TextColumn::make('prices_gap')
                    ->label(__('Precios'))
                    ->badge()
                    ->state(fn (Location $record): ?string => self::pricesBadge($record))
                    ->color(fn (Location $record): string => match (true) {
                        $record->isStore() || $record->priceCoverage()['in_stock'] === 0 => 'gray',
                        $record->priceCoverage()['priced'] === $record->priceCoverage()['in_stock'] => 'success',
                        default => 'warning',
                    })
                    ->tooltip(fn (Location $record): ?string => self::pricesTooltip($record))
                    ->url(fn (Location $record): ?string => self::pricesUrl($record)),
                TextColumn::make('address')->label(__('Dirección'))->toggleable(),
                TextColumn::make('capacity')->label(__('Aforo'))->sortable(),
                TextColumn::make('timezone')->label(__('Zona horaria'))->toggleable(),
                IconColumn::make('active')->label(__('Activo'))->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Prompt 354 — one ⋮ per row, never inline buttons: below 1280 px the actions cell is pinned over the row.
                ActionGroup::make([
                    EditAction::make(),
                ]),
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

    private static function pricesBadge(Location $record): ?string
    {
        if ($record->isStore()) {
            return null;
        }
        $c = $record->priceCoverage();

        return match (true) {
            $c['in_stock'] === 0 => __('Sin existencias'),
            $c['priced'] === 0 => __('Sin precios (0 de :total)', ['total' => $c['in_stock']]),
            default => __('Precios: :priced de :total', ['priced' => $c['priced'], 'total' => $c['in_stock']]),
        };
    }

    private static function pricesTooltip(Location $record): ?string
    {
        if ($record->isStore()) {
            return null;
        }
        $c = $record->priceCoverage();
        if ($c['in_stock'] === 0) {
            return __('No hay lotes abiertos con stock en esta sede.');
        }
        if ($c['missing'] === []) {
            return null;
        }
        $names = array_column($c['missing'], 'name');
        $list = implode(', ', array_slice($names, 0, 5)).(count($names) > 5 ? ' '.__('y :count más', ['count' => count($names) - 5]) : '');

        return __('Sin precio: :names. Ponlo en el lote (Lotes → Precio) o como precio de respaldo de la sede.', ['names' => $list]);
    }

    /** The batches to price: this sede's, filtered to the strains the counter cannot price. */
    private static function pricesUrl(Location $record): ?string
    {
        if ($record->isStore() || $record->priceCoverage()['missing'] === []) {
            return null;
        }

        return BatchResource::getUrl('index', ['filters' => [
            'location_id' => ['value' => $record->id],
            'genetic_id' => ['values' => array_column($record->priceCoverage()['missing'], 'genetic_id')],
        ]]);
    }
}
