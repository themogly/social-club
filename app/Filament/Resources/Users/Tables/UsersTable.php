<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
                // Derived (prompt 93): a user with no role can't enter the panel, no PIN can't identify at
                // the counter, no sede is scoped to nothing. Never stored; each gap is named.
                TextColumn::make('setup_gap')
                    ->label(__('Configuración'))
                    ->badge()
                    ->state(fn (User $record): string => self::setupLabel($record))
                    ->color(fn (User $record): string => $record->setupIncompleteReasons() === [] ? 'success' : 'warning'),
                TextColumn::make('email')->label(__('Correo'))->searchable()->toggleable(),
                TextColumn::make('roles.name')->label(__('Roles'))->badge(),
                TextColumn::make('locations.name')->label(__('Sedes'))->badge()->toggleable(),
                IconColumn::make('active')->label(__('Activo'))->boolean(),
                IconColumn::make('mfa_confirmed_at')->label('MFA')->boolean()->toggleable(),
                TextColumn::make('created_at')->label(__('Alta'))->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
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
                    RestoreBulkAction::make(),
                ]),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin usuarios'))
            ->emptyStateDescription(__('Los usuarios son el personal del club, no los socios. Cada uno necesita una sede asignada y un PIN para trabajar en el mostrador.'));
    }

    /** Name each setup gap so the badge tells the truth (prompt 93). */
    private static function setupLabel(User $user): string
    {
        $labels = [
            'no_role' => __('Falta rol'),
            'no_location' => __('Falta sede'),
            'no_pin' => __('Falta PIN'),
        ];

        $reasons = $user->setupIncompleteReasons();
        if ($reasons === []) {
            return __('Completa');
        }

        return implode(' · ', array_map(fn (string $r): string => $labels[$r] ?? $r, $reasons));
    }
}
