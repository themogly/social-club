<?php

namespace App\Filament\Resources\DataRequests\Schemas;

use App\Filament\Resources\DataRequests\DataRequestResource;
use App\Models\Member;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DataRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Solicitud'))
                    ->description(__('Registra la solicitud de derechos del interesado (RGPD). Al atenderla se anota la fecha de finalización y se deja constancia en el registro de auditoría.'))
                    ->schema([
                        Select::make('member_id')
                            ->label(__('Socio solicitante'))
                            ->relationship('member', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn (Member $record): string => trim($record->first_name.' '.$record->last_name).' · '.$record->member_no)
                            ->searchable(['first_name', 'last_name', 'member_no'])
                            ->preload()
                            ->required(),
                        Select::make('type')
                            ->label(__('Tipo de solicitud'))
                            ->options(fn (): array => DataRequestResource::typeOptions())
                            ->required(),
                        DateTimePicker::make('requested_at')
                            ->label(__('Recibida el'))
                            ->default(now())
                            ->required(),
                        Textarea::make('notes')
                            ->label(__('Notas'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
