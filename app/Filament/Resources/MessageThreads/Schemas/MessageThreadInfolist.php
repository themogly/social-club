<?php

namespace App\Filament\Resources\MessageThreads\Schemas;

use App\Enums\DataRequestType;
use App\Enums\MessageThreadStatus;
use App\Models\MessageThread;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MessageThreadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Conversación'))
                ->schema([
                    TextEntry::make('member_name')
                        ->label(__('Socio'))
                        ->state(fn (MessageThread $record): string => $record->member?->fullName() ?? __('Socio dado de baja')),
                    TextEntry::make('member.member_no')->label(__('Nº de socio'))->placeholder('—'),
                    TextEntry::make('subject')->label(__('Asunto')),
                    TextEntry::make('status')
                        ->label(__('Estado'))
                        ->badge()
                        ->formatStateUsing(fn (MessageThreadStatus $state): string => $state->label())
                        ->color(fn (MessageThreadStatus $state): string => $state->getColor()),
                    TextEntry::make('location.name')->label(__('Sede'))->placeholder(__('General (organización)')),
                    TextEntry::make('last_message_at')->label(__('Último mensaje'))->dateTime('d/m/Y H:i'),
                    TextEntry::make('dataRequest.type')
                        ->label(__('Solicitud RGPD'))
                        ->placeholder('—')
                        ->formatStateUsing(fn ($state): string => $state instanceof DataRequestType ? $state->label() : '—'),
                ])
                ->columns(2),
        ]);
    }
}
