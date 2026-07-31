<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Enums\SanctionType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Prompt 51 — the socio's disciplinary history (warnings / suspensions / expulsions). Read-only;
 * sanctions are created through the domain action, never edited here.
 */
class SanctionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sanctions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Sanciones');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('from_date', 'desc')
            ->columns([
                TextColumn::make('from_date')->label(__('Desde'))->date()->sortable(),
                TextColumn::make('type')
                    ->label(__('Tipo'))
                    ->badge()
                    ->color(fn (SanctionType $state): string => match ($state) {
                        SanctionType::WARNING => 'warning',
                        SanctionType::SUSPENSION, SanctionType::EXPULSION => 'danger',
                    }),
                TextColumn::make('reason')->label(__('Motivo'))->wrap(),
                TextColumn::make('until_date')->label(__('Hasta'))->date()->placeholder('—'),
            ])
            ->emptyStateHeading(__('Sin sanciones'))
            ->emptyStateDescription(__('Este socio no tiene sanciones registradas.'));
    }
}
