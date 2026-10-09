<?php

namespace App\Rules;

use App\Support\Email;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Prompt 372 — an email address mail can actually be sent to ({@see Email::isSendable()}), in place of Laravel's `email`
 * rule, which lets «juan @gmail.com» through. Blank is the field's own business (`required` / `nullable`).
 */
class SendableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (! is_string($value) || ! Email::isSendable($value)) {
            $fail(__('Este correo no es válido. Revisa que no tenga espacios ni acentos y que termine en algo como .com o .es.'));
        }
    }
}
