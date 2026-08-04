<?php

namespace App\Filament\Pages\Auth;

use App\Support\Email;
use Filament\Auth\Pages\Login as BaseLogin;
use SensitiveParameter;

/**
 * Staff login. The only change from Filament's default is that the submitted email is NORMALISED
 * (lowercase + trim) before the credential lookup — so a login matches the lowercase-stored address on any
 * driver, rather than depending on the database collation being case-insensitive (prompt 146). What login
 * does is otherwise unchanged: rate limits, throttles and MFA are untouched.
 */
class Login extends BaseLogin
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'email' => Email::normalise(is_string($data['email'] ?? null) ? $data['email'] : null),
            'password' => $data['password'],
        ];
    }
}
