<?php

namespace App\Actions;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Identify the counter operator by PIN. Counter apps (dispensary/bar POS, check-in)
 * run under one authenticated device session; a PIN unlock names the operator
 * recorded on each transaction — so the till can switch operator without a full
 * re-login. PINs are hashed, never logged or shown.
 *
 * Throttled (prompt 120): the bucket is LOCATION-WIDE (see IdentifiesOperator::operatorThrottleKey — a shared
 * counter, so rotating the browser session must not reset the count), and the lockout ESCALATES — each
 * successive lockout at the same sede is longer, so a brute-forcer faces exponential cost while a fat-fingered
 * operator only waits a minute. A correct PIN clears the whole throttle.
 */
class UnlockOperator
{
    /** Failed attempts allowed before the pad locks out. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Escalating lockout windows (seconds); the last value repeats for further strikes.
     *
     * @var list<int>
     */
    public const LOCKOUT_WINDOWS = [60, 300, 900, 3600]; // 1m → 5m → 15m → 1h

    /** The attempt tally survives this long without a further failure (a window to reach MAX_ATTEMPTS). */
    private const ATTEMPT_TTL = 300;

    /** Strikes decay after this much calm, so the escalation resets once an attack stops. */
    private const STRIKE_TTL = 3600;

    /**
     * Verify a PIN against the active staff assigned to the location. Returns the matched operator, or null on
     * a wrong PIN or while locked out.
     */
    public function handle(Location $location, string $pin, string $throttleKey): ?User
    {
        if ($this->isLockedOut($throttleKey)) {
            return null;
        }

        /** @var Collection<int, User> $candidates */
        $candidates = $location->users()->where('active', true)->get();

        foreach ($candidates as $candidate) {
            if ($candidate->pin !== null && Hash::check($pin, $candidate->pin)) {
                $this->clear($throttleKey);

                return $candidate;
            }
        }

        $this->registerFailure($throttleKey);

        return null;
    }

    public function isLockedOut(string $throttleKey): bool
    {
        return (bool) $this->safely(fn (): bool => Cache::has($this->key($throttleKey, 'lockout')), false);
    }

    /** Seconds until the pad will accept a PIN again (0 when not locked) — drives the countdown on the pad. */
    public function lockoutSecondsRemaining(string $throttleKey): int
    {
        $until = (int) $this->safely(fn () => Cache::get($this->key($throttleKey, 'lockout'), 0), 0);

        return max(0, $until - now()->getTimestamp());
    }

    private function registerFailure(string $throttleKey): void
    {
        $this->safely(function () use ($throttleKey): void {
            $attempts = (int) Cache::get($this->key($throttleKey, 'attempts'), 0) + 1;
            Cache::put($this->key($throttleKey, 'attempts'), $attempts, self::ATTEMPT_TTL);

            if ($attempts < self::MAX_ATTEMPTS) {
                return;
            }

            // Locked out: escalate the window by how many times this sede has locked out recently.
            $strikes = (int) Cache::get($this->key($throttleKey, 'strikes'), 0) + 1;
            Cache::put($this->key($throttleKey, 'strikes'), $strikes, self::STRIKE_TTL);

            $window = self::LOCKOUT_WINDOWS[min($strikes - 1, count(self::LOCKOUT_WINDOWS) - 1)];
            Cache::put($this->key($throttleKey, 'lockout'), now()->getTimestamp() + $window, $window);
            Cache::forget($this->key($throttleKey, 'attempts')); // fresh tally for the next window
        }, null);
    }

    private function clear(string $throttleKey): void
    {
        $this->safely(function () use ($throttleKey): void {
            Cache::forget($this->key($throttleKey, 'attempts'));
            Cache::forget($this->key($throttleKey, 'lockout'));
            Cache::forget($this->key($throttleKey, 'strikes'));
        }, null);
    }

    /**
     * Run a cache interaction, degrading to $default if the cache backend is unreachable — a Redis blip must
     * NEVER 503 the counter (prompt 124), and the overlay's lockout check runs on every counter render. The
     * throttle simply weakens during an outage; a correct PIN is still required, so access stays gated.
     */
    private function safely(callable $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $default;
        }
    }

    private function key(string $throttleKey, string $suffix): string
    {
        return $throttleKey.':'.$suffix;
    }
}
