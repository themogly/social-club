<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Models\Location;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label(__('Título'))
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('description')
                ->label(__('Descripción'))
                ->rows(5)
                ->columnSpanFull(),

            Select::make('location_id')
                ->label(__('Sede'))
                ->relationship('location', 'name', modifyQueryUsing: fn (Builder $query): Builder => Location::limitToAssignable($query))
                ->searchable()
                ->preload()
                ->placeholder(__('Todas las sedes')),

            DateTimePicker::make('starts_at')
                ->label(__('Comienza el'))
                ->seconds(false)
                ->required(),

            TextInput::make('capacity')
                ->label(__('Aforo'))
                ->numeric()
                ->minValue(1)
                ->helperText(__('Vacío = sin límite de plazas.')),
        ]);
    }
}
