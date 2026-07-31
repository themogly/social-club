<?php

namespace App\Filament\Pages;

use App\Actions\RecordAuditLog;
use App\Enums\SettingType;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Organisation settings — every compliance/behaviour threshold, seeded with a
 * default and read everywhere through Settings::get(). Loads on mount, validates,
 * persists, audits the change and notifies. Owner only (settings.manage).
 *
 * @property-read Schema $form Magic schema accessor (InteractsWithSchemas).
 */
class ManageSettings extends Page
{
    protected string $view = 'filament.pages.manage-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 30;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Scalar settings persisted directly, with their storage type. */
    private const SCALARS = [
        'currency_locale' => SettingType::STRING,
        'member_number_prefix' => SettingType::STRING,
        'member_number_padding' => SettingType::INT,
        'min_age' => SettingType::INT,
        'carencia_days' => SettingType::INT,
        'monthly_window' => SettingType::STRING,
        'active_member_cap' => SettingType::INT,
        'stock_ceiling_days' => SettingType::INT,
        'gauge_warning_pct' => SettingType::INT,
        'gauge_alert_pct' => SettingType::INT,
        'avalador_policy' => SettingType::STRING,
        'avalador_max_sponsees' => SettingType::INT,
        'wallet_debt_allowed' => SettingType::BOOL,
        'fees_to_wallet_allowed' => SettingType::BOOL,
        'wallet_ring_fence' => SettingType::BOOL,
        'limit_override_requires_manager' => SettingType::BOOL,
        'avalador_therapeutic_exempt' => SettingType::BOOL,
        'expiring_soon_days' => SettingType::INT,
        'renewal_reminder_lead_days' => SettingType::INT,
        'invite_expiry_days' => SettingType::INT,
        'temporary_members_enabled' => SettingType::BOOL,
        'temporary_window_days' => SettingType::INT,
        'temporary_reminder_lead_days' => SettingType::INT,
        'temporary_count_toward_cap' => SettingType::BOOL,
        'batch_expiry_window_days' => SettingType::INT,
        'discounts_stack' => SettingType::BOOL,
        'data_retention_days' => SettingType::INT,
        'audit_retention_days' => SettingType::INT,
        'signed_url_ttl_seconds' => SettingType::INT,
    ];

    public static function getNavigationLabel(): string
    {
        return __('Ajustes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public function getTitle(): string
    {
        return __('Ajustes de la organización');
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
                Section::make(__('Identidad'))
                    ->description(__('Cómo se numera y se muestra el club.'))
                    ->schema([
                        Select::make('currency_locale')->label(__('Formato de moneda'))
                            ->options(['es' => '€1.234,56', 'en' => '€1,234.56'])->required(),
                        TextInput::make('member_number_prefix')->label(__('Prefijo de nº de socio'))->maxLength(8),
                        TextInput::make('member_number_padding')->label(__('Dígitos del nº de socio'))
                            ->numeric()->minValue(1)->maxValue(10)->required(),
                    ])->columns(3),

                Section::make(__('Cumplimiento'))
                    ->description(__('Límites legales. Cambiarlos afecta solo a comprobaciones futuras, nunca a lo ya registrado.'))
                    ->schema([
                        TextInput::make('min_age')->label(__('Edad mínima'))->numeric()->required()
                            ->helperText(__('Edad mínima para ser socio. Se bloquea la dispensación por debajo.')),
                        TextInput::make('carencia_days')->label(__('Días de carencia'))->numeric()->required()
                            ->helperText(__('Espera obligatoria desde el alta antes de la primera dispensación.')),
                        TextInput::make('daily_limit_g')->label(__('Límite diario (g)'))->numeric()->required()
                            ->helperText(__('Máximo por socio y día. Se bloquea en el mostrador al superarlo.')),
                        TextInput::make('monthly_limit_g')->label(__('Techo mensual (g)'))->numeric()->required()
                            ->helperText(__('Máximo por socio y mes.')),
                        Select::make('monthly_window')->label(__('Ventana mensual'))
                            ->options(['calendar' => __('Mes natural'), 'rolling30' => __('30 días móviles')])->required(),
                        TextInput::make('active_member_cap')->label(__('Tope de socios activos'))->numeric()->required()
                            ->helperText(__('Aviso en el panel al acercarse a este número.')),
                        TextInput::make('stock_ceiling_days')->label(__('Días para techo de stock'))->numeric()->required()
                            ->helperText(__('socios × límite diario × estos días = stock máximo recomendado en sede.')),
                        Toggle::make('limit_override_requires_manager')->label(__('El override de límite requiere gerente'))
                            ->helperText(__('Si se activa, solo un gerente puede forzar una dispensación que supere el límite.')),
                    ])->columns(3),

                Section::make(__('Indicador de consumo'))
                    ->schema([
                        TextInput::make('gauge_warning_pct')->label(__('% aviso'))->numeric()->required(),
                        TextInput::make('gauge_alert_pct')->label(__('% alerta'))->numeric()->required(),
                    ])->columns(2),

                Section::make(__('Avalador'))
                    ->schema([
                        Select::make('avalador_policy')->label(__('Política de aval'))
                            ->options([
                                'required' => __('Obligatorio'),
                                'waivable' => __('Exonerable por gerente'),
                                'not_required' => __('No requerido'),
                            ])->required(),
                        TextInput::make('avalador_max_sponsees')->label(__('Máx. avalados por socio'))->numeric()->required(),
                        Toggle::make('avalador_therapeutic_exempt')->label(__('Socios terapéuticos exentos de aval'))
                            ->helperText(__('Los socios terapéuticos pueden sustituir el aval por un certificado médico.')),
                    ])->columns(2),

                Section::make(__('Cartera y deuda'))
                    ->schema([
                        Toggle::make('wallet_debt_allowed')->label(__('Permitir deuda'))
                            ->helperText(__('Si se desactiva, ninguna aportación puede dejar el monedero en negativo.')),
                        TextInput::make('wallet_debt_limit_eur')->label(__('Límite de deuda (€)'))->numeric()->minValue(0)->required()
                            ->helperText(__('Tope duro: el mostrador BLOQUEA una aportación que dejaría la deuda por encima de esta cifra.')),
                        TextInput::make('wallet_door_debt_threshold_eur')->label(__('Umbral de deuda en la puerta (€)'))->numeric()->minValue(0)->required()
                            ->helperText(__('Cifra DISTINTA del tope duro: la puerta reacciona (avisa/bloquea según la matriz) al llegar a esta deuda en el check-in.')),
                        Toggle::make('fees_to_wallet_allowed')->label(__('Cobrar cuotas a la cartera'))
                            ->helperText(__('Permite pagar la cuota de socio con saldo del monedero.')),
                        Toggle::make('wallet_ring_fence')->label(__('Monedero por sede'))
                            ->helperText(__('El saldo del monedero es específico de cada sede y no se comparte entre sedes.')),
                    ])->columns(3),

                Section::make(__('Membresía'))
                    ->schema([
                        TextInput::make('expiring_soon_days')->label(__('Días "caduca pronto"'))->numeric()->required(),
                        TextInput::make('renewal_reminder_lead_days')->label(__('Días de aviso de renovación'))->numeric()->required(),
                        TextInput::make('invite_expiry_days')->label(__('Caducidad de invitación (días)'))->numeric()->minValue(1)->required()
                            ->helperText(__('Una invitación de alta sin usar caduca tras estos días.')),
                    ])->columns(3),

                Section::make(__('Socios temporales'))
                    ->description(__('Socios de corta estancia que caducan automáticamente. NOTA: el encaje legal de esta figura no está resuelto en la jurisprudencia de CSC — úsala con criterio.'))
                    ->schema([
                        Toggle::make('temporary_members_enabled')->label(__('Permitir socios temporales'))
                            ->helperText(__('Si se desactiva, no aparece la opción de socio temporal en el alta.')),
                        TextInput::make('temporary_window_days')->label(__('Ventana temporal (días)'))->numeric()->minValue(1)->required()
                            ->helperText(__('Un socio temporal caduca a los tantos días de su alta.')),
                        TextInput::make('temporary_reminder_lead_days')->label(__('Aviso previo a la baja (días)'))->numeric()->minValue(0)->required()
                            ->helperText(__('Días antes de la baja para avisar (0 = sin aviso).')),
                        Toggle::make('temporary_count_toward_cap')->label(__('Cuentan para el tope de socios'))
                            ->helperText(__('Si se activa, los socios temporales cuentan para el tope de socios activos.')),
                    ])->columns(2),

                Section::make(__('Existencias'))
                    ->schema([
                        TextInput::make('batch_expiry_window_days')->label(__('Ventana de caducidad de lote (días)'))->numeric()->required(),
                        Toggle::make('discounts_stack')->label(__('Los descuentos se acumulan')),
                    ])->columns(2),

                Section::make(__('Caja'))
                    ->schema([
                        TextInput::make('arqueo_variance_tolerance_eur')->label(__('Tolerancia de descuadre (€)'))->numeric()->minValue(0)->required(),
                        TextInput::make('expense_approval_threshold_eur')->label(__('Umbral de aprobación de gasto (€)'))->numeric()->minValue(0)->required(),
                    ])->columns(2),

                Section::make(__('Privacidad y datos'))
                    ->schema([
                        TextInput::make('data_retention_days')->label(__('Retención de datos de socio (días)'))->numeric()->required(),
                        TextInput::make('audit_retention_days')->label(__('Retención del registro de auditoría (días)'))->numeric()->required(),
                        TextInput::make('signed_url_ttl_seconds')->label(__('Caducidad de URLs firmadas (seg.)'))->numeric()->required(),
                    ])->columns(3),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();          // validates
        $before = $this->currentValues();

        foreach (self::SCALARS as $key => $type) {
            if (array_key_exists($key, $state)) {
                Settings::set($key, $state[$key], $type);
            }
        }

        // Grams shown at the edge, stored as centigrams.
        Settings::set('daily_limit_cg', (int) round(((float) $state['daily_limit_g']) * 100), SettingType::CG);
        Settings::set('monthly_limit_cg', (int) round(((float) $state['monthly_limit_g']) * 100), SettingType::CG);

        // Euros shown at the edge, stored as integer cents.
        Settings::set('wallet_debt_limit_cents', (int) round_half_up(((float) ($state['wallet_debt_limit_eur'] ?? 0)) * 100), SettingType::CENTS);
        // The door threshold is a SEPARATE figure from the hard limit — never derived from it.
        Settings::set('wallet_door_debt_threshold_cents', (int) round_half_up(((float) ($state['wallet_door_debt_threshold_eur'] ?? 0)) * 100), SettingType::CENTS);
        Settings::set('arqueo_variance_tolerance_cents', (int) round_half_up(((float) ($state['arqueo_variance_tolerance_eur'] ?? 0)) * 100), SettingType::CENTS);
        Settings::set('expense_approval_threshold_cents', (int) round_half_up(((float) ($state['expense_approval_threshold_eur'] ?? 0)) * 100), SettingType::CENTS);

        (new RecordAuditLog)->handle('settings.updated', null, $before, $this->currentValues());

        Notification::make()->title(__('Ajustes guardados'))->success()->send();
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $values = [];
        foreach (array_keys(self::SCALARS) as $key) {
            $values[$key] = Settings::get($key);
        }
        $values['daily_limit_g'] = ((int) Settings::get('daily_limit_cg')) / 100;
        $values['monthly_limit_g'] = ((int) Settings::get('monthly_limit_cg')) / 100;
        $values['wallet_debt_limit_eur'] = ((int) Settings::get('wallet_debt_limit_cents')) / 100;
        $values['wallet_door_debt_threshold_eur'] = ((int) Settings::get('wallet_door_debt_threshold_cents')) / 100;
        $values['arqueo_variance_tolerance_eur'] = ((int) Settings::get('arqueo_variance_tolerance_cents')) / 100;
        $values['expense_approval_threshold_eur'] = ((int) Settings::get('expense_approval_threshold_cents')) / 100;

        return $values;
    }
}
