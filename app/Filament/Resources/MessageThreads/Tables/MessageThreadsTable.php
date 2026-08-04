<?php

namespace App\Filament\Resources\MessageThreads\Tables;

use App\Enums\MessageThreadStatus;
use App\Filament\Resources\MessageThreads\MessageThreadResource;
use App\Models\MessageThread;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MessageThreadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->columns([
                TextColumn::make('member.member_no')->label(__('Nº'))->searchable(),
                TextColumn::make('member_name')
                    ->label(__('Socio'))
                    ->state(fn (MessageThread $record): string => $record->member?->fullName() ?? __('Socio dado de baja')),
                TextColumn::make('subject')->label(__('Asunto'))->limit(45)->searchable(),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->formatStateUsing(fn (MessageThreadStatus $state): string => $state->label())
                    ->color(fn (MessageThreadStatus $state): string => $state->getColor()),
                IconColumn::make('data_request_id')
                    ->label(__('RGPD'))
                    ->state(fn (MessageThread $record): bool => $record->data_request_id !== null)
                    ->boolean()
                    ->trueIcon('heroicon-o-inbox-arrow-down')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn (MessageThread $record): ?string => $record->data_request_id !== null ? __('Convertida en solicitud RGPD') : null),
                TextColumn::make('last_message_at')->label(__('Último mensaje'))->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options(collect(MessageThreadStatus::cases())->mapWithKeys(fn (MessageThreadStatus $s): array => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                ...MessageThreadResource::recordActions(),
            ])
            ->emptyStateHeading(__('Sin mensajes'))
            ->emptyStateDescription(__('Cuando un socio escriba al club desde su app, su conversación aparecerá aquí.'));
    }
}
