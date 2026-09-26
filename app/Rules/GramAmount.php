<?php

namespace App\Rules;

use App\Support\Weight;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A typed gram amount must be ONE unambiguous number (prompt 257) — the field-level face of
 * {@see Weight::fromGrams()}'s contract, so "1.000" is a validation message the operator reads instead of a
 * thousand grams silently stored as one. Blank passes (`required` decides that).
 */
class GramAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || is_int($value) || is_float($value)) {
            return;
        }

        if (Weight::canonicalGrams((string) $value) === null) {
            $fail(__('Escribe los gramos sin separador de miles y con dos decimales como máximo (p. ej. 1000 o 3,5).'));
        }
    }
}
