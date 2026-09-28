<?php

namespace App\Actions;

use App\Models\Location;
use App\Models\User;
use App\Support\PinLookup;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Identify the counter operator by PIN. Counter apps (dispensary/bar POS, check-in)
 * run under one authenticated device session; a PIN unlock names the operator
 * recorded on each transaction — so the till can switch operator without a full
 * re-login. PINs are stored as a keyed lookup (prompt 286), never logged or shown.
 *
 * Throttled (prompt 120): the bucket is LOCATION-WIDE (see IdentifiesOperator::operatorThrottleKey — a shared
 * counter, so rotating the browser session must not reset the count), and the lockout ESCALATES — each
 * successive lockout at the same sede is longer, so a brute-forcer faces exponential cost while a fat-fingered
 * operator only waits a minute.
 *
 * **A correct PIN no longer clears the throttle (prompt 270, superseding 120's "a correct PIN clears everything").**
 * Since 267 a PIN is a full sign-in, and clearing on success let an insider interleave their OWN PIN between guesses at
 * someone else's — unbounded guessing (the audit made 24 wrong guesses against a limit of 5, then signed in as the
 * owner). Failures now decay only with time (the 300 s attempt window, the hour for strikes); a fat finger still costs
 * at most that window. A responsable can still clear a bucket from Seguridad ({@see self::clearLockout()}).
 *
 * **A PIN that matches two people signs in neither** (prompt 270). PINs are unique per organisation on save, but a PIN
 * set before that rule existed may still be shared; first-match used to sign a staff member in as the owner.
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
     *
     * Prompt 286 — ONE indexed lookup of the PIN's keyed HMAC ({@see PinLookup}), never a bcrypt check per person. The
     * only bcrypt left is the LEGACY scan, over people at this sede still holding a pre-286 hash, and only when the
     * lookup found nobody (it empties as each enters their PIN once). Two legacy matches are still the 270 ambiguity —
     * refused, never first-match. A single legacy match upgrades that person: lookup written, bcrypt hash nulled, in one
     * transaction. (A legacy PIN equal to an upgraded person's could only date from before 270's uniqueness rule; the
     * go-live list already has everyone set a fresh, distinct PIN.)
     */
    public function handle(Location $location, #[SensitiveParameter] string $pin, string $throttleKey): ?User
    {
        if ($this->isLockedOut($throttleKey)) {
            return null;
        }

        // Post-296 audit (A·6) — reserve this attempt BEFORE checking the PIN, atomically. Checking first and counting
        // after let N parallel requests all pass the lockout check before any failure was written: N guesses a window.
        $maxAttempts = $this->maxAttemptsAt($location);
        $attempt = $this->reserveAttempt($throttleKey);
        if ($attempt !== null && $attempt > $maxAttempts) {
            $this->lockOut($throttleKey);

            return null;
        }

        $lookup = PinLookup::for($pin);

        /** @var Collection<int, User> $matches */
        $matches = $location->users()->where('active', true)->where('pin_lookup', $lookup)->get();

        // The legacy scan runs ONLY when the lookup found nobody (prompt 286), so an upgraded person's PIN is one query
        // however many legacy hashes remain at the sede.
        /** @var Collection<int, User> $legacy */
        $legacy = $matches->isNotEmpty() ? collect() : $location->users()->where('active', true)->whereNull('pin_lookup')->whereNotNull('pin')->get()
            ->filter(fn (User $candidate): bool => Hash::check($pin, (string) $candidate->getRawOriginal('pin')));

        $all = $matches->concat($legacy);

        if ($all->count() === 1) {
            $operator = $all->first();
            $this->releaseAttempt($throttleKey); // a right PIN costs nothing

            return $legacy->isNotEmpty() ? $this->upgrade($operator, $lookup) : $operator;
        }

        if ($all->count() > 1) {
            // Ambiguous: refuse rather than guess who typed it, and say so in the trail (ids only — never the PIN).
            (new RecordAuditLog)->handle('counter.pin.ambiguous', $location, null, [
                'user_ids' => $all->pluck('id')->values()->all(),
            ]);
        }

        // The failure keeps its reserved attempt; the one that fills the set locks the pad.
        if ($attempt === null || $attempt >= $maxAttempts) {
            $this->lockOut($throttleKey);
        }

        return null;
    }

    /**
     * How many wrong PINs are left before this bucket locks out — shown on the pad ("Te quedan 2 intentos") so a
     * wrong attempt visibly costs something. Degrades to the sede maximum if the cache is unreachable.
     */
    public function attemptsRemaining(?Location $location, string $throttleKey): int
    {
        $attempts = (int) $this->safely(fn (): int => (int) Cache::get($this->key($throttleKey, 'attempts'), 0), 0);

        return max(0, $this->maxAttemptsAt($location) - $attempts);
    }

    /**
     * A legacy PIN's first correct use: store the lookup and null the bcrypt hash, together. If the lookup is already
     * someone else's (a person at another sede set the same PIN since), the sign-in still succeeds — it matched exactly
     * one person HERE — and the row is left for a responsable to reset (audited, ids only).
     */
    private function upgrade(User $user, string $lookup): User
    {
        try {
            DB::transaction(fn () => User::query()->withTrashed()->whereKey($user->id)
                ->update(['pin_lookup' => $lookup, 'pin' => null]));
            $user->setRawAttributes(array_merge($user->getAttributes(), ['pin_lookup' => $lookup, 'pin' => null]), true);
        } catch (UniqueConstraintViolationException) {
            (new RecordAuditLog)->handle('counter.pin.upgrade_collision', $user);
        }

        return $user;
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

    /** The attempt's number in this window (1-based), counted atomically; null when the cache is unreachable (fail open, 124). */
    private function reserveAttempt(string $throttleKey): ?int
    {
        return $this->safely(function () use ($throttleKey): int {
            Cache::add($this->key($throttleKey, 'attempts'), 0, self::ATTEMPT_TTL);

            return (int) Cache::increment($this->key($throttleKey, 'attempts'));
        }, null);
    }

    private function releaseAttempt(string $throttleKey): void
    {
        $this->safely(fn () => Cache::decrement($this->key($throttleKey, 'attempts')), null);
    }

    /** Lock the pad, the window escalating with how many times this sede has locked out recently. */
    private function lockOut(string $throttleKey): void
    {
        $this->safely(function () use ($throttleKey): void {
            Cache::add($this->key($throttleKey, 'strikes'), 0, self::STRIKE_TTL);
            $strikes = (int) Cache::increment($this->key($throttleKey, 'strikes'));
            Cache::put($this->key($throttleKey, 'strikes'), $strikes, self::STRIKE_TTL); // decays after an hour of calm

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
