<?php

namespace App\Filament\Pages;

use App\Actions\RecordAuditLog;
use App\Enums\SettingType;
use App\Support\CounterScreens;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
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
        'member_number_prefix' => SettingType::STRING,
        'member_number_padding' => SettingType::INT,
        'min_age' => SettingType::INT,
        'carencia_days' => SettingType::INT,
        'assembly_notice_days' => SettingType::INT,
        'monthly_window' => SettingType::STRING,
        'active_member_cap' => SettingType::INT,
        'stock_ceiling_days' => SettingType::INT,
        'gauge_warning_pct' => SettingType::INT,
        'gauge_alert_pct' => SettingType::INT,
        'avalador_policy' => SettingType::STRING,
        'avalador_max_sponsees' => SettingType::INT,
        'wallet_debt_allowed' => SettingType::BOOL,
        'avalador_therapeutic_exempt' => SettingType::BOOL,
        'expiring_soon_days' => SettingType::INT,
        'renewal_reminder_lead_days' => SettingType::INT,
        'invite_expiry_days' => SettingType::INT,
        'signature_on_application' => SettingType::BOOL,
        'refund_window_days' => SettingType::INT,
        'temporary_members_enabled' => SettingType::BOOL,
        'temporary_window_days' => SettingType::INT,
        'temporary_reminder_lead_days' => SettingType::INT,
        'temporary_count_toward_cap' => SettingType::BOOL,
        'batch_expiry_window_days' => SettingType::INT,
        'stock_cover_window_days' => SettingType::INT,
        'stock_cover_low_days' => SettingType::INT,
        'discounts_stack' => SettingType::BOOL,
        'data_retention_days' => SettingType::INT,
        'audit_retention_days' => SettingType::INT,
        'message_retention_days' => SettingType::INT,
        'application_retention_days' => SettingType::INT,
        'signed_url_ttl_seconds' => SettingType::INT,
        'qr_scan_max_failures_per_minute' => SettingType::INT,
        'counter_hero' => SettingType::STRING,
        'counter_landing' => SettingType::STRING,
        // Locale (prompt 44) — actively read by ResolveLocale / LocaleSwitcher / SetLocale, but had
        // no admin UI until now. enabled_locales is an array (JSON); default_locale a plain string.
        'default_locale' => SettingType::STRING,
        'enabled_locales' => SettingType::JSON,
    ];

    /** The locales the platform ships translations for (prompt 19). */
    private const LOCALE_OPTIONS = ['es' => 'Español', 'en' => 'English'];

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
                        TextInput::make('member_number_prefix')->label(__('Prefijo de nº de socio'))->maxLength(8),
                        TextInput::make('member_number_padding')->label(__('Dígitos del nº de socio'))
                            ->numeric()->minValue(1)->maxValue(10)->required(),
                    ])->columns(3),

                Section::make(__('Idioma y gobernanza'))
                    ->description(__('Idiomas disponibles y el umbral de quórum para las actas.'))
                    ->schema([
                        Select::make('default_locale')
                            ->label(__('Idioma por defecto'))
                            ->options(self::LOCALE_OPTIONS)
                            ->required(),
                        CheckboxList::make('enabled_locales')
                            ->label(__('Idiomas disponibles'))
                            ->options(self::LOCALE_OPTIONS)
                            ->required(),
                        TextInput::make('minute_quorum_fraction_pct')
                            ->label(__('Quórum de actas (%)'))
                            ->numeric()->minValue(1)->maxValue(100)->required()
                            ->helperText(__('% de socios activos necesario para el quórum de una asamblea.')),
                        TextInput::make('assembly_second_call_quorum_pct')
                            ->label(__('Quórum en segunda convocatoria (%)'))
                            ->numeric()->minValue(0)->maxValue(100)->required()
                            ->helperText(__('% para el quórum en segunda convocatoria. 0 = queda constituida sea cual sea la asistencia.')),
                        TextInput::make('assembly_notice_days')
                            ->label(__('Plazo de convocatoria (días)'))
                            ->numeric()->minValue(0)->required()
                            ->helperText(__('Días mínimos entre emitir una convocatoria y celebrar la asamblea.')),
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
                        TextInput::make('low_balance_threshold_eur')->label(__('Aviso de saldo bajo (€)'))->numeric()->minValue(0)->required()
                            ->helperText(__('Cuando una aportación deja el saldo por debajo de esta cifra, se envía un aviso push al socio.')),
                    ])->columns(3),

                Section::make(__('Membresía'))
                    ->schema([
                        TextInput::make('expiring_soon_days')->label(__('Días "caduca pronto"'))->numeric()->required(),
                        TextInput::make('renewal_reminder_lead_days')->label(__('Días de aviso de renovación'))->numeric()->required(),
                        TextInput::make('invite_expiry_days')->label(__('Caducidad de invitación (días)'))->numeric()->minValue(1)->required()
                            ->helperText(__('Una invitación de alta sin usar caduca tras estos días.')),
                        TextInput::make('refund_window_days')->label(__('Ventana de reembolso (días)'))->numeric()->minValue(0)->required()
                            ->helperText(__('Una dispensación más antigua que esta ventana no puede reembolsarse en mostrador (0 = sin límite).')),
                        // Prompt 220. ORG-level, not per-location (unlike `signature_on_dispensation`): this is
                        // what evidence the club keeps of the applicant's OWN act of consent, and the emailed-link
                        // route has no active location in session — a per-location toggle would silently fall back
                        // to the org value on exactly the route where the applicant is alone with the form.
                        Toggle::make('signature_on_application')->label(__('Firma en el alta'))
                            ->helperText(__('El alta se firma en pantalla, por las tres vías. Si se desactiva, el personal declara que tiene el consentimiento en papel.')),
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
                        TextInput::make('stock_cover_window_days')->label(__('Ventana de consumo (días)'))->numeric()->minValue(1)->required()
                            ->helperText(__('Sobre cuántos días de dispensaciones reales se calcula el ritmo de cada genética.')),
                        TextInput::make('stock_cover_low_days')->label(__('Avisar por debajo de (días de stock)'))->numeric()->minValue(1)->required()
                            ->helperText(__('«Stock bajo» se mide contra la demanda: cuántos días duraría al ritmo actual. Un aviso que llega el día que te quedas sin existencias no es un aviso.')),
                        Toggle::make('discounts_stack')->label(__('Los descuentos se acumulan')),
                    ])->columns(2),

                Section::make(__('Caja'))
                    ->schema([
                        TextInput::make('till_default_float_eur')->label(__('Fondo de caja por defecto (€)'))->numeric()->minValue(0)->required()
                            ->helperText(__('Se propone al abrir la caja cada mañana. El operador siempre puede cambiarlo. 0 = sin fondo por defecto.')),
                        TextInput::make('arqueo_variance_tolerance_eur')->label(__('Tolerancia de descuadre (€)'))->numeric()->minValue(0)->required(),
                        TextInput::make('expense_approval_threshold_eur')->label(__('Umbral de aprobación de gasto (€)'))->numeric()->minValue(0)->required(),
                    ])->columns(2),

                Section::make(__('Privacidad y datos'))
                    ->schema([
                        TextInput::make('data_retention_days')->label(__('Retención de datos de socio (días)'))->numeric()->required(),
                        TextInput::make('audit_retention_days')->label(__('Retención del registro de auditoría (días)'))->numeric()->required()
                            ->helperText(__('Retención MÍNIMA. El registro de auditoría es inalterable y no se purga automáticamente; esta cifra se comunica (panel, RAT), no borra nada.')),
                        TextInput::make('message_retention_days')->label(__('Retención de mensajes (días)'))->numeric()->minValue(1)->required()
                            ->helperText(__('El texto de los mensajes con socios se redacta pasado este plazo; queda el hilo como evidencia del contacto.')),
                        TextInput::make('application_retention_days')->label(__('Retención de solicitudes (días)'))->numeric()->minValue(1)->required()
                            ->helperText(__('Una solicitud rechazada o abandonada se anonimiza y su foto de identidad se borra pasado este plazo. Las aprobadas no se tocan (la foto pasa a ser del socio).')),
                        TextInput::make('signed_url_ttl_seconds')->label(__('Caducidad de URLs firmadas (seg.)'))->numeric()->required(),
                        TextInput::make('qr_scan_max_failures_per_minute')->label(__('Máx. escaneos fallidos por minuto'))->numeric()->minValue(1)->required()
                            ->helperText(__('Tras tantos escaneos de tarjeta fallidos por operador en un minuto, se bloquea temporalmente (anti fuerza bruta).')),
                        Select::make('counter_landing')->label(__('Pantalla de inicio del mostrador'))
                            ->options([
                                'home' => __('Inicio del mostrador (iconos)'),
                                'screen' => __('Directo a la pantalla de trabajo'),
                            ])->required()
                            ->helperText(__('Dónde entra un operador al abrir el mostrador. Si eliges la pantalla de trabajo, cada persona entra en la primera que tiene permiso para abrir.')),
                        Select::make('counter_hero')->label(__('Botón principal del mostrador'))
                            ->options(fn (): array => collect(CounterScreens::forUser(null))
                                ->mapWithKeys(fn (array $s): array => [$s['route'] => $s['label']])->all())
                            ->required()
                            ->helperText(__('Qué destino ocupa el botón grande del inicio del mostrador. Quien no tenga permiso para abrirlo verá como principal el primero que sí pueda abrir.')),
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
        Settings::set('daily_limit_cg', (int) round_half_up(((float) $state['daily_limit_g']) * 100), SettingType::CG);
        Settings::set('monthly_limit_cg', (int) round_half_up(((float) $state['monthly_limit_g']) * 100), SettingType::CG);

        // Euros shown at the edge, stored as integer cents.
        Settings::set('wallet_debt_limit_cents', (int) round_half_up(((float) ($state['wallet_debt_limit_eur'] ?? 0)) * 100), SettingType::CENTS);
        // The door threshold is a SEPARATE figure from the hard limit — never derived from it.
        Settings::set('wallet_door_debt_threshold_cents', (int) round_half_up(((float) ($state['wallet_door_debt_threshold_eur'] ?? 0)) * 100), SettingType::CENTS);
        Settings::set('low_balance_threshold_cents', (int) round_half_up(((float) ($state['low_balance_threshold_eur'] ?? 0)) * 100), SettingType::CENTS);
        Settings::set('till_default_float_cents', (int) round_half_up(((float) ($state['till_default_float_eur'] ?? 0)) * 100), SettingType::CENTS);
        Settings::set('arqueo_variance_tolerance_cents', (int) round_half_up(((float) ($state['arqueo_variance_tolerance_eur'] ?? 0)) * 100), SettingType::CENTS);
        Settings::set('expense_approval_threshold_cents', (int) round_half_up(((float) ($state['expense_approval_threshold_eur'] ?? 0)) * 100), SettingType::CENTS);

        // Quórum shown as a percentage at the edge, stored as basis points (50% → 5000 bp).
        Settings::set('minute_quorum_fraction_bp', (int) round_half_up(((float) ($state['minute_quorum_fraction_pct'] ?? 0)) * 100), SettingType::BP);
        Settings::set('assembly_second_call_quorum_bp', (int) round_half_up(((float) ($state['assembly_second_call_quorum_pct'] ?? 0)) * 100), SettingType::BP);

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
        $values['low_balance_threshold_eur'] = ((int) Settings::get('low_balance_threshold_cents')) / 100;
        $values['till_default_float_eur'] = ((int) Settings::get('till_default_float_cents')) / 100;
        $values['arqueo_variance_tolerance_eur'] = ((int) Settings::get('arqueo_variance_tolerance_cents')) / 100;
        $values['expense_approval_threshold_eur'] = ((int) Settings::get('expense_approval_threshold_cents')) / 100;
        $values['minute_quorum_fraction_pct'] = ((int) Settings::get('minute_quorum_fraction_bp')) / 100;
        $values['assembly_second_call_quorum_pct'] = ((int) Settings::get('assembly_second_call_quorum_bp')) / 100;

        return $values;
    }
}
