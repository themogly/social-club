<?php

namespace App\Filament\Resources\MemberApplications\Schemas;

use App\Enums\ApplicationStatus;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MemberApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Solicitud'))
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('Estado'))
                            ->badge()
                            ->color(fn (ApplicationStatus $state): string => match ($state) {
                                ApplicationStatus::APPROVED => 'success',
                                ApplicationStatus::PENDING => 'warning',
                                ApplicationStatus::REJECTED => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('location.name')
                            ->label(__('Sede'))
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label(__('Recibida'))
                            ->dateTime(),
                        TextEntry::make('reviewer.name')
                            ->label(__('Revisada por'))
                            ->placeholder('—'),
                        TextEntry::make('reviewed_at')
                            ->label(__('Fecha de revisión'))
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('reject_reason')
                            ->label(__('Motivo del rechazo'))
                            ->placeholder('—'),
                    ])
                    ->columns(2),

                Section::make(__('Datos de la solicitud'))
                    ->schema([
                        KeyValueEntry::make('payload')
                            ->label(__('Formulario'))
                            ->keyLabel(__('Campo'))
                            ->valueLabel(__('Valor')),
                    ]),
            ]);
    }
}
