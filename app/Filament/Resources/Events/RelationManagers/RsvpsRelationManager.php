<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Enums\EventRsvpStatus;
use App\Models\Event;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The event's RSVP list — read-only oversight: socios respond from the PWA, not here.
 * The title carries the confirmed-attendee count.
 */
class RsvpsRelationManager extends RelationManager
{
    protected static string $relationship = 'rsvps';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        $going = $ownerRecord instanceof Event
            ? $ownerRecord->rsvps()->where('status', EventRsvpStatus::GOING->value)->count()
            : 0;

        return __('Asistentes (:n confirmados)', ['n' => $going]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('responded_at', 'desc')
            ->columns([
                TextColumn::make('member.member_no')->label(__('Nº socio/a'))->searchable(),
                TextColumn::make('member.first_name')
                    ->label(__('Socio/a'))
                    ->state(fn ($record): string => trim(((string) $record->member?->first_name).' '.((string) $record->member?->last_name)))
                    ->searchable(),
                TextColumn::make('status')->label(__('Respuesta'))->badge(),
                TextColumn::make('responded_at')->label(__('Respondido'))->dateTime('d/m/Y H:i')->sortable(),
            ]);
    }
}
