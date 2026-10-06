<?php

namespace App\Filament\Resources\ExpenseCategories\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExpenseCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Prompt 354 (Ben: "the latest first on all the entries") — newest first; every header still sorts.
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
                TextColumn::make('default_kind')->label(__('Tipo por defecto'))->badge(),
                IconColumn::make('active')->label(__('Activo'))->boolean(),
            ])
            ->filters([
                //
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
                ]),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin categorías de gasto'))
            ->emptyStateDescription(__('Las categorías ordenan los gastos en los informes: alquiler, suministros, material, personal. Crea las que use la asociación.'));
    }
}
