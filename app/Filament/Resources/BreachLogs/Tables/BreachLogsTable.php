<?php

namespace App\Filament\Resources\BreachLogs\Tables;

use App\Enums\BreachStatus;
use App\Filament\Resources\BreachLogs\BreachLogResource;
use App\Models\BreachLog;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BreachLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('discovered_at', 'desc')
            ->columns([
                TextColumn::make('discovered_at')->label(__('Descubierta'))->dateTime()->sortable(),
                TextColumn::make('scope')->label(__('Alcance'))->wrap()->placeholder('—')->searchable(),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->formatStateUsing(fn (BreachStatus $state): string => BreachLogResource::statusLabel($state))
                    ->color(fn (BreachStatus $state): string => match ($state) {
                        BreachStatus::OPEN => 'warning',
                        BreachStatus::NOTIFIED => 'success',
                        BreachStatus::CLOSED => 'gray',
                    }),
                TextColumn::make('aepd_notified_at')->label(__('AEPD'))->dateTime()->placeholder(__('Pendiente'))->sortable(),
                TextColumn::make('deadline')
                    ->label(__('Plazo 72 h'))
                    ->badge()
                    ->state(fn (BreachLog $record): string => BreachLogResource::deadlineStatus($record->discovered_at, $record->aepd_notified_at)['label'])
                    ->color(fn (BreachLog $record): string => BreachLogResource::deadlineStatus($record->discovered_at, $record->aepd_notified_at)['tone']),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options(fn (): array => BreachLogResource::statusOptions()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin brechas registradas'))
            ->emptyStateDescription(__('Lo normal es que esta pantalla esté vacía. Si algún día hay un incidente con datos personales, se registra aquí para poder notificarlo a la AEPD dentro de las 72 horas.'));
    }
}
