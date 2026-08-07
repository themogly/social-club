<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Fecha'))->dateTime()->sortable(),
                // fullName() is a method, not an accessor — resolve it in state (guest orders have no socio).
                TextColumn::make('socio')
                    ->label(__('Socio'))
                    ->state(fn (Order $record): string => $record->member?->fullName() ?? '—'),
                TextColumn::make('operator.name')->label(__('Operador'))->placeholder('—'),
                TextColumn::make('location.name')->label(__('Sede'))->sortable()->toggleable(),
                TextColumn::make('reference')->label(__('Referencia'))->placeholder('—')->toggleable(),
                TextColumn::make('total_cents')
                    ->label(__('Total'))
                    ->state(fn (Order $record): int => $record->total_cents->cents)
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (OrderStatus $state): string => match ($state) {
                        OrderStatus::COMPLETED => 'success',
                        OrderStatus::VOIDED => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options(collect(OrderStatus::cases())
                        ->mapWithKeys(fn (OrderStatus $case): array => [$case->value => $case->label()])
                        ->all()),
                // Meaningful in the owner rollup ("All locations"), a no-op when one sede is active.
                SelectFilter::make('location')
                    ->label(__('Sede'))
                    ->relationship('location', 'name'),
                SelectFilter::make('operator')
                    ->label(__('Operador'))
                    ->relationship('operator', 'name'),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')->label(__('Desde')),
                        DatePicker::make('created_until')->label(__('Hasta')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['created_from'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date),
                        )
                        ->when(
                            $data['created_until'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date),
                        )),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin ventas de barra'))
            ->emptyStateDescription(__('Las ventas de barra y tienda se registran en el TPV de barra. Esta pantalla es solo de consulta.'));
    }
}
