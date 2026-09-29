<?php

namespace Tests\Feature\System;

use App\ViewModels\SystemHealth;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 307 — live showed the Caché card as *No accesible* with Redis answering PONG. Laravel's `RedisStore` writes a
 * numeric value unserialised, so the integer probe came back as the STRING "1" and `=== 1` was always false on Redis.
 * The suite ran on the array store, where the integer survives: a false green. The double below returns numeric values
 * as strings exactly as `RedisStore::unserialize()` does; the last test uses the real Redis when one is running.
 */
class CacheHealthProbeTest extends TestCase
{
    public function test_a_store_that_hands_numbers_back_as_strings_is_reachable(): void
    {
        $this->useStore('redis-like', new class extends ArrayStore
        {
            public function get($key): mixed
            {
                $value = parent::get($key);

                return is_int($value) || is_float($value) ? (string) $value : $value;
            }
        });

        $this->assertTrue((new SystemHealth)->cache()['reachable']);
    }

    public function test_a_store_that_throws_is_unreachable(): void
    {
        $this->useStore('down', new class extends ArrayStore
        {
            public function put($key, $value, $seconds): bool
            {
                throw new RuntimeException('Connection refused');
            }
        });

        $snapshot = (new SystemHealth)->cache();
        $this->assertSame(['down', false], [$snapshot['store'], $snapshot['reachable']]);
    }

    public function test_the_probe_key_is_removed_after_the_check(): void
    {
        $this->assertTrue((new SystemHealth)->cache()['reachable']);

        $this->assertNull(Cache::store('array')->get('csc.health.probe'));
    }

    public function test_the_real_redis_store_is_reachable_when_redis_is_running(): void
    {
        try {
            Redis::connection('cache')->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('No Redis here; the store double above covers the string round trip.');
        }
        config(['cache.default' => 'redis']);

        $this->assertTrue((new SystemHealth)->cache()['reachable']);
        $this->assertNull(Cache::store('redis')->get('csc.health.probe'));
    }

    private function useStore(string $name, ArrayStore $store): void
    {
        Cache::extend($name, fn () => Cache::repository($store));
        config(["cache.stores.$name" => ['driver' => $name], 'cache.default' => $name]);
    }
}
