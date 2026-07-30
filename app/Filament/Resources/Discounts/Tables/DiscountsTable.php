<?php

namespace App\Filament\Resources\Discounts\Tables;

use App\Enums\DiscountMode;
use App\Models\Discount;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
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
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
                TextColumn::make('kind')->label(__('Tipo'))->badge(),
                TextColumn::make('mode')->label(__('Modo'))->badge(),
                TextColumn::make('value')->label(__('Valor'))
                    ->state(fn (Discount $d): string => $d->mode === DiscountMode::PERCENT
                        ? number_format((int) $d->value_bp / 100, 2).'%'
                        : ($d->value_cents?->formatted() ?? '—')),
                TextColumn::make('applies_to')->label(__('Aplica a'))->badge(),
                IconColumn::make('active')->label(__('Activo'))->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
