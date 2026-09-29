<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Actions\UnlockOperator;
use App\Enums\LocationKind;
use App\Enums\Role;
use App\Models\Location;
use App\Support\Settings;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

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
        'dispensary_calculator_enabled', // prompt 292 — the € calculator, off by default
        'restrict_pos_to_checked_in',
        'camera_scan_enabled',
        'ring_fenced',
        'multiple_tills_enabled',
        'card_readers_enabled',
        'bar_attach_socio_enabled',
        'bar_ticket_reference_enabled',
    ];

    /**
     * Per-location toggles only the OWNER may change (prompt 259) — persisted by Create/EditLocation only when
     * the saving user is the owner, so a manager editing their sede cannot grant themselves the power the toggle
     * governs. `managers_can_approve_debt`: may a manager here approve a member's tab.
     *
     * @var list<string>
     */
    public const OWNER_TOGGLES = [
        'managers_can_approve_debt',
    ];

    /** Is the current user the owner — the one who may change the {@see OWNER_TOGGLES}? */
    public static function actorIsOwner(): bool
    {
        return Auth::user()?->hasRole(Role::OWNER->value) ?? false;
    }

    /**
     * Per-location INTEGER settings — same location-scoped-Setting-row mechanism as the toggles, but numeric
     * (prompt 120: counter_idle_lock_minutes). Filled/persisted by Create/EditLocation as SettingType::INT.
     *
     * @var list<string>
     */
    public const SETTING_INTEGERS = [
        'counter_idle_lock_minutes',
        'counter_pin_max_attempts',
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
     * Per-location STRING settings — same location-scoped-Setting-row mechanism as the toggles, but a chosen
     * value from a fixed set (prompt 248: bar_layout_default, the standalone Bar's default article layout for a
     * fresh device at this sede). Persisted by Create/EditLocation as SettingType::STRING.
     *
     * @var list<string>
     */
    public const SETTING_STRINGS = [
        'bar_layout_default',
        'dispensary_batch_selection',
        'till_close_clock_out', // prompt 312
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

    /** Is the form describing an Almacén / cultivo (277)? The type is a live select on create, fixed on edit. */
    private static function isStore(Get $get): bool
    {
        $kind = $get('kind');

        return ($kind instanceof LocationKind ? $kind->value : $kind) === LocationKind::ALMACEN->value;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Prompt 273 — ~25 fields in one flat grid; now grouped so a manager can find the security settings.

                // Prompt 283 — the heading follows the type selector: a store is a location, not a sede.
                Section::make(fn (Get $get): string => self::isStore($get) ? __('Datos de la ubicación') : __('Datos de la sede'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Nombre'))
                            ->required()
                            ->maxLength(255),

                        // Prompt 277 — a counter premises or the grow / central store. Fixed once created: a location with
                        // members, tills and dispensations cannot become a store, nor the other way round.
                        Select::make('kind')
                            ->label(__('Tipo de ubicación'))
                            ->options(collect(LocationKind::cases())->mapWithKeys(fn (LocationKind $k): array => [$k->value => $k->label()])->all())
                            ->default(LocationKind::SEDE->value)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live()
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->helperText(__('El almacén / cultivo guarda stock y lo asigna a las sedes; no tiene mostrador, caja ni socios.')),

                        TextInput::make('address')
                            ->label(__('Dirección'))
                            ->maxLength(255),

                        // Prompt 271 — required and at least 1: an empty aforo silently switched the capacity check off (the
                        // matrix calls it "Siempre BLOQUEAR"), and −1 blocked every entry (or 500'd on MySQL's unsigned column).
                        TextInput::make('capacity')
                            ->label(__('Aforo'))
                            ->integer()
                            ->minValue(1)
                            ->required(fn (Get $get): bool => $get('kind') !== LocationKind::ALMACEN->value)
                            ->visible(fn (Get $get): bool => $get('kind') !== LocationKind::ALMACEN->value)
                            // A new sede pre-fills from the org-wide aforo_default (prompt 44 — previously a
                            // dead setting nothing read); editable per location, so it's only a starting point.
                            ->default(fn (): int => (int) Settings::get('aforo_default', 50))
                            ->helperText(__('Ocupación máxima simultánea permitida en la sede.')),

                        Select::make('timezone')
                            ->label(__('Zona horaria'))
                            // Prompt 273 — a stored zone outside the two (the demo's first sede is UTC) is still offered, so the
                            // edit form never shows an empty required select for a sede that is set up.
                            ->options(fn (?Location $record): array => ['Europe/Madrid' => 'Europe/Madrid', 'Atlantic/Canary' => 'Atlantic/Canary']
                                + (filled($record?->timezone) ? [(string) $record->timezone => (string) $record->timezone] : []))
                            ->default('Europe/Madrid')
                            // Prompt 271 — the column is NOT NULL: an emptied zone was a 500 on save.
                            ->required()
                            ->selectablePlaceholder(false),

                        // Counter theming (the per-location accent, prompt 03) — a store has no counter (283).
                        ColorPicker::make('accent')
                            ->label(__('Color de acento'))
                            ->visible(fn (Get $get): bool => ! self::isStore($get)),

                        Toggle::make('active')
                            ->label(__('Activo'))
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make(__('Horario'))
                    ->schema([
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
                            // Prompt 283 — kept for a store: it decides which business day a stock movement or transfer
                            // there belongs to. Only the explanation changes.
                            ->helperText(fn (Get $get): string => self::isStore($get)
                                ? __('Decide a qué día se asignan los movimientos de stock del almacén. Normalmente 06:00.')
                                : __('Hora en la que se reinicia el día operativo, p. ej. 06:00.')),

                        // Optional. Blank must dehydrate to NULL, not '' (a TimePicker does; a TextInput did not).
                        // Hidden for a store (283): nothing reads them, and it has no opening hours.
                        TimePicker::make('opening_time')
                            ->label(__('Hora de apertura'))
                            ->visible(fn (Get $get): bool => ! self::isStore($get))
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('H:i')
                            ->format('H:i'),

                        TimePicker::make('closing_time')
                            ->label(__('Hora de cierre'))
                            ->visible(fn (Get $get): bool => ! self::isStore($get))
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('H:i')
                            ->format('H:i'),
                    ])
                    ->columns(2),

                Section::make(__('Barra'))
                    ->visible(fn (Get $get): bool => $get('kind') !== LocationKind::ALMACEN->value) // no counter at the store (277)
                    ->schema([
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

                        // Prompt 248 — the standalone Bar's default article layout for a fresh terminal at this sede.
                        // A terminal remembers its own choice after that; this is only the starting point.
                        Select::make('bar_layout_default')
                            ->label(__('Barra: vista por defecto'))
                            ->helperText(__('Cómo empieza un terminal nuevo en esta sede. Cada terminal recuerda luego su elección.'))
                            ->options([
                                'list' => __('Lista'),
                                'grid' => __('Cuadrícula'),
                                'large' => __('Grande'),
                            ])
                            ->default('grid')
                            ->selectablePlaceholder(false),
                    ])
                    ->columns(2),

                Section::make(__('Dispensario'))
                    ->visible(fn (Get $get): bool => $get('kind') !== LocationKind::ALMACEN->value) // no counter at the store (277)
                    ->schema([
                        // Prompt 250 — how the dispensary picks the lote a dispensation draws from at this sede.
                        Select::make('dispensary_batch_selection')
                            ->label(__('Lotes en el dispensario'))
                            ->helperText(__('Automático toma del lote más antiguo y reparte cuando se agota; manual lo elige el operador (útil solo si guardáis un bote por lote).'))
                            ->options([
                                'automatic' => __('Automático (el sistema toma del lote más antiguo)'),
                                'manual' => __('Manual (el operador elige el lote)'),
                            ])
                            ->default('automatic')
                            ->selectablePlaceholder(false),

                        Toggle::make('signature_on_dispensation')
                            ->label(__('Firma en dispensación'))
                            ->helperText(__('El socio firma en la pantalla cada dispensación; la firma queda cifrada con el registro.')),

                        // Prompt 292 — the owner's decision: OFF by default. When off the counter shows no Gramos/€ toggle
                        // and the server treats every entry as grams.
                        Toggle::make('dispensary_calculator_enabled')
                            ->label(__('Calculadora € en el dispensario'))
                            ->helperText(__('Permite introducir un importe en euros y calcular los gramos. Desactivada por defecto.')),

                        // One-tap weight presets on the dispensary POS (prompt 133). Grams; 3,5 g triggers the eighth
                        // break. A sede sets its own list.
                        TagsInput::make('pos_weight_presets_g')
                            ->label(__('Atajos de peso (g)'))
                            ->helperText(__('Gramos de un toque en el dispensario, p. ej. 1, 2, 3.5, 5.'))
                            ->placeholder(__('Añadir gramos')),
                    ])
                    ->columns(2),

                Section::make(__('Monedero'))
                    ->visible(fn (Get $get): bool => $get('kind') !== LocationKind::ALMACEN->value) // no counter at the store (277)
                    ->schema([
                        Toggle::make('ring_fenced')
                            ->label(__('Monedero separado por sede'))
                            ->helperText(__('Si se activa, el crédito de esta sede no salda automáticamente deudas en otras sedes.')),

                        // Prompt 259 — who may approve a member's tab here. Only the owner can flip it (disabled for
                        // anyone else, and Create/EditLocation ignore it from a non-owner), so a manager cannot grant
                        // themselves the power. The owner can always approve; a manager only where this is on.
                        Toggle::make('managers_can_approve_debt')
                            ->label(__('Los gerentes pueden aprobar cuentas de socios'))
                            ->helperText(__('Solo la propiedad puede cambiarlo. La propiedad siempre puede aprobar una cuenta.'))
                            ->disabled(fn (): bool => ! self::actorIsOwner()),
                    ])
                    ->columns(2),

                Section::make(__('Cajas'))
                    ->visible(fn (Get $get): bool => $get('kind') !== LocationKind::ALMACEN->value) // no counter at the store (277)
                    ->schema([
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

                        // Prompt 312 — clocking the closer out is automatic by default (with a 2-minute *Deshacer*); a sede
                        // that prefers the old question keeps it.
                        Select::make('till_close_clock_out')
                            ->label(__('Fichar salida al cerrar la caja'))
                            ->options(['auto' => __('Automático'), 'ask' => __('Preguntar')])
                            ->selectablePlaceholder(false)
                            ->helperText(__('Automático: quien cierra la caja queda con la salida fichada, y puede deshacerlo durante 2 minutos. A las demás personas se les muestra y fichan con su propio PIN.')),
                    ])
                    ->columns(2),

                Section::make(__('Seguridad del mostrador'))
                    ->visible(fn (Get $get): bool => $get('kind') !== LocationKind::ALMACEN->value) // no counter at the store (277)
                    ->schema([
                        Toggle::make('restrict_pos_to_checked_in')
                            ->label(__('Solo dispensar a socios que han entrado'))
                            ->helperText(__('El dispensario solo acepta socios con la entrada registrada en recepción.')),

                        Toggle::make('camera_scan_enabled')
                            ->label(__('Escaneo con cámara'))
                            ->helperText(__('Permite leer la tarjeta QR del socio con la cámara de la tableta.')),

                        // Idle lock (prompt 120): minutes of no real operator input before a counter screen auto-locks
                        // (signs the operator out, obscures member data). Per-location; 0 disables it.
                        TextInput::make('counter_idle_lock_minutes')
                            ->label(__('Bloqueo por inactividad (min)'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(60) // prompt 273 — 100,000 was accepted, which disables the lock without saying so
                            ->default(fn (): int => (int) Settings::get('counter_idle_lock_minutes', 5))
                            ->helperText(__('Minutos sin actividad antes de bloquear el mostrador (0–60). 0 lo desactiva.')),

                        // PIN attempts before the pad locks out (prompt 235). The owner: *"adjust the number of
                        // attempts."* Bounded 3–10: below three a mistyped digit locks the club out of its own
                        // counter, above ten the escalating lockout is doing nothing. The escalation itself is not a
                        // knob — see UnlockOperator.
                        TextInput::make('counter_pin_max_attempts')
                            ->label(__('Intentos de PIN permitidos'))
                            ->numeric()
                            ->minValue(UnlockOperator::MIN_CONFIGURABLE_ATTEMPTS)
                            ->maxValue(UnlockOperator::MAX_CONFIGURABLE_ATTEMPTS)
                            ->default(fn (): int => (int) Settings::get('counter_pin_max_attempts', UnlockOperator::MAX_ATTEMPTS))
                            ->helperText(__('Fallos seguidos antes de bloquear el teclado del mostrador (3–10). Cada bloqueo seguido dura más; un responsable puede desbloquearlo desde Seguridad.')),
                    ])
                    ->columns(2),
            ]);
    }
}
