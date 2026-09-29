<?php

namespace App\Actions\Users;

use App\Actions\RecordAuditLog;
use App\Models\User;
use App\Support\PinLookup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;

/**
 * Prompt 322 — *Probar PIN*: does this PIN open the counter as THIS person? The answer is yes or no for this person
 * only — a PIN that belongs to someone else is just "no", never whose it is. Throttled like `PinCollisionGuard` (five
 * an hour per person testing), and every try is audited as `user.pin.tested` with the result, never the PIN.
 *
 * Returns true / false, or null once throttled.
 */
class TestUserPin
{
    public const MAX_PER_HOUR = 5;

    /** @throws AuthorizationException */
    public function handle(User $actor, User $user, #[SensitiveParameter] string $pin): ?bool
    {
        if (! $actor->can('update', $user)) {
            throw new AuthorizationException(__('No puedes probar el PIN de esta persona.'));
        }

        $key = 'pin-test:'.$actor->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_HOUR)) {
            (new RecordAuditLog)->handle('user.pin.tested', $user, null, ['throttled' => true]);

            return null;
        }
        RateLimiter::hit($key, 3600);

        $lookup = $user->getRawOriginal('pin_lookup');
        $legacy = $user->getRawOriginal('pin');
        $matched = filled($lookup)
            ? hash_equals((string) $lookup, PinLookup::for($pin))
            : filled($legacy) && Hash::check($pin, (string) $legacy);

        (new RecordAuditLog)->handle('user.pin.tested', $user, null, ['matched' => $matched]);

        return $matched;
    }
}
