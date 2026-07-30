<?php

namespace App\Filament\Resources\Purchases\Tables;

use App\Models\Purchase;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PurchasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('purchased_on', 'desc')
            ->columns([
                TextColumn::make('supplier.name')->label(__('Proveedor'))->searchable()->sortable(),
                TextColumn::make('purchased_on')->label(__('Fecha'))->date()->sortable(),
                TextColumn::make('amount_cents')
                    ->label(__('Importe'))
                    ->state(fn (Purchase $record): int => $record->amount_cents->cents)
                    ->money('EUR', divideBy: 100)
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('paid_cents')
                    ->label(__('Pagado'))
                    ->state(fn (Purchase $record): int => $record->paid_cents->cents)
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('owing')
                    ->label(__('Saldo pendiente'))
                    ->state(fn (Purchase $record): int => $record->amount_cents->cents - $record->paid_cents->cents)
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->color(fn (Purchase $record): string => ($record->amount_cents->cents - $record->paid_cents->cents) > 0 ? 'warning' : 'gray'),
                TextColumn::make('batch.batch_no')->label(__('Lote'))->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
