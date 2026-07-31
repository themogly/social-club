<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Scopes\LocationScope;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Barra y tienda');
    }

    public function table(Table $table): Table
    {
        return $table
            // Read-only history: no create/edit/delete row actions. A socio is org-wide, so
            // surface every location's orders (drop the active-location scope like the wallet does).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope(LocationScope::class))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Fecha'))->dateTime()->sortable(),
                TextColumn::make('lines')
                    ->label(__('Líneas'))
                    ->state(fn (Order $record): int => count($record->items ?? [])),
                TextColumn::make('total_cents')
                    ->label(__('Total'))
                    ->state(fn (Order $record): int => $record->total_cents->cents)
                    ->money('EUR', divideBy: 100),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (OrderStatus $state): string => match ($state) {
                        OrderStatus::COMPLETED => 'success',
                        OrderStatus::VOIDED => 'danger',
                    }),
            ])
            ->emptyStateHeading(__('Sin ventas'))
            ->emptyStateDescription(__('Este socio no tiene ventas de barra registradas.'));
    }
}
