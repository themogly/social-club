<?php

namespace App\Filament\Pages\Auth;

use App\Support\Email;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;

/**
 * Password-reset request. Like the login page, it normalises the submitted email (lowercase + trim) before
 * the broker looks the user up and keys the reset token — so a reset works for the lowercase-stored address
 * on any driver, not by accident of the collation (prompt 146). The reset flow is otherwise unchanged.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => Email::normalise(is_string($data['email'] ?? null) ? $data['email'] : null),
        ];
    }
}
