<?php

namespace App\Filament\Resources\Genetics\Tables;

use App\Enums\BatchStatus;
use App\Enums\ProductType;
use App\Enums\StrainType;
use App\Models\Genetic;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class GeneticsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
                TextColumn::make('product_type')->label(__('Tipo'))->badge()->sortable(),
                TextColumn::make('strain_type')->label(__('Variedad'))->badge()->placeholder('—')->toggleable(),
                TextColumn::make('category.name')->label(__('Categoría'))->sortable()->toggleable(),
                TextColumn::make('thc_bp')
                    ->label(__('THC'))
                    ->state(fn (Genetic $record): string => number_format(((int) $record->thc_bp) / 100, 2).'%'),
                TextColumn::make('cbd_bp')
                    ->label(__('CBD'))
                    ->state(fn (Genetic $record): string => number_format(((int) $record->cbd_bp) / 100, 2).'%'),
                TextColumn::make('cultivation_type')
                    ->label(__('Cultivo'))
                    ->badge()
                    ->toggleable(),
                // OPEN batches at the active location — grams for WEIGHT genetics, units +
                // gram-equivalent for UNIT genetics. The relation keeps the LocationScope in force.
                TextColumn::make('stock_g')
                    ->label(__('Stock'))
                    ->state(function (Genetic $record): string {
                        $open = $record->batches()->where('status', BatchStatus::OPEN->value);
                        if ($record->isUnitType()) {
                            $units = (int) $open->sum('remaining_units');

                            return $units.' '.__('uds').' ('.number_format($units * (int) $record->grams_per_unit_cg / 100, 2).' g)';
                        }

                        return number_format((float) $open->sum('remaining_cg') / 100, 2).' g';
                    }),
                IconColumn::make('published')->label(__('Publicada'))->boolean(),
                IconColumn::make('active')->label(__('Activa'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('product_type')
                    ->label(__('Tipo de producto'))
                    ->options(collect(ProductType::cases())
                        ->mapWithKeys(fn (ProductType $case): array => [$case->value => $case->label()])
                        ->all()),
                SelectFilter::make('strain_type')
                    ->label(__('Variedad'))
                    ->options(collect(StrainType::cases())
                        ->mapWithKeys(fn (StrainType $case): array => [$case->value => $case->label()])
                        ->all()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
