<?php

namespace App\Filament\Resources\DocumentTemplates\Tables;

use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->label(__('Tipo'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DocumentTemplateResource::typeOptions()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('version')->label(__('Versión'))->sortable(),
                IconColumn::make('active')->label(__('Activa'))->boolean(),
                TextColumn::make('updated_at')->label(__('Actualizada'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Tipo'))
                    ->options(DocumentTemplateResource::typeOptions()),
            ])
            ->recordActions([
                EditAction::make()->label(__('Nueva versión')),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin plantillas'))
            ->emptyStateDescription(__('Las plantillas definen el texto de los documentos que firma un socio: solicitud de alta, previsión de consumo, acta de sanción.'));
    }
}
