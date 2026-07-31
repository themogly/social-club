<?php

namespace App\Support;

use App\Casts\MoneyCast;
use App\Casts\WeightCast;
use BackedEnum;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Formats one audit-log field value into the SAME plain-language string the field shows
 * anywhere else in the panel — money in euros, weight in grams, enums as their translated
 * label, dates in the panel format, booleans as Yes/No (prompt 40). One generic mechanism:
 * a newly-audited model formats correctly with no changes here.
 *
 * The reliable signal is the *_cents / *_cg column-name suffix, NOT the Eloquent cast: this
 * codebase enforces those suffixes absolutely, whereas several genuinely-cg columns
 * (`Member::declared_monthly_cg`, the tier limit columns) are cast plain `integer` and
 * converted to grams by hand at the page edge. A cast-first formatter would render those as
 * raw integers. A secondary cast check still catches a MoneyCast/WeightCast column that
 * somehow lacked the suffix.
 */
class AuditFieldFormatter
{
    /** @var array<string, array<string, string>> model FQCN → its getCasts(), memoised per request */
    private static array $castsCache = [];

    public static function format(?string $modelClass, string $key, mixed $value): string
    {
        // Money / weight — trust the suffix (or a MoneyCast/WeightCast on a real model column).
        if (is_numeric($value) && self::isMoney($modelClass, $key)) {
            return Money::fromCents((int) $value)->formatted();
        }

        if (is_numeric($value) && self::isWeight($modelClass, $key)) {
            return Weight::fromCentigrams((int) $value)->formatted();
        }

        // Booleans (before/after are JSON-decoded, so this is unambiguous) → translated Yes/No.
        if (is_bool($value)) {
            return $value ? __('Sí') : __('No');
        }

        // Enum column on a known model → its translated label (the one case the suffix can't cover).
        $enum = self::enumLabel($modelClass, $key, $value);
        if ($enum !== null) {
            return $enum;
        }

        // Date / datetime-shaped string → the panel's standard format.
        $date = self::dateLabel($value);
        if ($date !== null) {
            return $date;
        }

        // Everything else (null, plain scalars, nested arrays) → the safe fallback.
        return self::scalarise($value);
    }

    private static function isMoney(?string $modelClass, string $key): bool
    {
        return str_ends_with($key, '_cents') || self::castIs($modelClass, $key, MoneyCast::class);
    }

    private static function isWeight(?string $modelClass, string $key): bool
    {
        return str_ends_with($key, '_cg') || self::castIs($modelClass, $key, WeightCast::class);
    }

    private static function enumLabel(?string $modelClass, string $key, mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $cast = self::castFor($modelClass, $key);

        if ($cast === null) {
            return null;
        }

        $castClass = explode(':', $cast)[0];

        if (! enum_exists($castClass) || ! is_a($castClass, HasLabel::class, true) || ! is_a($castClass, BackedEnum::class, true)) {
            return null;
        }

        /** @var HasLabel|null $case */
        $case = $castClass::tryFrom($value);

        $label = $case?->getLabel();

        return is_string($label) ? $label : null;
    }

    private static function dateLabel(mixed $value): ?string
    {
        // Audit payloads store dates via ->toDateString() / ->toIso8601String(). Match strictly so
        // a member number or enum backing value is never mistaken for a date.
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?/', $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // Time component present → date + time, else date only. `d/m/Y` matches the rest of the UI.
        return str_contains($value, ':') ? $date->format('d/m/Y H:i') : $date->format('d/m/Y');
    }

    private static function castIs(?string $modelClass, string $key, string $castClass): bool
    {
        $cast = self::castFor($modelClass, $key);

        return $cast !== null && explode(':', $cast)[0] === $castClass;
    }

    private static function castFor(?string $modelClass, string $key): ?string
    {
        if ($modelClass === null || ! class_exists($modelClass) || ! is_a($modelClass, Model::class, true)) {
            return null;
        }

        $casts = self::$castsCache[$modelClass] ??= (new $modelClass)->getCasts();
        $cast = $casts[$key] ?? null;

        return is_string($cast) ? $cast : null;
    }

    private static function scalarise(mixed $value): string
    {
        if ($value === null) {
            return '∅';
        }

        if (is_bool($value)) {
            return $value ? __('Sí') : __('No');
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Test hook: drop the per-request casts memo. */
    public static function flush(): void
    {
        self::$castsCache = [];
    }
}
