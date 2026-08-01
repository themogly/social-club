<?php

namespace App\Filament\Resources\Convocatorias\Tables;

use App\Enums\ConvocatoriaType;
use App\Filament\Resources\Convocatorias\ConvocatoriaResource;
use App\Models\Convocatoria;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ConvocatoriasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('held_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Título'))->searchable()->limit(40),
                TextColumn::make('type')
                    ->label(__('Tipo'))
                    ->badge()
                    ->formatStateUsing(fn (ConvocatoriaType $state): string => $state->label()),
                TextColumn::make('held_at')->label(__('Celebración'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('roll_count')
                    ->label(__('Convocados'))
                    ->placeholder('—'),
                TextColumn::make('issued_at')
                    ->label(__('Estado'))
                    ->badge()
                    ->state(fn (Convocatoria $record): string => $record->isIssued() ? __('Emitida') : __('Borrador'))
                    ->color(fn (Convocatoria $record): string => $record->isIssued() ? 'success' : 'warning'),
            ])
            ->filters([
                TernaryFilter::make('issued_at')
                    ->label(__('Emitida'))
                    ->nullable()
                    ->placeholder(__('Todas'))
                    ->trueLabel(__('Emitidas'))
                    ->falseLabel(__('Borradores')),
            ])
            ->recordActions([
                ViewAction::make(),
                ConvocatoriaResource::issueAction(),
                EditAction::make(),
                ConvocatoriaResource::pdfAction(),
                DeleteAction::make(),
            ]);
    }
}
