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
        'minute_quorum_fraction_bp' => 5000,  // first-call quorum = 50% of active members (basis points)
        'assembly_second_call_quorum_bp' => 0, // second-call quorum (0 = constituted whatever the attendance)
        'assembly_notice_days' => 15,         // minimum days between issuing a convocatoria and the assembly

        // Till / cash
        // The standing opening float for this sede's drawer (prompt 182), pre-filled every morning so
        // opening is one tap. 0 = no default set, which the open screen states rather than presenting an
        // unexplained empty field. Always overridable at the counter — a figure you cannot change is worse
        // than typing. Deliberately a SETTING rather than a carry-forward from the previous close: see
        // DECISIONS (a carried-forward count imports yesterday's variance into today's opening as if it
        // were intended).
        'till_default_float_cents' => 0,
        'arqueo_variance_tolerance_cents' => 500,
        'expense_approval_threshold_cents' => 10000,

        // Data retention & privacy
        'data_retention_days' => 1825,      // 5 years after leaving
        'import_staging_retention_hours' => 4, // abandoned member-import CSV scratch files swept after this (prompt 142)
        'audit_retention_days' => 3650,     // MINIMUM retention only — the audit log is append-only and
        // never auto-purged (the model refuses deletes). Prompt 58.
        'message_retention_days' => 730,    // member↔club message bodies redacted after this (2 years; prompt 136)
        'application_retention_days' => 180, // rejected/abandoned member applications anonymised + their ID photo
        //                                      deleted after this (6 months; security-audit finding on prompt 157)
        'signed_url_ttl_seconds' => 300,    // 5 minutes
        // Consent (prompts 97 + 153): the version stamped on every consent record, AND the two texts it refers
        // to — shown to the applicant so the consent is INFORMED (Art. 7(2): clear and plain language). The
        // texts are PER-LOCALE and versioned AS A SET, so the es and en of a version are by construction the
        // same declaration; the version stamped at capture is the one the applicant actually saw, and the LOCALE
        // they read it in is stamped alongside. SPANISH is the authoritative text (Spanish asociación, Spanish
        // estatutos) — the others are translations of a specific version, and the form says so in non-es locales.
        // Read only through App\Support\ConsentText (es fallback, legacy-string safe); a missing locale degrades
        // to the Spanish, never blank. Plain text, displayed inline (no public page to link to — NOTES §A).
        'consent_text_version' => '1.0',
        'consent_privacy_text' => [
            'es' => 'Tratamos tus datos personales para gestionar tu condición de socio/a (alta, cuota, control de acceso y dispensación) conforme al RGPD. El consumo de cannabis y el uso terapéutico son datos de categoría especial (art. 9 RGPD) y reciben protección reforzada. No cedemos tus datos a terceros salvo obligación legal. Puedes ejercer tus derechos de acceso, rectificación y supresión desde tu área de socio/a.',
            'en' => 'We process your personal data to manage your membership (registration, fees, access control and dispensing) in accordance with the GDPR. Cannabis consumption and therapeutic use are special-category data (Art. 9 GDPR) and receive reinforced protection. We do not share your data with third parties except where legally required. You may exercise your rights of access, rectification and erasure from your member area.',
        ],
        'consent_statutes_text' => [
            'es' => 'Solicitar el alta implica aceptar los estatutos de la asociación: es una asociación privada sin ánimo de lucro de consumidores de cannabis, no un comercio; el cannabis se dispensa a coste como aportación al consumo compartido entre socios, nunca como venta. La asociación opera bajo tolerancia judicial, no autorización. Pide una copia íntegra de los estatutos a la asociación.',
            'en' => 'Applying for membership entails accepting the association\'s statutes: it is a private, non-profit association of cannabis consumers, not a business; cannabis is dispensed at cost as a contribution to shared consumption among members, never as a sale. The association operates under judicial tolerance, not authorisation. Request a full copy of the statutes from the association.',
        ],
        // Brute-force guard on QR-card scans (failed scans per minute, per operator). Prompt 58.
        'qr_scan_max_failures_per_minute' => 10,
        // Minutes of NO real operator input before a counter screen auto-locks (prompt 120). Locking signs the
        // operator out, so commits are refused server-side and an unattended tablet stops showing member data
        // until someone re-enters a PIN. Per-location (a quiet sede may want longer). 0 disables the idle lock.
        'counter_idle_lock_minutes' => 5,
        // Where one link into the counter lands (prompt 189). 'home' = the tile hub, 'screen' = straight to
        // the first screen the operator may open (prompt 172's per-user resolution, which is the fallback
        // either way — a till-only operator must always land somewhere they are allowed to be).
        'counter_landing' => 'home',
        // Prompt 193 — the bar's two optional cart panels, per SEDE. Most bar sales are a coffee for cash, so
        // both default OFF and the panel is not rendered at all when off (not collapsed, not disabled) — the
        // cart column then opens on the basket, where the operator's attention belongs. The flag governs
        // INPUT only: a socio or a ticket reference recorded earlier still renders on receipts, in the ledger
        // export and in reports.
        // Prompt 194 — does this sede have card readers? CONFIGURATION, not feature detection: a USB QR or
        // RFID reader is a keyboard and has no presence any browser API can detect, so there is nothing to
        // test for. It changes the lookup field's WORDS only; token resolution runs either way, so a scan
        // that happens anyway still works. Default off — a club with no readers must never be told to scan.
        'card_readers_enabled' => false,
        'bar_attach_socio_enabled' => false,
        'bar_ticket_reference_enabled' => false,
        // Panic lockdown (prompt 121), all per-org. The safety-net auto-reactivation runs after this many
        // minutes so a locked-out club — who are the data controller — always regains access to their own
        // statutory register (default 24h). The owner email "way back" link is valid for this many hours.
        'lockdown_auto_reactivate_minutes' => 1440,
        'lockdown_reactivation_link_ttl_hours' => 48,

        // One-tap weight presets on the dispensary POS (prompt 133), per-location — clubs sell different amounts.
        // 3.5 g is ResolvePrice::EIGHTH_CG, the button that triggers the eighth break. Grams at the edge.
        'pos_weight_presets_g' => [1, 2, 3.5, 5],

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
                // Photo-on-file (prompt 157) is the ONE rule that ships OFF, not BLOCK: a club mid-migration has
                // hundreds of paper members with no photo, and a hard block on day one is a system they switch
                // off. OFF = no check (behaviour unchanged). A club opts in to WARN or OVERRIDE. There is no hard
                // BLOCK mode for photo by design — its strict setting is OVERRIDE (blocked, manager may force
                // with a reason + audit). Read it ONLY via self::photoEnforcement(), never enforcement().
                'photo' => 'OFF',
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

    /**
     * Photo-on-file enforcement mode (OFF | WARN | OVERRIDE) for a surface (prompt 157). Deliberately NOT
     * routed through enforcement(), which fail-safes UNKNOWN combinations to BLOCK — the correct default for
     * age/membership, but the WRONG one for photo: a club that customised its enforcement matrix before this
     * rule existed has a stored matrix with no `photo` key, and a BLOCK fallback there is exactly the
     * surprise-block-on-upgrade the prompt forbids. So this treats anything that is not an explicit WARN or
     * OVERRIDE — no matrix, a legacy matrix, an 'OFF', even a stray 'BLOCK' — as OFF. Photo has no hard-BLOCK
     * mode: its strict setting is OVERRIDE (dispensing is blocked, but a manager may force it with a reason
     * and an audit row, so a paper-member migration is never wedged).
     */
    public static function photoEnforcement(string $surface = 'counter'): string
    {
        $value = data_get(self::get('enforcement', self::DEFAULTS['enforcement']), "{$surface}.photo");

        return in_array($value, ['WARN', 'OVERRIDE'], true) ? $value : 'OFF';
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
