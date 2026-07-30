<?php

namespace App\Filament\Resources\Announcements\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label(__('Título'))
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('body')
                ->label(__('Cuerpo'))
                ->rows(6)
                ->columnSpanFull(),

            Select::make('location_id')
                ->label(__('Sede'))
                ->relationship('location', 'name')
                ->searchable()
                ->preload()
                ->placeholder(__('Todas las sedes'))
                ->helperText(__('Vacío = visible para todos los socios de la organización.')),

            DateTimePicker::make('published_at')
                ->label(__('Publicar el'))
                ->seconds(false)
                ->helperText(__('Vacío = borrador (no visible para los socios). Al publicar se notifica a los socios suscritos.')),

            DateTimePicker::make('expires_at')
                ->label(__('Caduca el'))
                ->seconds(false)
                ->helperText(__('Opcional. Tras esta fecha el aviso deja de mostrarse.')),
        ]);
    }
}
