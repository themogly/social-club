<?php

namespace App\Support;

use App\Actions\RecordAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Post-296 audit, finding 6 — "that PIN is taken" is an answer about somebody else's PIN. PINs must be unique (270: a
 * PIN is a sign-in), so the answer stays, but it is AUDITED and it stops after a few: five taken PINs an hour per
 * person setting them. Once throttled, every PIN gets the same refusal, taken or not, so the form is no longer a way to
 * find the owner's PIN.
 */
final class PinCollisionGuard
{
    public const MAX_PER_HOUR = 5;

    public static function refusal(string $pin, ?string $ignoreUserId): ?string
    {
        $key = 'pin-collision:'.(Auth::id() ?? request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            return __('Has probado demasiados PIN que ya estaban en uso. Espera una hora antes de volver a intentarlo.');
        }

        if (! User::pinIsTaken($pin, $ignoreUserId)) {
            return null;
        }

        RateLimiter::hit($key, 3600);
        (new RecordAuditLog)->handle('user.pin.collision', null, null, ['target_user_id' => $ignoreUserId]);

        return __('Ese PIN ya lo usa otra persona. Elige otro.');
    }
}
