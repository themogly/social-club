<?php

namespace App\Filament\Resources\Events\Tables;

use App\Enums\EventRsvpStatus;
use App\Models\Event;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Título'))->searchable()->wrap(),
                TextColumn::make('location.name')->label(__('Sede'))->placeholder(__('Todas'))->badge(),
                TextColumn::make('starts_at')->label(__('Comienza'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('attendees')
                    ->label(__('Confirmados'))
                    ->state(fn (Event $e): int => $e->rsvps()->where('status', EventRsvpStatus::GOING->value)->count())
                    ->badge(),
                TextColumn::make('capacity')->label(__('Aforo'))->placeholder(__('Sin límite')),
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
            ]);
    }
}
