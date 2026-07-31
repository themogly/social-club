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
        'default_locale' => 'en',           // organisation UI default (prompt 19: system default is en)
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

        // Membership lifecycle
        'expiring_soon_days' => 30,
        'renewal_reminder_lead_days' => 7,

        // Invitations — an unused invite link expires after this many days (prompt 29).
        'invite_expiry_days' => 14,

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

        // Dispensary POS
        'pos_require_checked_in' => false,  // only dispense to members checked in at the door
        'pos_signature_required' => false,  // capture an on-screen signature per withdrawal (acta-grade)

        // Governance / actas
        'minute_quorum_fraction_bp' => 5000,  // quorum = 50% of active members (basis points)

        // Till / cash
        'arqueo_variance_tolerance_cents' => 500,
        'expense_approval_threshold_cents' => 10000,

        // Data retention & privacy
        'data_retention_days' => 1825,      // 5 years after leaving
        'audit_retention_days' => 3650,     // deliberately longer
        'signed_url_ttl_seconds' => 300,    // 5 minutes
        'consent_text_version' => '1.0',

        // Per-location defaults (overridable per premises)
        'aforo_default' => 50,
        'aforo_enforcement' => 'block',     // block | warn

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
        ],
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $scope = app(ActiveScope::class);
            $organisationId = $scope->organisationId();

            if ($organisationId !== null) {
                $rows = Setting::query()->withoutGlobalScopes()
                    ->where('organisation_id', $organisationId)
                    ->where('key', $key)
                    ->get();

                $locationId = $scope->locationId();
                $row = ($locationId !== null ? $rows->firstWhere('location_id', $locationId) : null)
                    ?? $rows->firstWhere('location_id', null);

                if ($row !== null) {
                    return self::cast($row->value, $row->type);
                }
            }
        } catch (Throwable) {
            // A missing table (pre-migration), stale cache or DB blip must degrade to a
            // sensible default, never throw — silent job death is the enemy here.
        }

        return self::DEFAULTS[$key] ?? $default;
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
