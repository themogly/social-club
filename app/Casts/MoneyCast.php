<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts an integer-cents column to/from a Money value object. The DB and the
 * model attribute are always integer cents; euros → cents conversion is explicit
 * via Money::fromEuros() at the Filament/import edge, never implicit here.
 *
 * @implements CastsAttributes<Money, mixed>
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::fromCents((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            return $value->cents;
        }

        if (is_int($value)) {
            return $value; // already cents
        }

        throw new InvalidArgumentException(
            "Attribute [{$key}] is money: set it with a Money instance or integer cents. "
            .'Convert euros explicitly with Money::fromEuros() at the edge.'
        );
    }
}
