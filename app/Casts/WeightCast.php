<?php

namespace App\Casts;

use App\Support\Weight;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts an integer-centigrams column to/from a Weight value object. The DB and
 * the model attribute are always integer centigrams; grams → centigrams
 * conversion is explicit via Weight::fromGrams() at the edge, never here.
 *
 * @implements CastsAttributes<Weight, mixed>
 */
class WeightCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Weight
    {
        return $value === null ? null : Weight::fromCentigrams((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Weight) {
            return $value->centigrams;
        }

        if (is_int($value)) {
            return $value; // already centigrams
        }

        throw new InvalidArgumentException(
            "Attribute [{$key}] is weight: set it with a Weight instance or integer centigrams. "
            .'Convert grams explicitly with Weight::fromGrams() at the edge.'
        );
    }
}
