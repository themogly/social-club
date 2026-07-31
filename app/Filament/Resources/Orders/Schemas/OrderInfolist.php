<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A bar / merch ticket as an Infolist — the frozen item snapshot, the tender split and,
 * when the ticket was reversed, the anulación detail. Read-only: an order is never edited,
 * a correction is a void plus a fresh linked row.
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Venta'))
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('Estado'))
                            ->badge()
                            ->color(fn (OrderStatus $state): string => match ($state) {
                                OrderStatus::COMPLETED => 'success',
                                OrderStatus::VOIDED => 'danger',
                            }),
                        TextEntry::make('reference')->label(__('Referencia'))->placeholder('—'),
                        TextEntry::make('created_at')->label(__('Fecha'))->dateTime(),
                        // fullName() is a method, not an accessor — resolve it in state (guest orders have no socio).
                        TextEntry::make('socio')
                            ->label(__('Socio'))
                            ->state(fn (Order $record): string => $record->member?->fullName() ?? '—'),
                        TextEntry::make('operator.name')->label(__('Operador'))->placeholder('—'),
                        TextEntry::make('location.name')->label(__('Sede'))->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make(__('Importe'))
                    ->schema([
                        TextEntry::make('total_cents')
                            ->label(__('Total'))
                            ->state(fn (Order $record): string => Money::fromCents($record->total_cents->cents)->formatted()),
                        TextEntry::make('cash_cents')
                            ->label(__('Efectivo'))
                            ->state(fn (Order $record): string => Money::fromCents($record->cash_cents->cents)->formatted()),
                        TextEntry::make('wallet_cents')
                            ->label(__('Monedero'))
                            ->state(fn (Order $record): string => Money::fromCents($record->wallet_cents->cents)->formatted()),
                    ])
                    ->columns(3),

                Section::make(__('Líneas'))
                    ->schema([
                        // Each item snapshot is a plain array (name/qty/line_total_cents are plain ints);
                        // format one readable line per item — cents to euros at the display edge only.
                        TextEntry::make('lines')
                            ->hiddenLabel()
                            ->state(fn (Order $record): array => collect($record->items ?? [])
                                ->map(fn (mixed $line): string => sprintf(
                                    '%s × %d — %s',
                                    (string) data_get($line, 'name', '—'),
                                    (int) data_get($line, 'qty', 1),
                                    Money::fromCents((int) data_get($line, 'line_total_cents', 0))->formatted(),
                                ))
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                // Only meaningful once the ticket has been reversed.
                Section::make(__('Anulación'))
                    ->visible(fn (Order $record): bool => $record->status === OrderStatus::VOIDED)
                    ->schema([
                        TextEntry::make('void_reason')->label(__('Motivo de anulación'))->placeholder('—')->columnSpanFull(),
                        TextEntry::make('voidedBy.name')->label(__('Anulada por'))->placeholder('—'),
                        TextEntry::make('voided_at')->label(__('Anulada el'))->dateTime()->placeholder('—'),
                    ])
                    ->columns(3),
            ]);
    }
}
