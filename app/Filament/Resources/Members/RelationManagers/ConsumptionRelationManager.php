<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Enums\DispensationStatus;
use App\Models\Dispensation;
use App\Models\Scopes\LocationScope;
use App\Support\Weight;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Prompt 51 — the socio's cannabis consumption history (dispensations). Read-only; a socio is
 * org-wide so every location's dispensations show. Lines are eager-loaded so the grams column
 * does not N+1 across the page.
 */
class ConsumptionRelationManager extends RelationManager
{
    protected static string $relationship = 'dispensations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Consumo');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope(LocationScope::class)->with('lines'))
            ->defaultSort('dispensed_at', 'desc')
            ->columns([
                TextColumn::make('dispensed_at')->label(__('Fecha'))->dateTime()->sortable(),
                TextColumn::make('grams')
                    ->label(__('Cantidad'))
                    ->state(fn (Dispensation $record): string => Weight::fromCentigrams(
                        (int) $record->lines->sum(fn ($line): int => $line->grams_cg->centigrams)
                    )->formatted()),
                TextColumn::make('total_cents')
                    ->label(__('Total'))
                    ->state(fn (Dispensation $record): int => $record->total_cents->cents)
                    ->money('EUR', divideBy: 100),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (DispensationStatus $state): string => $state === DispensationStatus::COMPLETED ? 'success' : 'danger'),
            ])
            ->emptyStateHeading(__('Sin dispensaciones'))
            ->emptyStateDescription(__('Este socio no tiene dispensaciones registradas.'));
    }
}
