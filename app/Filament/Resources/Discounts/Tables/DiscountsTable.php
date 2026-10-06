<?php

namespace App\Filament\Resources\Discounts\Tables;

use App\Enums\DiscountMode;
use App\Models\Discount;
use App\Support\Percent;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DiscountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Prompt 354 (Ben: "the latest first on all the entries") — newest first; every header still sorts.
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
                TextColumn::make('kind')->label(__('Tipo'))->badge(),
                TextColumn::make('mode')->label(__('Modo'))->badge(),
                TextColumn::make('value')->label(__('Valor'))
                    ->state(fn (Discount $d): string => $d->mode === DiscountMode::PERCENT
                        ? Percent::formatted((int) $d->value_bp / 100)
                        : ($d->value_cents?->formatted() ?? '—')),
                TextColumn::make('applies_to')->label(__('Aplica a'))->badge(),
                IconColumn::make('active')->label(__('Activo'))->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Prompt 354 — one ⋮ per row, never inline buttons: below 1280 px the actions cell is pinned over the row.
                ActionGroup::make([
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin descuentos'))
            ->emptyStateDescription(__('Un descuento se aplica en el mostrador sobre el precio por gramo. Crea uno si la asociación acuerda una bonificación.'));
    }
}
