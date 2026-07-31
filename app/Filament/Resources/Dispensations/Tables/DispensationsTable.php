<?php

namespace App\Filament\Resources\Dispensations\Tables;

use App\Enums\DispensationStatus;
use App\Filament\Resources\Dispensations\DispensationResource;
use App\Models\Dispensation;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DispensationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('dispensed_at', 'desc')
            ->columns([
                TextColumn::make('dispensed_at')->label(__('Fecha'))->dateTime()->sortable(),
                // fullName() is a method, not an accessor — resolve it in state.
                TextColumn::make('socio')
                    ->label(__('Socio'))
                    ->state(fn (Dispensation $record): string => $record->member?->fullName() ?? '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'member',
                        fn (Builder $q): Builder => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"),
                    )),
                TextColumn::make('operator.name')->label(__('Operador'))->placeholder('—'),
                TextColumn::make('location.name')->label(__('Sede'))->sortable()->toggleable(),
                TextColumn::make('reference')->label(__('Referencia'))->placeholder('—')->searchable()->toggleable(),
                TextColumn::make('total_cents')
                    ->label(__('Total'))
                    ->state(fn (Dispensation $record): int => $record->total_cents->cents)
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (DispensationStatus $state): string => match ($state) {
                        DispensationStatus::COMPLETED => 'success',
                        DispensationStatus::VOIDED => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options(collect(DispensationStatus::cases())
                        ->mapWithKeys(fn (DispensationStatus $case): array => [$case->value => $case->label()])
                        ->all()),
                SelectFilter::make('location')
                    ->label(__('Sede'))
                    ->relationship('location', 'name'),
                Filter::make('dispensed_at')
                    ->schema([
                        DatePicker::make('from')->label(__('Desde')),
                        DatePicker::make('until')->label(__('Hasta')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('dispensed_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('dispensed_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                DispensationResource::refundAction(),
            ]);
    }
}
