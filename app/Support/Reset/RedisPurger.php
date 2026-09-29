<?php

namespace App\Support\Reset;

use Closure;
use Illuminate\Support\Facades\Redis;

/**
 * Prompt 304 — flush THIS app's Redis keys and nothing else: the cache, the queue and Horizon, by their configured
 * prefixes (`REDIS_PREFIX`, Horizon's prefix). Never FLUSHALL / FLUSHDB — other apps may share the server's Redis.
 * Scans the default and the cache connections (they are different databases).
 */
class RedisPurger
{
    /** @var Closure(string): KeyStore */
    private Closure $store;

    /**
     * @param  (Closure(string): KeyStore)|null  $store
     * @param  list<string>|null  $prefixes
     */
    public function __construct(?Closure $store = null, private ?array $prefixes = null)
    {
        $this->store = $store ?? fn (string $connection): KeyStore => config('database.redis.client') === 'phpredis'
            ? new PhpRedisKeyStore(Redis::connection($connection)->client())
            : PredisKeyStore::forConnection($connection);
    }

    /** @return list<string> */
    public function prefixes(): array
    {
        return array_values(array_filter(array_unique($this->prefixes ?? [
            (string) config('database.redis.options.prefix'),
            (string) config('horizon.prefix'),
        ]), fn (string $prefix): bool => $prefix !== ''));
    }

    /** Before anything is touched: can this app's Redis be reached (and scanned) at all? Throws when it cannot. */
    public function check(): void
    {
        foreach (['default', 'cache'] as $connection) {
            ($this->store)($connection)->keysStartingWith($this->prefixes()[0] ?? 'csc-reset-probe-');
        }
    }

    /** The number of keys deleted. */
    public function purge(): int
    {
        $deleted = 0;
        foreach (['default', 'cache'] as $connection) {
            $store = ($this->store)($connection);
            foreach ($this->prefixes() as $prefix) {
                $deleted += $store->delete($store->keysStartingWith($prefix));
            }
        }

        return $deleted;
    }
}
