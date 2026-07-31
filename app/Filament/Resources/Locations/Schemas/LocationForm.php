<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Support\Settings;
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
                    // A new sede pre-fills from the org-wide aforo_default (prompt 44 — previously a
                    // dead setting nothing read); editable per location, so it's only a starting point.
                    ->default(fn (): int => (int) Settings::get('aforo_default', 50))
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
                // (No aforo-enforcement control: aforo is a legal capacity limit and is enforced as a
                // fixed BLOCK via the enforcement matrix — prompt 34 removed the inert warn/block dropdown.)
                Toggle::make('settings.bar_enabled')
                    ->label(__('Bar activado')),

                Toggle::make('settings.signature_on_dispensation')
                    ->label(__('Firma en dispensación'))
                    ->default(true),

                Toggle::make('settings.restrict_pos_to_checked_in')
                    ->label(__('Restringir TPV a socios con check-in')),

                Toggle::make('settings.camera_scan_enabled')
                    ->label(__('Escaneo con cámara')),

                // The REAL ring-fence control (prompt 34) — read by AutoSettleDebt::isRingFenced.
                // Replaces the inert org-level wallet_ring_fence toggle that was removed.
                Toggle::make('settings.ring_fenced')
                    ->label(__('Monedero por sede (ring-fence)'))
                    ->helperText(__('Si se activa, el crédito de esta sede no salda automáticamente deudas en otras sedes.')),
            ]);
    }
}
