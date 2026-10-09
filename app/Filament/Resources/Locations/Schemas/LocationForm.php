<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Actions\UnlockOperator;
use App\Enums\CashPot;
use App\Enums\LocationKind;
use App\Enums\Role;
use App\Models\Location;
use App\Support\CashBoxes;
use App\Support\DispensarySort;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
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
        'bar_receipt_enabled', // prompt 317
        'signature_on_dispensation',
        'dispensary_calculator_enabled', // prompt 292 — the € calculator, off by default
        'charge_rounding_enabled', // prompt 355 — the charged weight rounded to the half gram, on by default
        'restrict_pos_to_checked_in',
        'camera_scan_enabled',
        'ring_fenced',
        'multiple_tills_enabled',
        'card_readers_enabled',
        'bar_attach_socio_enabled',
        'bar_ticket_reference_enabled',
        'counter_training_enabled', // prompt 324 — on by default
        'applications_chime_enabled', // prompt 330 — off by default
        'reception_enabled', // prompt 337 — on by default
        'block_self_dispensation', // prompt 347 — off by default
        'require_photo_to_dispense', // prompt 348 — on by default
        'confirm_photo_on_scan', // prompt 348 — on by default
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
        // Prompt 373 — counting a box every night is part of where the cash goes (owner-only, like the boxes themselves).
        'count_bar_nightly',
        'count_fees_nightly',
        'count_edibles_nightly',
        'count_shop_nightly', // prompt 378
    ];

    /**
     * Prompt 373 — per-location STRING settings only the OWNER may change: where each kind of money goes ('till' | 'own').
     * Where the money goes changes how a sede's cash is reconciled, so a manager sees the section read-only.
     *
     * @var list<string>
     */
    public const OWNER_STRINGS = [
        'cash_box_edibles',
        'cash_box_bar',
        'cash_box_shop', // prompt 378 — 'with_bar' | 'till' | 'own'
        'cash_box_fees',
    ];

    /** Prompt 378 — the values an owner-only string may take (the shop row has a third choice). */
    public static function allowedOwnerString(string $key, mixed $value): bool
    {
        $pot = array_search($key, CashBoxes::SETTINGS, true);

        return $pot !== false && in_array($value, CashBoxes::choicesFor((string) $pot), true);
    }

    /**
     * Prompt 367 — per-location INTEGER settings only the OWNER may change: the losses alert threshold, so a manager cannot
     * quiet the alert that watches their own sede.
     *
     * @var list<string>
     */
    public const OWNER_INTEGERS = [
        'losses_alert_threshold_pct',
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
        'after_recording', // prompt 347
        'dispensary_sort', // prompt 351
    ];

    /**
     * Prompt 373 — one kind of money: «En la caja» / «Bote propio» (two big buttons, the same as *Ajuste*), WHEN its own box is
     * counted beneath it (379: «Cada noche» / «Solo al vaciarlo», replacing the switch), and — switching a box that still holds
     * money into the till — the warning that opening the next till will merge it.
     *
     * @return list<Component>
     */
    private static function cashBoxRow(string $pot, string $label): array
    {
        $key = CashBoxes::SETTINGS[$pot];
        $count = CashBoxes::COUNT_NIGHTLY[$pot];

        return [
            // Prompt 379 (Ben: "Toggle is confusing") — one column: the row's choices, and — only when it has its own box — WHEN
            // it is counted, directly beneath and aligned with them, in the same two-button style. No switch in a far column.
            // The same `count_*_nightly` setting underneath (Cada noche = true), so nothing about the close changes.
            ToggleButtons::make($key)
                ->label($label)
                // Prompt 378 — the shop alone may also go «Con la barra» (the default: wherever the bar's money goes).
                ->options(array_intersect_key(['with_bar' => __('Con la barra'), 'till' => __('En la caja'), 'own' => __('Bote propio')], array_flip(CashBoxes::choicesFor($pot))))
                ->default(CashBoxes::defaultFor($pot))
                ->inline()
                ->inlineLabel()
                ->live()
                ->disabled(fn (): bool => ! self::actorIsOwner()),
            ToggleButtons::make($count)
                ->label(__('¿Cuándo se cuenta?'))
                // '1' / '0' (what the buttons post), read from and saved back to the boolean setting.
                ->options(['1' => __('Cada noche'), '0' => __('Solo al vaciarlo')])
                ->formatStateUsing(fn (mixed $state): string => (bool) $state ? '1' : '0')
                ->dehydrateStateUsing(fn (mixed $state): bool => (string) $state === '1')
                ->inline()
                ->inlineLabel()
                ->live()
                ->extraAttributes(['data-cash-count-choice' => $pot])
                // Prompt 380 — what it is: where the close STARTS (366: the close never blocks), and that a skip is noted.
                ->helperText(fn (Get $get): string => (string) $get($count) === '1'
                    ? __('Al cerrar viene marcado «Contar ahora». Si un día no se cuenta, queda anotado.')
                    : __('Al cerrar viene marcado «No se cuenta hoy»; lo que tiene pasa al día siguiente.'))
                ->visible(fn (Get $get): bool => $get($key) === 'own')
                ->disabled(fn (): bool => ! self::actorIsOwner()),
            Text::make(fn (Get $get, ?Location $record): ?string => $get($key) !== 'own' && $record !== null ? CashBoxes::mergeWarning($record, CashPot::from($pot)) : null)
                ->color('warning')
                ->weight(FontWeight::SemiBold)
                ->extraAttributes(['data-cash-merge-warning' => $pot])
                ->visible(fn (Get $get, ?Location $record): bool => $get($key) !== 'own' && $record !== null && CashBoxes::mergeWarning($record, CashPot::from($pot)) !== null),
        ];
    }

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

                        // Prompt 317 — the bar ticket reads like an invoice ("Ticket de venta"); off until a sede wants it.
                        Toggle::make('bar_receipt_enabled')
                            ->label(__('Ofrecer ticket de barra'))
                            ->helperText(__('Permite ver e imprimir un ticket tras una venta de barra. Desactivado por defecto.'))
                            ->default(false),

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

                        // Prompt 347 — where the counter goes after a contribution (and a Barra sale) is recorded.
                        Select::make('after_recording')
                            ->label(__('Después de registrar'))
                            ->helperText(__('Al volver al inicio, la última venta sigue a mano dos minutos (recibo y anular).'))
                            ->options([
                                'home' => __('Volver al inicio'),
                                'new_member' => __('Nuevo socio en el dispensario'),
                                'stay' => __('Quedarse con el socio'),
                            ])
                            ->default('home')
                            ->selectablePlaceholder(false),

                        // Prompt 355 — the weight a member pays for, rounded to the half gram (0.2 g → 0.5 g, 1.1 g → 1.0 g).
                        Toggle::make('charge_rounding_enabled')
                            ->label(__('Redondeo del peso cobrado'))
                            ->default(true)
                            ->helperText(__('Se cobra el peso redondeado al medio gramo (mínimo 0.5 g); el stock y los límites usan el peso exacto. Cada persona puede desactivarlo en el mostrador para su sesión.')),

                        // Prompt 351 — the order of the dispensary's strain list; the counter's €↓ / €↑ / A–Z switch starts here.
                        Select::make('dispensary_sort')
                            ->label(__('Orden de las genéticas en el dispensario'))
                            ->helperText(__('El mostrador puede invertirlo; la elección se recuerda en esa tableta hasta el día siguiente.'))
                            ->options(DispensarySort::options())
                            ->default(DispensarySort::PRICE_DESC)
                            ->selectablePlaceholder(false),

                        Toggle::make('block_self_dispensation')
                            ->label(__('Prohibir auto-dispensación'))
                            ->helperText(__('Si está activado, un miembro del personal no puede atenderse a sí mismo: le atiende otra persona.')),

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

                        // Prompt 373 — «¿Dónde va el efectivo?» (Arron: "Members, drinks and edibles all go in separate boxes";
                        // Liam: "members money separate and all other transactions in one till"). The dispensary is always the
                        // till; each other kind of money goes in the till or its own box. Owner-only: a manager sees it read-only.
                        Fieldset::make(__('¿Dónde va el efectivo?'))
                            ->columnSpanFull()
                            ->columns(1)
                            ->schema([
                                Actions::make(collect(['all_till' => __('Todo en la caja'), 'fees_apart' => __('Cuotas aparte'), 'all_apart' => __('Todo aparte')])
                                    ->map(fn (string $label, string $preset): Action => Action::make('cash_preset_'.$preset)
                                        ->label($label)->color('gray')->outlined()
                                        ->extraAttributes(['data-cash-preset' => $preset])
                                        ->action(function (Set $set) use ($preset): void {
                                            foreach (CashBoxes::PRESETS[$preset] as $pot => $choice) {
                                                $set(CashBoxes::SETTINGS[$pot], $choice);
                                            }
                                        }))->values()->all())
                                    ->visible(fn (): bool => self::actorIsOwner()),
                                Text::make(__('Dispensario (flores, hachís, porros, vapers…): siempre en la caja, con el fondo, y se cuenta cada noche.')),
                                ...self::cashBoxRow('EDIBLES', __('Comestibles')),
                                ...self::cashBoxRow('BAR', __('Barra (bebidas, comida)')),
                                ...self::cashBoxRow('SHOP', __('Tienda (productos)')), // prompt 378
                                ...self::cashBoxRow('FEES', __('Cuotas de socio')),
                                Text::make(fn (Get $get): string => CashBoxes::summary(collect(CashBoxes::SETTINGS)->map(fn (string $key, string $pot): string => (string) ($get($key) ?: CashBoxes::defaultFor($pot)))->all()))
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['data-cash-summary' => true]),
                                Text::make(__('Los cambios se aplican la próxima vez que se abra la caja.'))->color('gray'),
                            ]),

                        // Prompt 312 — clocking the closer out is automatic by default (with a 2-minute *Deshacer*); a sede
                        // that prefers the old question keeps it.
                        // Prompt 338 — the SAME setting now governs both ends of the till (key kept: no data migration).
                        Select::make('till_close_clock_out')
                            ->label(__('Fichar al abrir y cerrar la caja'))
                            ->options(['auto' => __('Automático'), 'ask' => __('Preguntar')])
                            ->selectablePlaceholder(false)
                            ->helperText(__('Automático: quien abre la caja queda con la entrada fichada y quien la cierra con la salida fichada; cada uno puede deshacerlo durante 2 minutos. Preguntar: se le pregunta. A las demás personas nunca se les ficha: lo hacen con su propio PIN.')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(), // prompt 374 — the cash rows need the width: half a page stacked every choice

                // Prompt 367 — Informes → Pérdidas' alert (291's discount alert, extended to every loss). Owner only.
                Section::make(__('Pérdidas'))
                    ->visible(fn (Get $get): bool => $get('kind') !== LocationKind::ALMACEN->value) // nothing is taken at the store
                    ->schema([
                        TextInput::make('losses_alert_threshold_pct')
                            ->label(__('Avisar si las pérdidas de un día superan el … % de lo recaudado'))
                            ->integer()->minValue(1)->maxValue(100)->suffix('%')
                            ->default(fn (): int => (int) Settings::DEFAULTS['losses_alert_threshold_pct'])
                            ->disabled(fn (): bool => ! self::actorIsOwner())
                            ->helperText(__('Un aviso en el panel y en el resumen de la mañana cuando las pérdidas de ayer en esta sede (Informes → Pérdidas, sin los descuentos de socio) pasan de este % de lo recaudado. Solo el propietario lo cambia.')),
                    ]),

                Section::make(__('Seguridad del mostrador'))
                    ->visible(fn (Get $get): bool => $get('kind') !== LocationKind::ALMACEN->value) // no counter at the store (277)
                    ->schema([
                        // Prompt 337 — a sede that does not record entries at the door: no Recepción on its counter.
                        Toggle::make('reception_enabled')
                            ->label(__('Mostrar Recepción en el mostrador'))
                            ->default(true)
                            ->live()
                            ->afterStateUpdated(fn (bool $state, Set $set) => $state ? null : $set('restrict_pos_to_checked_in', false))
                            ->helperText(__('Desactívalo si esta sede no registra entradas en la puerta.')),

                        // Disabled — and so not saved, which stores it OFF — while Recepción is off: nobody could be served.
                        Toggle::make('restrict_pos_to_checked_in')
                            ->label(__('Solo dispensar a socios que han entrado'))
                            ->disabled(fn (Get $get): bool => ! (bool) $get('reception_enabled'))
                            ->helperText(fn (Get $get): string => (bool) $get('reception_enabled')
                                ? __('El dispensario solo acepta socios con la entrada registrada en recepción.')
                                : __('Requiere Recepción.')),

                        // Prompt 348 (Ben: "bring a picture up when they scan the QR code") — no sharing cards.
                        Toggle::make('confirm_photo_on_scan')
                            ->label(__('Confirmar la foto al escanear'))
                            ->default(true)
                            ->helperText(__('Al escanear la tarjeta, se muestra la foto del socio en grande para confirmar que es esa persona. Desactívalo en una puerta con mucha cola: el socio se selecciona sin pedir confirmación (su foto sigue en su ficha).')),

                        Toggle::make('require_photo_to_dispense')
                            ->label(__('Exigir foto para dispensar'))
                            ->default(true)
                            ->helperText(__('Un socio sin foto en su ficha no puede dispensarse hasta que se le haga una (botón «Hacer foto» en el dispensario).')),

                        Toggle::make('camera_scan_enabled')
                            ->label(__('Escaneo con cámara'))
                            ->helperText(__('Permite leer la tarjeta QR del socio con la cámara de la tableta.')),

                        // Prompt 324 — practice on the real counter, nothing kept.
                        Toggle::make('counter_training_enabled')
                            ->label(__('Permitir modo formación'))
                            ->default(true)
                            ->helperText(__('El personal puede practicar en el mostrador real: nada de lo que haga en modo formación se guarda.')),

                        // Prompt 330 — a short tone when a new sign-up arrives at the counter. Off: the banner is enough.
                        Toggle::make('applications_chime_enabled')
                            ->label(__('Sonido al recibir solicitudes'))
                            ->helperText(__('Suena un aviso breve en el mostrador cuando llega una solicitud de alta nueva.')),

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
