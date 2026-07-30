<?php

namespace App\Filament\Resources\MembershipTiers\Tables;

use App\Models\MembershipTier;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class MembershipTiersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
                TextColumn::make('default_fee_cents')
                    ->label(__('Cuota'))
                    ->state(fn (MembershipTier $record): int => $record->default_fee_cents->cents)
                    ->money('EUR', divideBy: 100)
                    ->sortable(),
                TextColumn::make('default_period')
                    ->label(__('Periodo'))
                    ->badge(),
                IconColumn::make('active')->label(__('Activa'))->boolean(),
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
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
