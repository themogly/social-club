<?php

namespace App\Filament\Resources\TillSessions\Schemas;

use App\Enums\TillSessionStatus;
use App\Models\TillSession;
use App\Support\Money;
use App\Support\ZReport;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The session (Z) report as an Infolist — every figure comes from ZReport::for($record),
 * so the totals equal the sum of the underlying ledger rows. Read-only: a closed session
 * is immutable.
 */
class TillSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Sesión'))
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('Estado'))
                            ->badge()
                            ->color(fn (TillSessionStatus $state): string => match ($state) {
                                TillSessionStatus::OPEN => 'warning',
                                TillSessionStatus::CLOSED => 'gray',
                            }),
                        TextEntry::make('terminal')->label(__('Terminal'))->placeholder('—'),
                        TextEntry::make('location.name')->label(__('Sede'))->placeholder('—'),
                        TextEntry::make('openedBy.name')->label(__('Abierta por'))->placeholder('—'),
                        TextEntry::make('opened_at')->label(__('Apertura'))->dateTime(),
                        TextEntry::make('closedBy.name')->label(__('Cerrada por'))->placeholder('—'),
                        TextEntry::make('closed_at')->label(__('Cierre'))->dateTime()->placeholder('—'),
                        TextEntry::make('transaction_count')
                            ->label(__('Transacciones'))
                            ->state(fn (TillSession $record): int => (int) (self::report($record)['transaction_count'] ?? 0)),
                        TextEntry::make('voids')
                            ->label(__('Anuladas'))
                            ->state(fn (TillSession $record): int => (int) (self::report($record)['voids'] ?? 0)),
                    ])
                    ->columns(3),

                Section::make(__('Efectivo'))
                    ->schema([
                        self::money('float', __('Fondo de caja')),
                        self::money('cash_contributions', __('Dispensación en efectivo')),
                        self::money('wallet_contributions', __('Monedero (excluido del cajón)')),
                        self::money('bar_cash', __('Barra y tienda en efectivo')),
                        self::money('top_ups', __('Recargas de monedero')),
                        self::money('refunds', __('Devoluciones')),
                        self::money('fees_cash', __('Cuotas en efectivo')),
                        self::money('cash_in', __('Entradas de efectivo')),
                        self::money('cash_out', __('Salidas de efectivo')),
                        self::money('banked', __('Ingresado en banco')),
                        self::money('petty_cash', __('Caja chica')),
                    ])
                    ->columns(3),

                Section::make(__('Arqueo'))
                    ->schema([
                        self::money('expected', __('Esperado')),
                        self::money('counted', __('Contado')),
                        self::money('variance', __('Diferencia'))
                            ->color(fn (TillSession $record): string => (int) (self::report($record)['variance'] ?? 0) !== 0 ? 'danger' : 'gray'),
                        TextEntry::make('notes')->label(__('Nota'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    /** A money entry whose formatted value is read from the Z-report at the given key. */
    private static function money(string $key, string $label): TextEntry
    {
        return TextEntry::make($key)
            ->label($label)
            ->state(fn (TillSession $record): string => Money::fromCents((int) (self::report($record)[$key] ?? 0))->formatted());
    }

    /**
     * The Z-report for this record, computed once per request (each entry reads a key).
     *
     * @return array<string, mixed>
     */
    private static function report(TillSession $record): array
    {
        /** @var array<string, array<string, mixed>> $cache */
        static $cache = [];

        return $cache[$record->id] ??= ZReport::for($record);
    }
}
