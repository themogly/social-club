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
        'min_age' => 18,
        'carencia_days' => 15,
        'daily_limit_cg' => 350,          // 3.5 g
        'monthly_limit_cg' => 10000,      // 100 g
        'forecast_options_g' => [30, 50, 60, 90],
        'active_member_cap' => 750,
        'stock_ceiling_days' => 5,
        'limit_breach_hard_block' => true,
        'limit_override_requires_manager' => true,
        'currency_locale' => 'es',        // €1.234,56
        'data_retention_days' => 1825,    // 5 years after leaving
        'aforo_default' => 50,
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
