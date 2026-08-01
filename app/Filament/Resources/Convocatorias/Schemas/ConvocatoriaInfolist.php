<?php

namespace App\Filament\Resources\Convocatorias\Schemas;

use App\Enums\ConvocatoriaType;
use App\Models\Convocatoria;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ConvocatoriaInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Asamblea'))
                ->schema([
                    TextEntry::make('type')
                        ->label(__('Tipo'))
                        ->badge()
                        ->formatStateUsing(fn (ConvocatoriaType $state): string => $state->label()),
                    TextEntry::make('title')->label(__('Título')),
                    TextEntry::make('held_at')->label(__('Primera convocatoria'))->dateTime('d/m/Y H:i'),
                    TextEntry::make('second_call_at')->label(__('Segunda convocatoria'))->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('venue')->label(__('Lugar'))->placeholder('—'),
                    TextEntry::make('location.name')->label(__('Sede'))->placeholder(__('General (organización)')),
                    TextEntry::make('issued_at')
                        ->label(__('Estado'))
                        ->badge()
                        ->state(fn (Convocatoria $record): string => $record->isIssued() ? __('Emitida') : __('Borrador'))
                        ->color(fn (Convocatoria $record): string => $record->isIssued() ? 'success' : 'warning'),
                    TextEntry::make('issuedBy.name')->label(__('Emitida por'))->placeholder('—'),
                ])
                ->columns(2),

            Section::make(__('Convocatoria'))
                ->schema([
                    TextEntry::make('roll_count')->label(__('Socios convocados'))->placeholder('—'),
                    TextEntry::make('quorum_required')->label(__('Quórum requerido (primera convocatoria)'))->placeholder('—'),
                    TextEntry::make('notice_days')->label(__('Plazo de convocatoria (días)'))->placeholder('—'),
                    TextEntry::make('notified')
                        ->label(__('Notificados por email'))
                        ->state(fn (Convocatoria $record): string => $record->isIssued()
                            ? $record->recipients()->whereNotNull('notified_at')->count().' / '.$record->roll_count
                            : '—'),
                    TextEntry::make('no_email')
                        ->label(__('Sin email — no notificados'))
                        ->state(fn (Convocatoria $record): int => $record->recipients()->whereNull('notified_at')->count())
                        ->color(fn (Convocatoria $record): string => $record->recipients()->whereNull('notified_at')->count() > 0 ? 'warning' : 'gray'),
                ])
                ->columns(2)
                ->visible(fn (Convocatoria $record): bool => $record->isIssued()),

            Section::make(__('Orden del día'))
                ->schema([
                    TextEntry::make('agenda')
                        ->hiddenLabel()
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder(__('Sin puntos')),
                ]),

            Section::make(__('Texto de la convocatoria'))
                ->schema([
                    TextEntry::make('body')->hiddenLabel()->prose()->placeholder(__('Sin texto')),
                ]),
        ]);
    }
}
