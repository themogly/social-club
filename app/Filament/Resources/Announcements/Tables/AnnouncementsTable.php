<?php

namespace App\Filament\Resources\Announcements\Tables;

use App\Models\Announcement;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Título'))->searchable()->wrap(),
                TextColumn::make('location.name')->label(__('Sede'))->placeholder(__('Todas'))->badge(),
                IconColumn::make('published')
                    ->label(__('Publicado'))
                    ->state(fn (Announcement $r): bool => $r->published_at !== null && ! $r->published_at->isFuture()
                        && ($r->expires_at === null || ! $r->expires_at->isPast()))
                    ->boolean(),
                TextColumn::make('published_at')->label(__('Publicar el'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('expires_at')->label(__('Caduca'))->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('author.name')->label(__('Autor/a'))->placeholder('—')->toggleable(),
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
