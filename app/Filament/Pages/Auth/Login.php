<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\Email;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * Staff login. Two changes from Filament's default:
 *
 * 1. The submitted email is NORMALISED (lowercase + trim) before the credential lookup — so a login matches
 *    the lowercase-stored address on any driver, rather than depending on the database collation being
 *    case-insensitive (prompt 146).
 *
 * 2. "Remember me" defaults to CHECKED (prompt 239). A counter device is a shared tablet a responsable signs
 *    in ONCE; floor staff then identify by PIN. When the session lifetime lapses, the remember cookie
 *    re-authenticates the device so it lands on the counter's PIN surface — "identify the operator" — instead
 *    of the email/password form that floor staff have no credentials for. The PIN, not the device session, is
 *    the gate at the counter, and a responsable can still log the DEVICE out (behind staff.manage, prompt 239).
 *    Uncheck it on a shared or public back-office computer.
 *
 * Rate limits, throttles and MFA are untouched.
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

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->default(true);
    }

    /**
     * Prompt 262 — ONE login serves the panel and the counter, so it must admit a counter-only account. Filament's own
     * check is `canAccessPanel()`, which now also needs `panel.access`: without this override a STAFF account (no
     * panel access by default) was refused with "these credentials do not match our records" and locked out of the
     * counter too. The check is not weakened — an inactive or role-less account is still refused — and where the
     * account then lands is `CounterAwareLoginResponse`'s decision.
     */
    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return $user instanceof User && $user->canUseTheApp();
    }
}
