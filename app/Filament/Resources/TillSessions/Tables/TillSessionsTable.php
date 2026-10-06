<?php

namespace App\Filament\Resources\TillSessions\Tables;

use App\Enums\CashPot;
use App\Enums\TillSessionStatus;
use App\Models\TillSession;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TillSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('terminal')->label(__('Terminal'))->searchable()->sortable(),
                TextColumn::make('location.name')->label(__('Sede'))->sortable()->toggleable(),
                TextColumn::make('opened_at')->label(__('Apertura'))->dateTime()->sortable(),
                TextColumn::make('closed_at')->label(__('Cierre'))->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (TillSessionStatus $state): string => match ($state) {
                        TillSessionStatus::OPEN => 'warning',
                        TillSessionStatus::CLOSED => 'gray',
                    }),
                TextColumn::make('expected_cents')
                    ->label(__('Esperado'))
                    ->state(fn (TillSession $record): ?int => $record->expected_cents?->cents)
                    ->money('EUR', divideBy: 100)
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('counted_cents')
                    ->label(__('Contado'))
                    ->state(fn (TillSession $record): ?int => $record->counted_cents?->cents)
                    ->money('EUR', divideBy: 100)
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('variance_cents')
                    ->label(__('Diferencia'))
                    ->state(fn (TillSession $record): ?int => $record->variance_cents?->cents)
                    ->money('EUR', divideBy: 100)
                    ->placeholder('—')
                    ->alignEnd()
                    // Colour the variance red whenever it is non-zero (a clean arqueo is €0);
                    // an open session has no variance yet (null state) and stays neutral.
                    ->color(fn (?int $state): string => ($state ?? 0) !== 0 ? 'danger' : 'gray'),
                // Prompt 349 — with separate cash pots the three columns above are the DISPENSARY pot's; the bar and fees
                // pots are summarised here (counted with its difference, or not counted with what it carried).
                TextColumn::make('pots')
                    ->label(__('Barra · Cuotas'))
                    ->state(fn (TillSession $record): ?string => $record->separate_pots && $record->closed_at !== null
                        ? collect(CashPot::optional())->map(function (CashPot $pot) use ($record): string {
                            $counted = $record->getRawOriginal($pot->column().'_counted_cents');
                            $expected = (int) $record->getRawOriginal($pot->column().'_expected_cents');

                            return $pot->label().': '.($counted === null
                                ? __('no contado (:amount)', ['amount' => Money::fromCents($expected)->formatted()])
                                : Money::fromCents((int) $counted)->formatted().' ('.Money::fromCents((int) $counted - $expected)->formatted().')');
                        })->implode(' · ')
                        : null)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options(collect(TillSessionStatus::cases())
                        ->mapWithKeys(fn (TillSessionStatus $case): array => [$case->value => $case->value])
                        ->all()),
                SelectFilter::make('location')
                    ->label(__('Sede'))
                    ->relationship('location', 'name'),
                Filter::make('opened_at')
                    ->schema([
                        DatePicker::make('opened_from')->label(__('Apertura desde')),
                        DatePicker::make('opened_until')->label(__('Apertura hasta')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['opened_from'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->whereDate('opened_at', '>=', $date),
                        )
                        ->when(
                            $data['opened_until'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->whereDate('opened_at', '<=', $date),
                        )),
                // A clean arqueo is €0 — surface the sessions that did not reconcile.
                Filter::make('variances')
                    ->label(__('Con diferencia'))
                    ->query(fn (Builder $query): Builder => $query->where('variance_cents', '!=', 0)),
                Filter::make('open')
                    ->label(__('Solo abiertas'))
                    ->query(fn (Builder $query): Builder => $query->where('status', TillSessionStatus::OPEN->value)),
            ])
            ->recordActions([
                // Prompt 354 — one ⋮ per row, never inline buttons: below 1280 px the actions cell is pinned over the row.
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin cajas'))
            ->emptyStateDescription(__('Las cajas se abren y se cierran en el terminal del mostrador. Aquí se consultan las sesiones y sus descuadres.'));
    }
}
