<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Canonical cached-reference-data gateway. THE reference for the caching rule:
 *
 *  - caches PLAIN ARRAYS / primitives only — NEVER Eloquent objects (modern
 *    Laravel refuses to unserialize objects from cache);
 *  - every read goes through an accessor with a sensible default fallback, so a
 *    stale or missing entry written by older code degrades gracefully instead of
 *    throwing (which in queued mail/jobs would fail silently);
 *  - transactional/live data (takings, stock, balances, limits) is NEVER cached
 *    here — it is always queried live;
 *  - caches are busted explicitly on write (model observers in real features).
 *
 * Prompt 03 builds the real Settings store on exactly this shape (source becomes
 * the settings table instead of the placeholder below).
 */
class SiteContent
{
    private const CACHE_KEY = 'site_content';

    /**
     * The whole cached array (primitives only).
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => self::load());
    }

    /**
     * Read one value with a default fallback — never a throw on a missing/stale key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return data_get(self::all(), $key, $default);
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Placeholder source — primitives only. Replaced by the settings table in
     * prompt 03. Must NEVER return Eloquent models or other objects.
     *
     * @return array<string, mixed>
     */
    private static function load(): array
    {
        return [
            'app_name' => (string) config('app.name'),
            'locale' => (string) config('app.locale'),
            'currency' => 'EUR',
        ];
    }
}
