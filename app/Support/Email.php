<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Email is the login identifier for staff and members, so its casing must be handled by the APPLICATION, not
 * left to the database collation (which differs by driver — MySQL's utf8mb4_unicode_ci is case-insensitive,
 * SQLite's `=` is not, and DB_COLLATION is one env var from changing either). One function, used by the write
 * cast (App\Casts\NormalisedEmail) and by every lookup, so both sides always compare like with like.
 *
 * RFC 5321 makes the local part technically case-sensitive, but no mainstream provider treats it that way and
 * every practical system stores email lowercase — that is the choice here (prompt 146). Only the email is
 * normalised; names, document numbers and everything else keep their case exactly.
 */
class Email
{
    /** Lowercase + trim; an empty or null value normalises to null (email is nullable on members). */
    public static function normalise(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalised = Str::lower(trim($email));

        return $normalised === '' ? null : $normalised;
    }

    /**
     * Prompt 372 — is this an address mail can actually be SENT to? Laravel's `email` rule (behind `->email()`) accepts
     * «juan @gmail.com», «juan@gmail», «josé@gmail.com», «"juan perez"@gmail.com», «juan@localhost» and «juan@gmail.c»;
     * Resend refuses them all («Invalid `to` field»). After {@see normalise()}: PHP's FILTER_VALIDATE_EMAIL (ASCII only — no
     * spaces, quotes, comments or accents), no IP-literal domain, and a dotted domain whose last label is two or more letters.
     * One function, used by the validation rule, the sending path and every surface that flags a bad address.
     */
    public static function isSendable(?string $email): bool
    {
        $email = self::normalise($email);
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $domain = (string) substr($email, (int) strrpos($email, '@') + 1);
        if (str_starts_with($domain, '[') || ! str_contains($domain, '.')) {
            return false;
        }
        $labels = explode('.', $domain);

        return preg_match('/^[a-z]{2,}$/', (string) end($labels)) === 1;
    }
}
