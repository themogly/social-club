<?php

namespace App\Casts;

use App\Support\Email;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Normalise an email to lowercase + trim on WRITE, at the model boundary — so every path that sets it
 * (Filament forms, csc:install, ImportMembers, the public application form, the invite flow, and anything
 * added later) inherits normalisation without having to remember it. A normalisation applied at six call
 * sites is one that will be missed at the seventh (prompt 146). Applied to User::email and Member::email.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class NormalisedEmail implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return Email::normalise($value === null ? null : (string) $value);
    }
}
