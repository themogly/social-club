<?php

namespace App\Support\Guides;

use App\Actions\RecordAuditLog;
use App\Models\User;
use App\Support\PinLookup;
use App\Support\Settings;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Prompt 353 — `/docs`: staff open the guides on their own phone with their counter PIN.
 *
 * **Guides only.** A right PIN sets ONE encrypted cookie naming the person and a fingerprint of their PIN. It is not a
 * sign-in: the `web` guard is never touched, so the counter, the panel and every member or till URL still see a guest.
 * Only the `/docs` routes read the cookie ({@see self::reader()}), and they serve guides, PDFs and guide images — nothing
 * else.
 *
 * **30 days**, then the PIN again. It ends at once when the account is deactivated or deleted, when the PIN changes (the
 * fingerprint no longer matches), on *Salir*, and for everyone when the owner switches *Guías en el móvil* off — every
 * request re-checks all of that against the database.
 *
 * **Guessing is impractical.** The PIN is found by its keyed lookup (286's HMAC, one indexed query, never a bcrypt scan on
 * a public URL). Wrong PINs are limited per IP (5, then 15 minutes, rising with repeats) and club-wide (a ceiling per
 * hour that closes `/docs` to every PIN for the rest of the hour and logs a warning). Owner decision (2 Oct 2026): these
 * limits are `/docs`'s OWN — a wrong PIN here never counts against the counter's per-sede throttle, so nobody on the
 * internet can lock the club's tablets out from this page. Every failure says the same thing.
 */
final class DocsAccess
{
    public const COOKIE = 'csc_guides';

    public const DAYS = 30;

    /** Wrong PINs from one IP before it is locked out. */
    public const IP_ATTEMPTS = 5;

    /** The IP lockout, rising with each repeat within a day; the last value repeats. */
    public const IP_LOCKOUTS = [900, 3600, 14400]; // 15 min → 1 h → 4 h

    /** Wrong `/docs` PINs per hour, from anywhere, before `/docs` refuses every PIN for the rest of that hour. */
    public const CLUB_CEILING = 30;

    private const ATTEMPT_TTL = 900;

    private const STRIKE_TTL = 86400;

    public static function enabled(): bool
    {
        return (bool) Settings::get('guides_docs_enabled', true);
    }

    /** The person whose guides this phone may read, or null. */
    public static function reader(Request $request): ?User
    {
        if (! self::enabled()) {
            return null;
        }

        $payload = json_decode((string) $request->cookie(self::COOKIE), true);
        if (! is_array($payload) || ! is_string($payload['u'] ?? null) || ! is_string($payload['p'] ?? null) || (int) ($payload['e'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $user = User::query()->whereKey($payload['u'])->where('active', true)->first();

        return $user !== null && hash_equals(self::fingerprint($user), $payload['p']) ? $user : null;
    }

    /**
     * Check a PIN typed on `/docs`. The person on success; null on any failure — wrong, unknown, inactive, ambiguous or
     * locked out alike, so the answer never says which.
     */
    public static function attempt(Request $request, #[SensitiveParameter] string $pin): ?User
    {
        $ip = (string) $request->ip();
        if (self::lockedOut($ip)) {
            return null;
        }

        // Reserve the attempt before checking (296's A·6): parallel guesses cannot all slip under the limit.
        // Fails CLOSED: with the limiter's store down, no PIN is checked (a public URL must not become unlimited guessing).
        // Phones already signed in keep reading — their cookie needs no cache.
        $attempt = self::safely(fn (): int => self::bump('ip:'.$ip.':attempts', self::ATTEMPT_TTL), null);
        if ($attempt === null) {
            return null;
        }
        if ($attempt > self::IP_ATTEMPTS) {
            self::lockIp($ip);

            return null;
        }

        $user = preg_match('/^\d{4,8}$/', $pin) === 1
            ? User::query()->where('pin_lookup', PinLookup::for($pin))->where('active', true)->first()
            : null;

        if ($user !== null) {
            // A right PIN gives the attempt back but clears nothing (270): it must not reset someone else's guesses.
            self::safely(fn () => self::cache()->decrement('docs-pin:ip:'.$ip.':attempts'), null);
            (new RecordAuditLog)->handle('guides.docs_signed_in', $user, null, ['ip' => $ip]);

            return $user;
        }

        if ($attempt >= self::IP_ATTEMPTS) {
            self::lockIp($ip);
        }
        $club = self::safely(fn (): int => self::bump('club:wrong', 3600), 0);
        if ($club === self::CLUB_CEILING) {
            self::safely(fn () => self::cache()->put('docs-pin:club:lockout', now()->getTimestamp() + 3600, 3600), null);
            Log::warning('Guides /docs: club-wide wrong-PIN ceiling reached; refusing every PIN for an hour.', ['ip' => $ip]);
            (new RecordAuditLog)->handle('guides.docs_locked_out', null, null, ['scope' => 'club', 'ip' => $ip]);
        }

        return null;
    }

    public static function lockedOut(string $ip): bool
    {
        return (bool) self::safely(fn (): bool => self::cache()->has('docs-pin:ip:'.$ip.':lockout') || self::cache()->has('docs-pin:club:lockout'), false);
    }

    public static function issue(User $user): Cookie
    {
        return cookie(self::COOKIE, (string) json_encode([
            'u' => (string) $user->getKey(),
            'p' => self::fingerprint($user),
            'e' => now()->addDays(self::DAYS)->getTimestamp(),
        ]), self::DAYS * 24 * 60, null, null, null, true, false, 'lax');
    }

    public static function forget(): Cookie
    {
        return cookie()->forget(self::COOKIE);
    }

    /** Changes whenever the PIN does — so a new PIN ends every phone signed in with the old one. */
    private static function fingerprint(User $user): string
    {
        return hash_hmac('sha256', 'guides|'.$user->getKey().'|'.(string) $user->getRawOriginal('pin_lookup'), (string) config('app.key'));
    }

    private static function lockIp(string $ip): void
    {
        self::safely(function () use ($ip): void {
            $strikes = self::bump('ip:'.$ip.':strikes', self::STRIKE_TTL);
            $window = self::IP_LOCKOUTS[min($strikes, count(self::IP_LOCKOUTS)) - 1];
            self::cache()->put('docs-pin:ip:'.$ip.':lockout', now()->getTimestamp() + $window, $window);
            self::cache()->forget('docs-pin:ip:'.$ip.':attempts');
        }, null);
        (new RecordAuditLog)->handle('guides.docs_locked_out', null, null, ['scope' => 'ip', 'ip' => $ip]);
    }

    private static function bump(string $key, int $ttl): int
    {
        self::cache()->add('docs-pin:'.$key, 0, $ttl);

        return (int) self::cache()->increment('docs-pin:'.$key);
    }

    private static function cache(): Repository
    {
        return Cache::store(config('cache.limiter')); // off Redis, like the counter's PIN limiter (344)
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  T  $fallback
     * @return T
     */
    private static function safely(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
