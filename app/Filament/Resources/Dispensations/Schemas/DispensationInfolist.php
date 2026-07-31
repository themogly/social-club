<?php

namespace App\Filament\Resources\Dispensations\Schemas;

use App\Enums\DispensationStatus;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Refund;
use App\Support\Money;
use App\Support\Weight;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A dispensation as an Infolist — the header, the tender split, the frozen lines, what has already been
 * refunded (so an operator sees the remaining-refundable figure before acting, prompt 71) and the void
 * detail. Read-only: a dispensation is never edited; a correction is a void plus a fresh linked row.
 */
class DispensationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Dispensación'))
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('Estado'))
                            ->badge()
                            ->color(fn (DispensationStatus $state): string => match ($state) {
                                DispensationStatus::COMPLETED => 'success',
                                DispensationStatus::VOIDED => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('reference')->label(__('Referencia'))->placeholder('—'),
                        TextEntry::make('dispensed_at')->label(__('Fecha'))->dateTime(),
                        TextEntry::make('socio')
                            ->label(__('Socio'))
                            ->state(fn (Dispensation $record): string => $record->member?->fullName() ?? '—'),
                        TextEntry::make('operator.name')->label(__('Operador'))->placeholder('—'),
                        TextEntry::make('location.name')->label(__('Sede'))->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make(__('Importe'))
                    ->schema([
                        TextEntry::make('total_cents')
                            ->label(__('Total'))
                            ->state(fn (Dispensation $record): string => Money::fromCents($record->total_cents->cents)->formatted()),
                        TextEntry::make('cash_cents')
                            ->label(__('Efectivo'))
                            ->state(fn (Dispensation $record): string => Money::fromCents($record->cash_cents->cents)->formatted()),
                        TextEntry::make('wallet_cents')
                            ->label(__('Monedero'))
                            ->state(fn (Dispensation $record): string => Money::fromCents($record->wallet_cents->cents)->formatted()),
                    ])
                    ->columns(3),

                Section::make(__('Reembolsos'))
                    ->schema([
                        TextEntry::make('refunded')
                            ->label(__('Reembolsado'))
                            ->state(fn (Dispensation $record): string => Money::fromCents($record->refundedAmountCents())->formatted()
                                .' · '.Weight::fromCentigrams($record->refundedGramsCg())->formatted()),
                        TextEntry::make('remaining')
                            ->label(__('Disponible para reembolsar'))
                            ->state(fn (Dispensation $record): string => Money::fromCents($record->remainingRefundableCents())->formatted()
                                .' · '.Weight::fromCentigrams($record->remainingRefundableGramsCg())->formatted()),
                        TextEntry::make('refund_lines')
                            ->hiddenLabel()
                            ->state(fn (Dispensation $record): array => $record->refunds()->latest()->get()
                                ->map(fn (Refund $r): string => sprintf(
                                    '%s · %s · %s · %s',
                                    Money::fromCents($r->amount_cents->cents)->formatted(),
                                    Weight::fromCentigrams($r->grams_cg->centigrams)->formatted(),
                                    $r->destination->label(),
                                    $r->method->label(),
                                ))
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder(__('Sin reembolsos'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Líneas'))
                    ->schema([
                        TextEntry::make('lines')
                            ->hiddenLabel()
                            ->state(fn (Dispensation $record): array => $record->lines()->get()
                                ->map(fn (DispensationLine $line): string => sprintf(
                                    '%s × %s — %s',
                                    (string) ($line->genetic_name_snapshot ?? '—'),
                                    Weight::fromCentigrams($line->grams_cg->centigrams)->formatted(),
                                    Money::fromCents($line->line_total_cents->cents)->formatted(),
                                ))
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Anulación'))
                    ->visible(fn (Dispensation $record): bool => $record->status === DispensationStatus::VOIDED)
                    ->schema([
                        TextEntry::make('void_reason')->label(__('Motivo de anulación'))->placeholder('—')->columnSpanFull(),
                        TextEntry::make('voidedBy.name')->label(__('Anulada por'))->placeholder('—'),
                        TextEntry::make('voided_at')->label(__('Anulada el'))->dateTime()->placeholder('—'),
                    ])
                    ->columns(3),
            ]);
    }
}
