<?php

namespace App\Filament\Resources\TillSessions\Schemas;

use App\Enums\TillSessionStatus;
use App\Models\AuditLog;
use App\Models\TillSession;
use App\Support\Money;
use App\Support\Weight;
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
                // Prompt 366 — a close is never refused for a difference, so the till page leads with it: every pot's figures,
                // the note (or «Sin nota») and the flower count's answer (or «Sin motivo»). Only beyond the tolerance.
                Section::make(__('Diferencia en el cierre'))
                    ->schema([
                        TextEntry::make('difference_lines')->hiddenLabel()
                            ->state(fn (TillSession $record): array => self::differenceLines($record))
                            ->listWithLineBreaks()->bulleted()->columnSpanFull(),
                        TextEntry::make('difference_note')->label(__('Nota'))
                            ->state(fn (TillSession $record): string => filled($record->notes) ? (string) $record->notes : __('Sin nota'))
                            ->color(fn (TillSession $record): string => filled($record->notes) ? 'gray' : 'warning'),
                        TextEntry::make('difference_flower')->label(__('Recuento de flor'))
                            ->state(fn (TillSession $record): ?string => self::flowerAnswer($record))
                            ->color(fn (TillSession $record): string => self::flowerAnswer($record) === __('Sin motivo') ? 'warning' : 'gray')
                            ->visible(fn (TillSession $record): bool => self::flowerAnswer($record) !== null),
                        TextEntry::make('difference_unexplained')->hiddenLabel()
                            ->state(__('Sin explicar'))->badge()->color('warning')
                            ->visible(fn (TillSession $record): bool => $record->closedUnexplained()),
                    ])
                    ->columns(3)
                    ->columnSpanFull()
                    ->visible(fn (TillSession $record): bool => $record->closedBeyondTolerance()),

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
                        self::money('rounding', __('Redondeo (incluido en la dispensación)')), // prompt 350
                        // Prompt 359 — for the manager: bags opened into a jar with no «Rellenar», absorbed by the close count.
                        TextEntry::make('unrecorded_topup_cg')->label(__('Rellenado sin registrar'))
                            ->state(fn (TillSession $record): string => Weight::fromCentigrams((int) (self::report($record)['unrecorded_topup_cg'] ?? 0))->formatted())
                            ->visible(fn (TillSession $record): bool => (int) (self::report($record)['unrecorded_topup_cg'] ?? 0) > 0),
                        // Prompt 360 — the evening flower count: each jar's variance and staff's one answer when it was off.
                        TextEntry::make('stock_count_lines')->label(__('Recuento de flor'))
                            ->state(fn (TillSession $record): array => (array) (self::report($record)['stock_count_lines'] ?? []))
                            ->listWithLineBreaks()->bulleted()
                            ->visible(fn (TillSession $record): bool => (self::report($record)['stock_count_lines'] ?? []) !== []),
                        TextEntry::make('stock_count_reason')->label(__('Motivo del recuento de flor'))
                            ->state(fn (TillSession $record): ?string => self::report($record)['stock_count_reason'] ?? null)
                            ->color('warning')
                            ->visible(fn (TillSession $record): bool => filled(self::report($record)['stock_count_reason'] ?? null)),
                        // Prompt 265 — what each petty-cash expense was for, from the same breakdown the counter uses.
                        TextEntry::make('petty_cash_items')
                            ->label(__('Detalle de caja chica'))
                            ->state(fn (TillSession $record): array => array_map(
                                fn (array $i): string => ($i['note'] ?: __('Sin nota')).' — '.$i['category'].' · '.Money::fromCents($i['amount_cents'])->formatted().' · '.$i['recorded_by'].' · '.$i['at'],
                                (array) (self::report($record)['petty_cash_items'] ?? []),
                            ))
                            ->listWithLineBreaks()->bulleted()
                            ->placeholder(__('Sin gastos de caja'))
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make(__('Arqueo'))
                    ->schema([
                        self::money('expected', __('Esperado')),
                        self::money('counted', __('Contado')),
                        self::money('variance', __('Diferencia'))
                            ->color(fn (TillSession $record): string => (int) (self::report($record)['variance'] ?? 0) !== 0 ? 'danger' : 'gray'),
                        // Prompt 103: a closed session whose ledger moved after cierre (a post-close void) shows
                        // the cash-up FIXED at close; this flags that the register has since changed.
                        TextEntry::make('post_close_note')
                            ->label(__('Aviso'))
                            ->state(fn (TillSession $record): ?string => (self::report($record)['post_close_adjusted'] ?? false)
                                ? __('Ajustada tras el cierre: el arqueo mostrado es el fijado al cerrar; el registro ha cambiado desde entonces (recálculo actual: :amount).', ['amount' => Money::fromCents((int) self::report($record)['expected_live'])->formatted()])
                                : null)
                            ->visible(fn (TillSession $record): bool => (bool) (self::report($record)['post_close_adjusted'] ?? false))
                            ->color('warning')
                            ->columnSpanFull(),
                        TextEntry::make('notes')->label(__('Nota'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * «Dispensario: esperado 100.00 € · contado 80.00 € · diferencia -20.00 €», one line per pot counted (or «no contado»).
     *
     * @return list<string>
     */
    private static function differenceLines(TillSession $record): array
    {
        $line = fn (string $label, int $expected, ?int $counted, ?int $variance): string => $counted === null
            ? __(':pot: esperado :expected · no contado', ['pot' => $label, 'expected' => Money::fromCents($expected)->formatted()])
            : __(':pot: esperado :expected · contado :counted · diferencia :variance', ['pot' => $label,
                'expected' => Money::fromCents($expected)->formatted(), 'counted' => Money::fromCents($counted)->formatted(),
                'variance' => ($variance > 0 ? '+' : '').Money::fromCents((int) $variance)->formatted()]);
        $raw = fn (string $column): ?int => $record->getRawOriginal($column) === null ? null : (int) $record->getRawOriginal($column);

        // Prompt 373 — the till, then each of the session's own boxes (edibles too).
        $lines = [$line($record->ownBoxes() !== [] ? __('La caja') : __('Efectivo'), (int) $raw('expected_cents'), $raw('counted_cents'), $raw('variance_cents'))];
        foreach ($record->ownBoxes() as $pot) {
            $lines[] = $line($pot->label(), (int) $raw($pot->column().'_expected_cents'), $raw($pot->column().'_counted_cents'), $raw($pot->column().'_variance_cents'));
        }

        return $lines;
    }

    /** The evening flower count's answer as the close recorded it: the reason, «Sin motivo», or null (nothing was off). */
    private static function flowerAnswer(TillSession $record): ?string
    {
        /** @var array<string, ?string> $cache */
        static $cache = [];

        return array_key_exists($record->id, $cache) ? $cache[$record->id] : $cache[$record->id] = self::readFlowerAnswer($record);
    }

    private static function readFlowerAnswer(TillSession $record): ?string
    {
        $after = AuditLog::query()->withoutGlobalScopes()->where('action', 'till.closed_with_variance')
            ->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->latest('id')->value('after');
        $after = is_array($after) ? $after : (array) json_decode((string) $after, true);

        return match (true) {
            filled($after['flower_reason'] ?? null) => (string) $after['flower_reason'],
            (bool) ($after['flower_unexplained'] ?? false) => __('Sin motivo'),
            default => self::report($record)['stock_count_reason'] ?? null,
        };
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
