<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Post-296 audit, finding 7 — the owner's decision: a PIN session (a registered tablet plus a PIN, prompts 267/289) may
 * open the panel only after the person gives their password (and their MFA code where enrolled), once per SHIFT per
 * tablet. A password login is never asked: it already did exactly that. The counter itself stays PIN-only.
 */
final class PanelIdentity
{
    /**
     * How long one confirmation lasts: a working shift. Long enough that nobody is asked twice in an evening; short
     * enough that a tablet left signed in overnight asks again the next day (prompt 310: the banner offers to renew in
     * the last {@see self::RENEW_WINDOW_MINUTES} minutes, so it does not run out mid-task).
     */
    public const SHIFT_HOURS = 12;

    public const RENEW_WINDOW_MINUTES = 15;

    public static function mustConfirm(User $user): bool
    {
        if (session('auth.via_pin') !== true) {
            return false;
        }

        try {
            return ! self::cache()->has(self::key($user));
        } catch (Throwable) {
            self::cacheUnavailable();

            return true; // fail closed: when in doubt, ask for the password
        }
    }

    public static function confirmed(User $user): void
    {
        $until = now()->addHours(self::SHIFT_HOURS);
        // The expiry as the value (a timestamp), so the banner can tell when it is about to run out. Read back with a
        // cast: Redis hands numbers back as strings (prompt 307).
        self::cache()->put(self::key($user), $until->getTimestamp(), $until);
    }

    /** Minutes left on this PIN session's confirmation, or null when there is none to renew (or no PIN session). */
    public static function minutesLeft(User $user): ?int
    {
        if (session('auth.via_pin') !== true) {
            return null;
        }

        try {
            $until = self::cache()->get(self::key($user));
        } catch (Throwable) {
            return null;
        }

        return is_numeric($until) ? max(0, (int) ceil(((int) $until - now()->getTimestamp()) / 60)) : null;
    }

    /** Is the confirmation in its last minutes — time for the *Renovar* banner (prompt 310)? */
    public static function expiresSoon(User $user): bool
    {
        $left = self::minutesLeft($user);

        return $left !== null && $left <= self::RENEW_WINDOW_MINUTES;
    }

    /**
     * Prompt 310 — failing closed stays, but visibly: one warning per request (no personal data), and the confirmation
     * page says why the password is being asked for again.
     */
    private static function cacheUnavailable(): void
    {
        $request = request();
        if ($request->attributes->get('panel_identity.cache_warned') === true) {
            return;
        }
        $request->attributes->set('panel_identity.cache_warned', true);

        Log::warning('Panel identity confirmation could not be checked: the cache is unavailable, so the password is asked for again.');
        session()->flash('panel_identity.cache_unavailable', true);
    }

    /** Per person, per tablet: someone else on the same tablet — or the same person on another — is asked for theirs. */
    /**
     * Prompt 344 — on the limiter store (`database` by default), not the default (Redis): a PIN sign-in's password
     * confirmation (310) is part of signing in, and with Redis down `confirmed()` threw, so nobody who came in by PIN could
     * get past the password step until Redis returned.
     */
    private static function cache(): Repository
    {
        return Cache::store(config('cache.limiter'));
    }

    private static function key(User $user): string
    {
        $terminal = session('counter.terminal_id');

        return 'panel-identity:'.$user->id.':'.(is_string($terminal) ? $terminal : 'browser');
    }
}
