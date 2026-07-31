<?php

namespace App\Rules;

use App\Models\Member;
use App\Support\Settings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforce `avalador_max_sponsees` (prompt 34): an avalador cannot back more members than
 * the configured maximum. Before this the cap was inert — a sponsor could back unlimited
 * members. A ValidationRule object (not a bare closure) so Filament passes it through
 * cleanly rather than evaluating it as an injected value.
 */
class AvaladorWithinSponseeCap implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $max = (int) Settings::get('avalador_max_sponsees', 5);
        $count = Member::query()->withoutGlobalScopes()->where('avalador_member_id', $value)->count();

        if ($count >= $max) {
            $fail(__('Este avalador ya ha alcanzado el máximo de :max avalados.', ['max' => $max]));
        }
    }
}
