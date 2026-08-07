<?php

namespace App\Filament\Resources\Minutes\Tables;

use App\Enums\MinuteBook;
use App\Filament\Resources\Minutes\MinuteResource;
use App\Models\Minute;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class MinutesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('held_on', 'desc')
            ->columns([
                TextColumn::make('book')
                    ->label(__('Libro'))
                    ->badge()
                    ->formatStateUsing(fn (MinuteBook $state): string => match ($state) {
                        MinuteBook::ASSEMBLY => __('Asamblea'),
                        MinuteBook::BOARD => __('Junta directiva'),
                    })
                    ->sortable(),
                TextColumn::make('number')->label(__('Nº'))->sortable(),
                TextColumn::make('type')
                    ->label(__('Tipo'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : (MinuteResource::typeOptions()[$state] ?? $state)),
                TextColumn::make('held_on')->label(__('Celebración'))->date()->sortable(),
                TextColumn::make('location.name')->label(__('Sede'))->placeholder(__('General (organización)')),
                TextColumn::make('quorum')
                    ->label(__('Quórum'))
                    ->state(fn (Minute $record): string => $record->quorum_present.' / '.$record->quorum_required),
                TextColumn::make('signed_at')
                    ->label(__('Estado'))
                    ->badge()
                    ->state(fn (Minute $record): string => $record->signed_at === null ? __('Borrador') : __('Firmada'))
                    ->color(fn (Minute $record): string => $record->signed_at === null ? 'warning' : 'success'),
            ])
            ->filters([
                SelectFilter::make('book')
                    ->label(__('Libro'))
                    ->options([
                        MinuteBook::ASSEMBLY->value => __('Asamblea'),
                        MinuteBook::BOARD->value => __('Junta directiva'),
                    ]),
                TernaryFilter::make('signed_at')
                    ->label(__('Firmada'))
                    ->nullable()
                    ->placeholder(__('Todas'))
                    ->trueLabel(__('Firmadas'))
                    ->falseLabel(__('Borradores')),
            ])
            ->recordActions([
                ViewAction::make(),
                MinuteResource::signAction(),
                EditAction::make(),
                MinuteResource::correctAction(),
                MinuteResource::pdfAction(),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin actas'))
            ->emptyStateDescription(__('Un acta se redacta a partir de lo registrado en la asamblea, no a mano. Celebra una asamblea desde una convocatoria emitida.'));
    }
}
