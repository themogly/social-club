<?php

namespace App\Support;

use App\Enums\SettingType;
use App\Models\Setting;
use Throwable;

/**
 * The most-referenced accessor in the build. Resolves a setting
 * **location → organisation → hardcoded code default**, and ALWAYS returns a
 * value — it never throws on a missing or stale entry (so it is safe inside
 * queued jobs, mail, and the compliance checks that gate the counter).
 *
 * Prompt 03 builds the editing UI; the seeded rows override these defaults.
 */
class Settings
{
    /** Final-fallback code defaults (seeded into the settings table too). NOTES §A reference table. */
    public const DEFAULTS = [
        // Identity / display
        'enabled_locales' => ['es', 'en'],
        // Organisation UI default is SPANISH (prompt 96): this is a Spanish product for Spanish clubs, and
        // members/staff with no explicit preference must not get English. English stays available and is the
        // ultimate system fallback (config('app.locale')). Flips both the admin panel and the member PWA.
        'default_locale' => 'es',
        'member_number_prefix' => 'M-',
        'member_number_padding' => 5,

        // Compliance (NOTES §A) — all editable
        'min_age' => 18,
        'carencia_days' => 15,
        'daily_limit_cg' => 350,            // 3.5 g
        'monthly_limit_cg' => 10000,        // 100 g
        'monthly_window' => 'calendar',     // calendar | rolling30
        'forecast_options_g' => [30, 50, 60, 90],
        'active_member_cap' => 750,
        'stock_ceiling_days' => 5,

        // Consumption gauge thresholds (percent of monthly allowance)
        'gauge_warning_pct' => 70,
        'gauge_alert_pct' => 95,

        // Avalador policy
        'avalador_policy' => 'required',    // required | waivable | not_required
        'avalador_therapeutic_exempt' => true,
        'avalador_max_sponsees' => 5,

        // Wallet & debt (per-location balances — prompt 01 checkpoint)
        'wallet_debt_allowed' => false,
        'wallet_debt_limit_cents' => 0,
        'wallet_door_debt_threshold_cents' => 0,
        'low_balance_threshold_cents' => 500,   // €5 — push a low-balance reminder when a debit crosses below (prompt 56)

        // Membership lifecycle
        'expiring_soon_days' => 30,
        'renewal_reminder_lead_days' => 7,
        'event_reminder_lead_hours' => 24,  // push event reminders this many hours before start (prompt 56)

        // Invitations — an unused invite link expires after this many days (prompt 29).
        'invite_expiry_days' => 14,

        // Refunds (prompt 65) — a dispensation older than this many days cannot be refunded at the
        // counter. 0 = no window. A configurable control, not a hardcoded const (regional practice varies).
        'refund_window_days' => 30,

        // Temporary / short-stay members (prompt 31). Legally unsettled — see DECISIONS.
        'temporary_members_enabled' => false,
        'temporary_window_days' => 30,
        'temporary_reminder_lead_days' => 3,
        'temporary_count_toward_cap' => true,   // temporary members count toward the active-member soft cap

        // Stock
        'low_stock_threshold_cg' => 5000,
        'batch_expiry_window_days' => 30,

        // Discounts
        'discounts_stack' => false,

        // Dispensary POS — PER LOCATION (prompt 44). These are the keys the LocationForm toggles
        // write, and are now what DispensaryPos actually enforces: a busy front-of-house sede can
        // require door check-in / a signature while a small quiet one need not. `Settings::get`
        // resolves the active location first, so a location override wins, else org, else this default.
        // (Replaces the org-wide pos_require_checked_in / pos_signature_required, which no UI ever wrote.)
        'restrict_pos_to_checked_in' => false,  // only dispense to members checked in at the door
        'signature_on_dispensation' => false,   // capture an on-screen signature per withdrawal (acta-grade)
        // Whether this sede runs MORE THAN ONE till at once (prompt 102). Default false: most clubs run one
        // drawer, so opening a caja asks only for the float and uses the sede's single terminal. Turn on for a
        // multi-terminal sede, and the operator picks which configured terminal to open.
        'multiple_tills_enabled' => false,

        // Counter scanning (per location) — opt-in camera QR at the door + POS. OFF by default:
        // camera access is a deliberate per-premises choice; the keyboard-wedge scanner + name
        // search always remain, so this only ADDS an input, never gates one (prompt 35).
        'camera_scan_enabled' => false,

        // Per-location (prompt 59): whether this sede runs a bar (a multi-site club may have one
        // premises with a bar and one without), and whether its wallet is ring-fenced from
        // cross-location auto-settlement. Both are location-scoped Setting rows written by LocationForm.
        'bar_enabled' => true,
        'ring_fenced' => false,

        // Governance / actas
        'minute_quorum_fraction_bp' => 5000,  // quorum = 50% of active members (basis points)
        'assembly_notice_days' => 15,         // minimum days between issuing a convocatoria and the assembly

        // Till / cash
        'arqueo_variance_tolerance_cents' => 500,
        'expense_approval_threshold_cents' => 10000,

        // Data retention & privacy
        'data_retention_days' => 1825,      // 5 years after leaving
        'audit_retention_days' => 3650,     // MINIMUM retention only — the audit log is append-only and
        // never auto-purged (the model refuses deletes). Prompt 58.
        'signed_url_ttl_seconds' => 300,    // 5 minutes
        // Consent (prompt 97): the version stamped on every consent record, AND the two texts it refers to —
        // shown to the applicant so the consent is INFORMED. Whoever revises a text bumps the version; the
        // version stamped at capture is the one the applicant actually saw. Plain text, displayed inline
        // (there is no public page to link to — NOTES §A).
        'consent_text_version' => '1.0',
        'consent_privacy_text' => 'Tratamos tus datos personales para gestionar tu condición de socio/a (alta, cuota, control de acceso y dispensación) conforme al RGPD. El consumo de cannabis y el uso terapéutico son datos de categoría especial (art. 9 RGPD) y reciben protección reforzada. No cedemos tus datos a terceros salvo obligación legal. Puedes ejercer tus derechos de acceso, rectificación y supresión desde tu área de socio/a.',
        'consent_statutes_text' => 'Solicitar el alta implica aceptar los estatutos de la asociación: es una asociación privada sin ánimo de lucro de consumidores de cannabis, no un comercio; el cannabis se dispensa a coste como aportación al consumo compartido entre socios, nunca como venta. La asociación opera bajo tolerancia judicial, no autorización. Pide una copia íntegra de los estatutos a la asociación.',
        // Brute-force guard on QR-card scans (failed scans per minute, per operator). Prompt 58.
        'qr_scan_max_failures_per_minute' => 10,

        // Per-location defaults (overridable per premises)
        'aforo_default' => 50,

        /*
         * Enforcement matrix — each check is independently BLOCK | WARN | OVERRIDE at the
         * DOOR and at the COUNTER (they genuinely differ: a member in debt may usually sit
         * down but not take product). Consumed by prompts 06/09/11/12.
         */
        'enforcement' => [
            'door' => [
                // Carencia at the door only WARNS: a member may enter and sit down, but
                // may not be dispensed to (counter.carencia BLOCKs that).
                'age' => 'BLOCK', 'membership' => 'BLOCK', 'carencia' => 'WARN',
                'sanction' => 'BLOCK', 'debt' => 'WARN', 'unpaid_fee' => 'WARN', 'aforo' => 'BLOCK',
            ],
            'counter' => [
                'age' => 'BLOCK', 'membership' => 'BLOCK', 'carencia' => 'BLOCK',
                'sanction' => 'BLOCK', 'debt' => 'BLOCK', 'unpaid_fee' => 'BLOCK',
                'daily_limit' => 'BLOCK', 'monthly_limit' => 'BLOCK',
            ],
            // The premises legal stock ceiling at intake (prompt 110). Defaults to WARN, not BLOCK: a club
            // legitimately receives a harvest that briefly exceeds a rolling figure, and a hard block with no
            // way through would push staff into not recording stock at all — far worse than an overage. A club
            // may set BLOCK; a BLOCK is overridable with limits.override, a reason and an audit row.
            'stock' => ['ceiling' => 'WARN'],
        ],
    ];

    /**
     * Resolve a setting: location override → org value → code default. When $locationId is null the
     * ACTIVE location is used (the normal enforcement path); pass an explicit id to read a SPECIFIC
     * location's override (e.g. the Location edit form, which edits a sede that isn't the active one).
     */
    /**
     * Request-lifetime memo keyed on the RESOLVED (organisation, location, key) — NOT on key alone (prompt
     * 109). The scope is ambient (the seeder loops locations, a queued worker processes several orgs), so a
     * key-only cache would hand one club's setting to another — a multi-tenancy leak, worse than the query
     * cost it saves. Only SUCCESSFUL resolutions are memoised (a real override, or a constant DEFAULTS value);
     * the swallow-to-default failure path is never cached, or a DB blip would pin the app to DEFAULTS.
     *
     * @var array<string, mixed>
     */
    private static array $memo = [];

    public static function get(string $key, mixed $default = null, ?string $locationId = null): mixed
    {
        try {
            $scope = app(ActiveScope::class);
            $organisationId = $scope->organisationId();

            if ($organisationId !== null) {
                $resolvedLocation = $locationId ?? $scope->locationId();
                $memoKey = $organisationId.'|'.($resolvedLocation ?? '').'|'.$key;

                if (array_key_exists($memoKey, self::$memo)) {
                    return self::$memo[$memoKey];
                }

                $rows = Setting::query()->withoutGlobalScopes()
                    ->where('organisation_id', $organisationId)
                    ->where('key', $key)
                    ->get();

                $row = ($resolvedLocation !== null ? $rows->firstWhere('location_id', $resolvedLocation) : null)
                    ?? $rows->firstWhere('location_id', null);

                if ($row !== null) {
                    return self::$memo[$memoKey] = self::cast($row->value, $row->type);
                }
                // No override: memoise the CONSTANT code default (safe — DEFAULTS[$key] never varies). A key
                // absent from DEFAULTS resolves to the caller's $default, which varies per call site, so it is
                // deliberately NOT memoised.
                if (array_key_exists($key, self::DEFAULTS)) {
                    return self::$memo[$memoKey] = self::DEFAULTS[$key];
                }
            }
        } catch (Throwable) {
            // A missing table (pre-migration), stale cache or DB blip must degrade to a
            // sensible default, never throw — silent job death is the enemy here.
        }

        return self::DEFAULTS[$key] ?? $default;
    }

    /** Clear the request-lifetime memo. Called on every write, and reset between tests (base TestCase). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * Enforcement mode (BLOCK | WARN | OVERRIDE) for a check at a surface, from the
     * enforcement matrix. Unknown combinations default to BLOCK (fail safe).
     */
    public static function enforcement(string $surface, string $rule): string
    {
        $matrix = self::get('enforcement', self::DEFAULTS['enforcement']);
        $value = data_get($matrix, "{$surface}.{$rule}");

        return is_string($value) ? $value : 'BLOCK';
    }

    /** Format a member number from the configured prefix + zero-padded sequence. */
    public static function formatMemberNumber(int $sequence): string
    {
        $prefix = (string) self::get('member_number_prefix', 'M-');
        $padding = (int) self::get('member_number_padding', 5);

        return $prefix.str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT);
    }

    /** Write an org-level (or location-level) setting. */
    public static function set(string $key, mixed $value, SettingType $type = SettingType::STRING, ?string $locationId = null): Setting
    {
        $organisationId = app(ActiveScope::class)->organisationId();

        // Any write invalidates the whole memo — writes are rare, reads constant, so clearing all is safer
        // (and simpler) than surgical invalidation (prompt 109).
        self::flush();

        return Setting::withoutGlobalScopes()->updateOrCreate(
            ['organisation_id' => $organisationId, 'location_id' => $locationId, 'key' => $key],
            ['value' => is_array($value) ? json_encode($value) : (string) $value, 'type' => $type],
        );
    }

    private static function cast(?string $value, SettingType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            SettingType::INT, SettingType::CENTS, SettingType::CG, SettingType::BP => (int) $value,
            SettingType::FLOAT => (float) $value,
            SettingType::BOOL => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            SettingType::JSON => json_decode($value, true),
            SettingType::STRING => $value,
        };
    }
}
