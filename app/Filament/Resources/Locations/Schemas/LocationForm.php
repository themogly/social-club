<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Support\Settings;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LocationForm
{
    /**
     * Per-location boolean settings — stored as location-scoped Setting rows (prompt 59), each read by
     * real enforcement code. Filled/persisted by CreateLocation/EditLocation, never a model column.
     *
     * @var list<string>
     */
    public const SETTING_TOGGLES = [
        'bar_enabled',
        'signature_on_dispensation',
        'restrict_pos_to_checked_in',
        'camera_scan_enabled',
        'ring_fenced',
        'multiple_tills_enabled',
    ];

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

                // Per-location settings (prompt 59): these five are stored as LOCATION-SCOPED Setting
                // rows — the one mechanism Settings::get reads — loaded + saved by the Edit/Create pages,
                // NOT bound to a model column. (No aforo control: aforo is a fixed BLOCK via the matrix.)
                Toggle::make('bar_enabled')
                    ->label(__('Bar activado'))
                    ->default(true), // a new sede runs a bar unless turned off

                Toggle::make('signature_on_dispensation')
                    ->label(__('Firma en dispensación')),

                Toggle::make('restrict_pos_to_checked_in')
                    ->label(__('Restringir TPV a socios con check-in')),

                Toggle::make('camera_scan_enabled')
                    ->label(__('Escaneo con cámara')),

                Toggle::make('ring_fenced')
                    ->label(__('Monedero por sede (ring-fence)'))
                    ->helperText(__('Si se activa, el crédito de esta sede no salda automáticamente deudas en otras sedes.')),

                // One drawer is the default (prompt 102): OFF, and opening a caja asks only for the float. ON
                // lets the sede run several terminals at once and the operator picks which to open.
                Toggle::make('multiple_tills_enabled')
                    ->label(__('Varias cajas por sede'))
                    ->helperText(__('Actívalo solo si esta sede abre más de una caja a la vez.')),

                // Terminal CRUD lives here now (prompt 102), not free-typed at the counter: the named tills of
                // this sede. With one till the name is cosmetic; with several it is what the operator picks.
                TagsInput::make('terminals')
                    ->label(__('Terminales (cajas)'))
                    ->helperText(__('Nombres de las cajas de esta sede, p. ej. «Caja 1», «Barra».'))
                    ->placeholder(__('Añadir terminal')),
            ]);
    }
}
