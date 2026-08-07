<?php

namespace App\Filament\Resources\MemberDocuments\Tables;

use App\Enums\MemberDocumentType;
use App\Filament\Resources\MemberDocuments\MemberDocumentResource;
use App\Models\MemberDocument;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MemberDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('member.member_no')->label(__('Nº socio'))->searchable()->sortable(),
                TextColumn::make('socio')
                    ->label(__('Socio'))
                    ->state(fn (MemberDocument $record): string => $record->member?->fullName() ?? '—'),
                TextColumn::make('type')
                    ->label(__('Tipo'))
                    ->badge()
                    ->formatStateUsing(fn (MemberDocumentType $state): string => MemberDocumentResource::typeLabel($state)),
                TextColumn::make('version')->label(__('Versión'))->sortable(),
                TextColumn::make('created_at')->label(__('Generado'))->dateTime()->sortable(),
                TextColumn::make('uploadedBy.name')->label(__('Por'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Tipo'))
                    ->options(collect(MemberDocumentType::cases())
                        ->mapWithKeys(fn (MemberDocumentType $case): array => [$case->value => MemberDocumentResource::typeLabel($case)])
                        ->all()),
            ])
            ->recordActions([
                MemberDocumentResource::viewDocumentAction(),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin documentos'))
            ->emptyStateDescription(__('Los documentos se generan desde la ficha del socio (solicitud de alta, previsión de consumo). Aquí solo se consultan.'));
    }
}
