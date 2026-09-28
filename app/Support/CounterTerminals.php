<?php

namespace App\Support;

use App\Models\CounterTerminal;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * The registered-counter cookie (prompt 289) — ONE place that reads, issues and forgets it.
 *
 * `csc_terminal` holds "<terminal id>|<64-char token>". Encrypted by EncryptCookies (never excluded), HttpOnly, Secure,
 * SameSite=Lax, valid 400 days (Chrome's maximum) and RE-ISSUED with a fresh 400 days on each hourly `last_seen_at`
 * update, so a tablet in daily use never expires. Only the token's SHA-256 is stored; `hash_equals` compares. A cookie
 * naming a revoked, deleted or unknown terminal, or with a wrong token, is treated as absent (and cleared by the
 * middleware) — the tablet is then an ordinary browser.
 */
class CounterTerminals
{
    public const COOKIE = 'csc_terminal';

    public const LIFETIME_MINUTES = 400 * 24 * 60;

    private const MEMO = 'counter.terminal';

    /** The valid terminal this request carries, or null. Memoised per request. */
    public static function current(?Request $request = null): ?CounterTerminal
    {
        $request ??= request();

        if ($request->attributes->has(self::MEMO)) {
            $memo = $request->attributes->get(self::MEMO);

            return $memo instanceof CounterTerminal ? $memo : null;
        }

        $terminal = self::resolve((string) $request->cookie(self::COOKIE, ''));
        $request->attributes->set(self::MEMO, $terminal ?? false);

        return $terminal;
    }

    /** Does the request carry the cookie at all (valid or not)? */
    public static function presented(?Request $request = null): bool
    {
        return filled(($request ?? request())->cookie(self::COOKIE));
    }

    private static function resolve(string $value): ?CounterTerminal
    {
        if (! str_contains($value, '|')) {
            return null;
        }

        [$id, $token] = explode('|', $value, 2);

        $terminal = CounterTerminal::query()->withoutGlobalScopes()->live()->whereKey($id)->first();

        return $terminal !== null && hash_equals((string) $terminal->token_hash, hash('sha256', $token)) ? $terminal : null;
    }

    public static function cookieValue(CounterTerminal $terminal, string $token): string
    {
        return $terminal->id.'|'.$token;
    }

    public static function issue(CounterTerminal $terminal, string $token): void
    {
        Cookie::queue(self::make(self::cookieValue($terminal, $token)));
    }

    /** Re-issue the cookie the request carried, with a fresh lifetime. */
    public static function renew(Request $request): void
    {
        Cookie::queue(self::make((string) $request->cookie(self::COOKIE)));
    }

    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    private static function make(string $value): SymfonyCookie
    {
        // Secure everywhere but a plain-http LOCAL dev server, which a browser would otherwise never send it back to.
        $secure = ! app()->environment('local') || request()->isSecure();

        return Cookie::make(self::COOKIE, $value, self::LIFETIME_MINUTES, '/', null, $secure, true, false, 'lax');
    }

    /**
     * The sedes the counter may work at: the signed-in person's (as always), or — on a registered tablet with nobody
     * signed in — just the terminal's home sede.
     *
     * @return Collection<int, Location>
     */
    public static function availableSedes(?User $user): Collection
    {
        if ($user !== null) {
            return app(LocationSwitcher::class)->available($user);
        }

        $terminal = self::current();

        return $terminal !== null ? collect(array_filter([$terminal->location])) : collect();
    }
}
