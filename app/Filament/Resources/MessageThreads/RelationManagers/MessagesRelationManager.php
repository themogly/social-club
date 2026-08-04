<?php

namespace App\Filament\Resources\MessageThreads\RelationManagers;

use App\Enums\MessageAuthor;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The messages in a thread — read-only. Replies are written through the ReplyToThread action (the single
 * writer, which also notifies the member), never hand-typed here.
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Mensajes');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('author')
                    ->label(__('De'))
                    ->badge()
                    ->formatStateUsing(fn (MessageAuthor $state): string => $state->label())
                    ->color(fn (MessageAuthor $state): string => $state === MessageAuthor::MEMBER ? 'info' : 'success'),
                TextColumn::make('user.name')->label(__('Personal'))->placeholder('—'),
                TextColumn::make('body')->label(__('Mensaje'))->wrap(),
                TextColumn::make('created_at')->label(__('Fecha'))->dateTime('d/m/Y H:i'),
            ])
            ->emptyStateHeading(__('Sin mensajes'));
    }
}
