<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Models\Refund;
use App\Models\Scopes\LocationScope;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The member's refunds (prompt 71) — a refund against one of their dispensations is visible here, not only
 * in the audit log. Read-only history; a socio is org-wide, so every location's refunds are surfaced.
 */
class RefundsRelationManager extends RelationManager
{
    protected static string $relationship = 'refunds';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Reembolsos');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope(LocationScope::class))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Fecha'))->dateTime()->sortable(),
                TextColumn::make('amount_cents')
                    ->label(__('Importe'))
                    ->state(fn (Refund $record): int => $record->amount_cents->cents)
                    ->money('EUR', divideBy: 100),
                TextColumn::make('grams_cg')
                    ->label(__('Peso'))
                    ->state(fn (Refund $record): string => $record->grams_cg->formatted()),
                TextColumn::make('destination')->label(__('Destino'))->badge(),
                TextColumn::make('method')->label(__('Método'))->badge(),
                TextColumn::make('reason')->label(__('Motivo'))->limit(40)->tooltip(fn (Refund $record): string => $record->reason),
            ])
            ->emptyStateHeading(__('Sin reembolsos'))
            ->emptyStateDescription(__('Este socio no tiene reembolsos registrados.'));
    }
}
