<?php

namespace App\Actions;

use App\Models\Location;
use App\Models\User;
use App\Support\Settings;
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
    /**
     * Failed attempts allowed before the pad locks out — the DEFAULT, overridable per sede (prompt 235).
     *
     * The owner: *"adjust the number of attempts."* This is the fat-finger tolerance, and it is legitimately
     * a club's call: a quiet sede with two staff who know their PINs wants three, a busy one with a queue and
     * cold hands wants more. Bounded 3–10 on the form — below three a mistyped digit locks the club out of
     * its own counter, above ten the escalation below is doing nothing.
     *
     * **The ESCALATION stays a constant**, deliberately. The attempt count answers "how forgiving is the
     * pad"; the escalating windows are what make a brute-force attempt cost exponentially more, which is a
     * security property of the product rather than a club preference. A club that could set every window to
     * 60 seconds would have a throttle in name only, and nobody would notice until it mattered.
     */
    public const MAX_ATTEMPTS = 5;

    /** The bounds the sede form enforces — declared here, beside the thing they bound. */
    public const MIN_CONFIGURABLE_ATTEMPTS = 3;

    public const MAX_CONFIGURABLE_ATTEMPTS = 10;

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

        $this->registerFailure($throttleKey, $this->maxAttemptsAt($location));

        return null;
    }

    /**
     * How many failures this sede tolerates. Inside the same fail-open reasoning as everything else here: a
     * settings read that throws must not 503 the counter (prompt 124), so it falls back to the code default.
     */
    public function maxAttemptsAt(?Location $location): int
    {
        $configured = (int) $this->safely(
            fn (): int => (int) Settings::get('counter_pin_max_attempts', self::MAX_ATTEMPTS, $location?->id),
            self::MAX_ATTEMPTS,
        );

        return max(self::MIN_CONFIGURABLE_ATTEMPTS, min(self::MAX_CONFIGURABLE_ATTEMPTS, $configured));
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

    private function registerFailure(string $throttleKey, int $maxAttempts): void
    {
        $this->safely(function () use ($throttleKey, $maxAttempts): void {
            $attempts = (int) Cache::get($this->key($throttleKey, 'attempts'), 0) + 1;
            Cache::put($this->key($throttleKey, 'attempts'), $attempts, self::ATTEMPT_TTL);

            if ($attempts < $maxAttempts) {
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

    /**
     * The state of one bucket, for the admin's lockout listing (prompt 235).
     *
     * Degrades to `available: false` rather than throwing — the Seguridad page must survive a cache outage
     * the same way the counter does, showing "estado no disponible" instead of an error.
     *
     * @return array{available: bool, locked: bool, seconds: int, attempts: int, strikes: int}
     */
    public function statusFor(string $throttleKey): array
    {
        $unavailable = ['available' => false, 'locked' => false, 'seconds' => 0, 'attempts' => 0, 'strikes' => 0];

        /** @var array{available: bool, locked: bool, seconds: int, attempts: int, strikes: int} $status */
        $status = $this->safely(function () use ($throttleKey): array {
            $until = (int) Cache::get($this->key($throttleKey, 'lockout'), 0);

            return [
                'available' => true,
                'locked' => $until > 0,
                'seconds' => max(0, $until - now()->getTimestamp()),
                'attempts' => (int) Cache::get($this->key($throttleKey, 'attempts'), 0),
                'strikes' => (int) Cache::get($this->key($throttleKey, 'strikes'), 0),
            ];
        }, $unavailable);

        return $status;
    }

    /**
     * Clear a bucket from the admin (prompt 235) — attempts, lockout AND strikes.
     *
     * The strikes go too, deliberately: a responsable clearing a lockout is vouching for that terminal, and
     * leaving the escalation armed would mean the next fat finger locks the sede for five minutes instead of
     * one, for a reason nobody can see. The owner clearing it says "this was us"; the count starts again at
     * 60 seconds. Returns what it wiped, so the caller can audit the figures rather than the act alone.
     *
     * @return array{attempts: int, strikes: int, was_locked: bool}
     */
    public function clearLockout(string $throttleKey): array
    {
        $before = $this->statusFor($throttleKey);

        $this->clear($throttleKey);

        return ['attempts' => $before['attempts'], 'strikes' => $before['strikes'], 'was_locked' => $before['locked']];
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
