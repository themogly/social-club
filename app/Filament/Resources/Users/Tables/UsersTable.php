<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
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
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
