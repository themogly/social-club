<?php

namespace App\Filament\Resources\Genetics\Tables;

use App\Enums\BatchStatus;
use App\Models\Genetic;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class GeneticsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
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
                // Sum of OPEN batches' remaining_cg at the active location, in grams. Using the
                // relation keeps the active LocationScope on Batch in force (per-location stock).
                TextColumn::make('stock_g')
                    ->label(__('Stock (g)'))
                    ->state(fn (Genetic $record): string => number_format((float) $record->batches()->where('status', BatchStatus::OPEN->value)->sum('remaining_cg') / 100, 2).' g'),
                IconColumn::make('published')->label(__('Publicada'))->boolean(),
                IconColumn::make('active')->label(__('Activa'))->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
