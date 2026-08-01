<?php

namespace App\Filament\Resources\Convocatorias\RelationManagers;

use App\Enums\ConvocatoriaRecipientStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The FROZEN recipient roll — read-only evidence of exactly who was convened, as they were at issue. No
 * create/edit/delete: the roll is a snapshot, not a live list. Members without an email are shown with the
 * NO_EMAIL status so the club can see (and chase) who it could not reach.
 */
class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Socios convocados');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('member_no')
            ->columns([
                TextColumn::make('member_no')->label(__('Nº'))->searchable(),
                TextColumn::make('name')->label(__('Socio'))->searchable(),
                TextColumn::make('email')->label(__('Email'))->placeholder(__('Sin email'))->searchable(),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->formatStateUsing(fn (ConvocatoriaRecipientStatus $state): string => $state->label())
                    ->color(fn (ConvocatoriaRecipientStatus $state): string => $state === ConvocatoriaRecipientStatus::NOTIFIED ? 'success' : 'warning'),
                TextColumn::make('notified_at')->label(__('Notificado'))->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options(collect(ConvocatoriaRecipientStatus::cases())->mapWithKeys(fn (ConvocatoriaRecipientStatus $s): array => [$s->value => $s->label()])->all()),
            ])
            ->emptyStateHeading(__('Sin socios convocados'))
            ->emptyStateDescription(__('La lista de convocados se fija al emitir la convocatoria.'));
    }
}
