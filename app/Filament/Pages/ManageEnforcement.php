<?php

namespace App\Filament\Pages;

use App\Actions\RecordAuditLog;
use App\Enums\SettingType;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * The enforcement matrix editor (prompt 53) — the highest-stakes setting in the app: for each check,
 * whether the DOOR and the COUNTER BLOCK, WARN or OVERRIDE (allow with a permissioned override). Until
 * now it could only be changed via tinker. Reads/writes the single `enforcement` Setting that
 * Settings::enforcement() consumes; owner only (settings.manage); every change audited.
 *
 * Two cells are LOCKED to BLOCK and cannot be edited:
 *   - `aforo` — you cannot "warn" a room over capacity and still let people in; capacity is a hard cap.
 *   - `age`  — a CSC legally cannot admit or dispense to a minor. Softening age to WARN/OVERRIDE would
 *     let a minor through with a warning or a permissioned override, which is legally indefensible; age
 *     is a hard legal floor, NOT a club-configurable policy. Recorded in DECISIONS.
 *
 * @property-read Schema $form
 */
class ManageEnforcement extends Page
{
    protected string $view = 'filament.pages.manage-enforcement';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 31;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, list<string>> The rules that apply at each surface (order = display order). */
    private const RULES = [
        'door' => ['age', 'membership', 'carencia', 'sanction', 'debt', 'unpaid_fee', 'aforo'],
        'counter' => ['age', 'membership', 'carencia', 'sanction', 'debt', 'unpaid_fee', 'daily_limit', 'monthly_limit', 'photo'],
        'stock' => ['ceiling'], // the premises legal stock ceiling at intake (prompt 110)
    ];

    /** Rules that are ALWAYS BLOCK and never editable (legal hard floor / hard capacity cap). */
    private const LOCKED = ['age', 'aforo'];

    private const MODES = ['BLOCK', 'WARN', 'OVERRIDE'];

    /**
     * Photo-on-file (prompt 157) is the exception to the matrix. It has NO hard-BLOCK mode — its strict
     * setting is OVERRIDE (blocked, but a manager may force it) — and it DEFAULTS to OFF (no check at all),
     * NOT the fail-safe BLOCK the other rules take, so a club mid-migration with paper members and no photos
     * is never surprise-blocked on upgrade. Read at the counter via Settings::photoEnforcement().
     */
    private const PHOTO_MODES = ['OFF', 'WARN', 'OVERRIDE'];

    public static function getNavigationLabel(): string
    {
        return __('Matriz de cumplimiento');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public function getTitle(): string
    {
        return __('Matriz de cumplimiento');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill($this->currentValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Puerta (check-in)'))
                    ->description(__('Qué hace el control de acceso ante cada situación.'))
                    ->schema($this->surfaceFields('door'))
                    ->columns(3),
                Section::make(__('Mostrador (dispensación)'))
                    ->description(__('Qué hace el mostrador ante cada situación al dispensar.'))
                    ->schema($this->surfaceFields('counter'))
                    ->columns(3),
                Section::make(__('Stock (entrada de género)'))
                    ->description(__('Qué hace la entrada de stock cuando el local superaría su techo legal.'))
                    ->schema($this->surfaceFields('stock'))
                    ->columns(3),
            ]);
    }

    /**
     * @return list<Component>
     */
    private function surfaceFields(string $surface): array
    {
        $fields = [];
        foreach (self::RULES[$surface] as $rule) {
            $locked = in_array($rule, self::LOCKED, true);
            $fields[] = Select::make("{$surface}.{$rule}")
                ->label(self::ruleLabel($rule))
                ->options(self::modeOptionsFor($rule))
                ->disabled($locked)
                ->helperText($this->fieldHelp($rule, $locked))
                ->selectablePlaceholder(false);
        }

        return $fields;
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $before = ['enforcement' => Settings::get('enforcement', Settings::DEFAULTS['enforcement'])];

        // Reassemble the matrix from the form, FORCING the locked cells to BLOCK server-side (a disabled
        // field isn't submitted, and even a tampered submit is ignored). Unknown modes fail safe to BLOCK.
        $matrix = [];
        foreach (self::RULES as $surface => $rules) {
            foreach ($rules as $rule) {
                $mode = in_array($rule, self::LOCKED, true)
                    ? 'BLOCK'
                    : ($state[$surface][$rule] ?? self::defaultMode($rule));
                $matrix[$surface][$rule] = in_array($mode, self::validModes($rule), true) ? $mode : self::defaultMode($rule);
            }
        }

        Settings::set('enforcement', $matrix, SettingType::JSON);

        (new RecordAuditLog)->handle('settings.enforcement.updated', null, $before, ['enforcement' => $matrix]);

        Notification::make()->title(__('Matriz de cumplimiento guardada'))->success()->send();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function currentValues(): array
    {
        $matrix = Settings::get('enforcement', Settings::DEFAULTS['enforcement']);
        $values = [];
        foreach (self::RULES as $surface => $rules) {
            foreach ($rules as $rule) {
                $current = is_array($matrix) ? ($matrix[$surface][$rule] ?? self::defaultMode($rule)) : self::defaultMode($rule);
                $values[$surface][$rule] = in_array($rule, self::LOCKED, true)
                    ? 'BLOCK'
                    : (in_array($current, self::validModes($rule), true) ? $current : self::defaultMode($rule));
            }
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private static function modeOptions(): array
    {
        return [
            'BLOCK' => __('Bloquear'),
            'WARN' => __('Avisar'),
            'OVERRIDE' => __('Permitir con permiso'),
        ];
    }

    /**
     * The mode options a given rule may take. Photo (prompt 157) offers OFF instead of a hard BLOCK.
     *
     * @return array<string, string>
     */
    private static function modeOptionsFor(string $rule): array
    {
        if ($rule === 'photo') {
            return [
                'OFF' => __('No comprobar'),
                'WARN' => __('Avisar'),
                'OVERRIDE' => __('Permitir con permiso'),
            ];
        }

        return self::modeOptions();
    }

    /**
     * @return list<string>
     */
    private static function validModes(string $rule): array
    {
        return $rule === 'photo' ? self::PHOTO_MODES : self::MODES;
    }

    /** Photo defaults to OFF (no surprise block); every other rule fails safe to BLOCK. */
    private static function defaultMode(string $rule): string
    {
        return $rule === 'photo' ? 'OFF' : 'BLOCK';
    }

    private function fieldHelp(string $rule, bool $locked): ?string
    {
        if ($locked) {
            return __('Siempre BLOQUEAR (requisito legal / aforo).');
        }

        if ($rule === 'photo') {
            return __('Por defecto sin comprobar. No tiene bloqueo duro: su modo estricto es «permitir con permiso».');
        }

        return null;
    }

    private static function ruleLabel(string $rule): string
    {
        return match ($rule) {
            'age' => __('Edad'),
            'membership' => __('Membresía'),
            'carencia' => __('Carencia'),
            'sanction' => __('Sanción'),
            'debt' => __('Deuda'),
            'unpaid_fee' => __('Cuota pendiente'),
            'aforo' => __('Aforo'),
            'daily_limit' => __('Límite diario'),
            'monthly_limit' => __('Límite mensual'),
            'photo' => __('Foto en ficha'),
            'ceiling' => __('Techo de stock del local'),
            default => $rule,
        };
    }
}
