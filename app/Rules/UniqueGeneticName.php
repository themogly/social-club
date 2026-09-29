<?php

namespace App\Rules;

use App\Models\Genetic;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Prompt 308 — one name, one strain: no other strain of the club, a DELETED one included, may already have the name,
 * compared ignoring case, accents and spacing ({@see Genetic::comparableName()}). A strain keeping its own name (or
 * re-cased) passes, so a pre-308 duplicate stays editable until someone tidies it by hand.
 */
class UniqueGeneticName implements ValidationRule
{
    public function __construct(private readonly ?Genetic $current = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }
        if ($this->current !== null && Genetic::comparableName((string) $value) === Genetic::comparableName((string) $this->current->name)) {
            return;
        }

        $match = Genetic::sameNameAs((string) $value, $this->current?->getKey());
        if ($match !== null) {
            $fail($match->trashed()
                ? __('Ya existe una genética borrada con este nombre. Restáurala en lugar de crear otra.')
                : __('Ya existe una genética con este nombre.'));
        }
    }
}
