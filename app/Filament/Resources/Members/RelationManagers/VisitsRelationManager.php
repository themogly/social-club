<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Models\Scopes\LocationScope;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Prompt 51 — the socio's attendance history (check-ins). Read-only; org-wide across locations.
 */
class VisitsRelationManager extends RelationManager
{
    protected static string $relationship = 'checkIns';

    /** Prompt 81 — org-wide attendance is oversight data; gate on reports.view so STAFF (members.view) can't see it. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('reports.view');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Visitas');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope(LocationScope::class))
            ->defaultSort('checked_in_at', 'desc')
            ->columns([
                TextColumn::make('checked_in_at')->label(__('Entrada'))->dateTime()->sortable(),
                TextColumn::make('checked_out_at')->label(__('Salida'))->dateTime()->placeholder('—'),
                TextColumn::make('location.name')->label(__('Sede')),
                TextColumn::make('method')->label(__('Método'))->badge(),
            ])
            ->emptyStateHeading(__('Sin visitas'))
            ->emptyStateDescription(__('Este socio no tiene entradas registradas.'));
    }
}
