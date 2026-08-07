<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Support\Settings;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
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
        'card_readers_enabled',
        'bar_attach_socio_enabled',
        'bar_ticket_reference_enabled',
    ];

    /**
     * Per-location INTEGER settings — same location-scoped-Setting-row mechanism as the toggles, but numeric
     * (prompt 120: counter_idle_lock_minutes). Filled/persisted by Create/EditLocation as SettingType::INT.
     *
     * @var list<string>
     */
    public const SETTING_INTEGERS = [
        'counter_idle_lock_minutes',
    ];

    /**
     * Per-location numeric-LIST settings — stored as a location-scoped JSON Setting row (prompt 133:
     * pos_weight_presets_g, the one-tap gram amounts on the dispensary POS). Persisted by Create/EditLocation.
     *
     * @var list<string>
     */
    public const SETTING_ARRAYS = [
        'pos_weight_presets_g',
    ];

    /**
     * Clean a TagsInput list of gram amounts (strings, comma-or-dot decimals) into a sorted, de-duplicated list
     * of positive numbers for storage — so a fat-fingered "3,5x" or a blank never reaches the POS.
     *
     * @param  array<int, mixed>  $values
     * @return list<int|float>
     */
    public static function normalizeNumberList(array $values): array
    {
        $numbers = [];
        foreach ($values as $value) {
            $normalised = str_replace(',', '.', trim((string) $value));
            if (is_numeric($normalised) && (float) $normalised > 0) {
                $float = (float) $normalised;
                $numbers[] = $float === floor($float) ? (int) $float : $float;
            }
        }
        $numbers = array_values(array_unique($numbers, SORT_NUMERIC));
        sort($numbers);

        return $numbers;
    }

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

                // TimePicker, not free-text (prompt 147): a `time` column rejects '' on MySQL (and SQLite
                // silently stores it), so three plain TextInputs 500'd the whole sede-create. 24-hour, no
                // seconds. The cut-off is REQUIRED with the schema default pre-filled so it can never be
                // emptied — half the domain (day boundary, gram cap, till, Z-report) depends on it.
                TimePicker::make('business_day_cutoff')
                    ->label(__('Corte del día operativo'))
                    ->native(false)
                    ->seconds(false)
                    ->displayFormat('H:i')
                    ->format('H:i')
                    ->required()
                    ->default('06:00')
                    ->helperText(__('Hora en la que se reinicia el día operativo, p. ej. 06:00.')),

                // Optional. Blank must dehydrate to NULL, not '' (a TimePicker does; a TextInput did not).
                TimePicker::make('opening_time')
                    ->label(__('Hora de apertura'))
                    ->native(false)
                    ->seconds(false)
                    ->displayFormat('H:i')
                    ->format('H:i'),

                TimePicker::make('closing_time')
                    ->label(__('Hora de cierre'))
                    ->native(false)
                    ->seconds(false)
                    ->displayFormat('H:i')
                    ->format('H:i'),

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

                // Prompt 193 — the bar's two optional cart panels. Off by default because most bar sales are
                // a coffee for cash; when off the panel is not rendered at all, so the cart opens on the
                // basket. Turning either off governs INPUT only — anything already recorded still shows on
                // receipts, in the ledger export and in reports.
                // Prompt 194 — the words on the member lookup, nothing else. A reader is a keyboard, so
                // this cannot be feature-detected; the club tells us. Off by default.
                Toggle::make('card_readers_enabled')
                    ->label(__('Lectores de tarjeta en esta sede'))
                    ->helperText(__('Cambia solo el texto del buscador de socios. Escanear funciona igualmente si está apagado.'))
                    ->default(false),

                Toggle::make('bar_attach_socio_enabled')
                    ->label(__('Barra: permitir atribuir un socio'))
                    ->helperText(__('Necesario para cobrar con monedero en la barra. Si está apagado, la barra cobra solo en efectivo.'))
                    ->default(false),

                Toggle::make('bar_ticket_reference_enabled')
                    ->label(__('Barra: referencia del ticket'))
                    ->helperText(__('Un campo libre para eventos o invitados. Apagado en el uso normal.'))
                    ->default(false),

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

                // Idle lock (prompt 120): minutes of no real operator input before a counter screen auto-locks
                // (signs the operator out, obscures member data). Per-location; 0 disables it.
                TextInput::make('counter_idle_lock_minutes')
                    ->label(__('Bloqueo por inactividad (min)'))
                    ->numeric()
                    ->minValue(0)
                    ->default(fn (): int => (int) Settings::get('counter_idle_lock_minutes', 5))
                    ->helperText(__('Minutos sin actividad antes de bloquear el mostrador. 0 lo desactiva.')),

                // One-tap weight presets on the dispensary POS (prompt 133). Grams; 3,5 g triggers the eighth
                // break. A sede sets its own list.
                TagsInput::make('pos_weight_presets_g')
                    ->label(__('Atajos de peso (g)'))
                    ->helperText(__('Gramos de un toque en el dispensario, p. ej. 1, 2, 3.5, 5.'))
                    ->placeholder(__('Añadir gramos')),

                // Terminal CRUD lives here now (prompt 102), not free-typed at the counter: the named tills of
                // this sede. With one till the name is cosmetic; with several it is what the operator picks.
                TagsInput::make('terminals')
                    ->label(__('Terminales (cajas)'))
                    ->helperText(__('Nombres de las cajas de esta sede, p. ej. «Caja 1», «Barra».'))
                    ->placeholder(__('Añadir terminal')),
            ]);
    }
}
