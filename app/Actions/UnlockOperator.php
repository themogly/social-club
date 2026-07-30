<?php

namespace App\Actions;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Identify the counter operator by PIN. Counter apps (dispensary/bar POS, check-in)
 * run under one authenticated device session; a PIN unlock names the operator
 * recorded on each transaction — so the till can switch operator without a full
 * re-login. Rate-limited; PINs are hashed, never logged or shown.
 */
class UnlockOperator
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    /**
     * Verify a PIN against the active staff assigned to the location. Returns the
     * matched operator, or null on a wrong PIN or while rate-limited.
     */
    public function handle(Location $location, string $pin, string $throttleKey): ?User
    {
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return null;
        }

        /** @var Collection<int, User> $candidates */
        $candidates = $location->users()->where('active', true)->get();

        foreach ($candidates as $candidate) {
            if ($candidate->pin !== null && Hash::check($pin, $candidate->pin)) {
                RateLimiter::clear($throttleKey);

                return $candidate;
            }
        }

        RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

        return null;
    }

    public function isLockedOut(string $throttleKey): bool
    {
        return RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS);
    }
}
