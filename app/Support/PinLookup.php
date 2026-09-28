<?php

namespace App\Support;

use SensitiveParameter;

/**
 * The lookup value of a counter PIN (prompt 286): HMAC-SHA256 of the PIN under a key derived from APP_KEY with a fixed
 * context. It lets the counter find the ONE person with a PIN in a single indexed query — no bcrypt, whose cost was paid
 * once per person at the sede on every attempt. Without the server's key the stored value is useless for guessing; the
 * online throttle (UnlockOperator) is what protects a 4–8 digit PIN, and it is unchanged.
 *
 * Rotating APP_KEY therefore invalidates every PIN (they are re-settable; the runbook already says never rotate it).
 */
class PinLookup
{
    public static function for(#[SensitiveParameter] string $pin): string
    {
        return hash_hmac('sha256', $pin, self::key());
    }

    private static function key(): string
    {
        $appKey = (string) config('app.key');
        $bytes = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;

        return hash_hkdf('sha256', $bytes, 32, 'csc-pin-lookup');
    }
}
