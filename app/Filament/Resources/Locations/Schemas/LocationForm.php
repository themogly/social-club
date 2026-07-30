<?php

namespace App\Filament\Resources\Locations\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Nombre'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('address')
                    ->label(__('Dirección'))
                    ->maxLength(255),

                TextInput::make('capacity')
                    ->label(__('Aforo'))
                    ->numeric()
                    ->helperText(__('Ocupación máxima simultánea permitida en la sede.')),

                Select::make('timezone')
                    ->label(__('Zona horaria'))
                    ->options([
                        'Europe/Madrid' => 'Europe/Madrid',
                        'Atlantic/Canary' => 'Atlantic/Canary',
                    ])
                    ->default('Europe/Madrid'),

                TextInput::make('business_day_cutoff')
                    ->label(__('Corte del día operativo'))
                    ->helperText(__('Hora en la que se reinicia el día operativo, p. ej. 06:00.')),

                TextInput::make('opening_time')
                    ->label(__('Hora de apertura')),

                TextInput::make('closing_time')
                    ->label(__('Hora de cierre')),

                ColorPicker::make('accent')
                    ->label(__('Color de acento')),

                Toggle::make('active')
                    ->label(__('Activo'))
                    ->default(true),

                // The following bind into the model's `settings` array cast via dot paths.
                Select::make('settings.aforo_enforcement')
                    ->label(__('Control de aforo'))
                    ->options([
                        'block' => __('Bloquear'),
                        'warn' => __('Avisar'),
                    ]),

                Toggle::make('settings.bar_enabled')
                    ->label(__('Bar activado')),

                Toggle::make('settings.signature_on_dispensation')
                    ->label(__('Firma en dispensación'))
                    ->default(true),

                Toggle::make('settings.restrict_pos_to_checked_in')
                    ->label(__('Restringir TPV a socios con check-in')),

                Toggle::make('settings.camera_scan_enabled')
                    ->label(__('Escaneo con cámara')),
            ]);
    }
}
