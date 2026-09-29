<?php

namespace App\Support\Reset;

use Redis;
use RuntimeException;

/**
 * A {@see KeyStore} over a phpredis client with its connection prefix switched OFF for the duration, so SCAN matches and
 * DEL deletes the exact raw key names — never the prefix doubled, never another app's keys.
 */
final class PhpRedisKeyStore implements KeyStore
{
    public function __construct(private mixed $client)
    {
        if (! $client instanceof Redis) {
            throw new RuntimeException('The pre-launch reset flushes Redis through phpredis (REDIS_CLIENT=phpredis).');
        }
    }

    public function keysStartingWith(string $prefix): array
    {
        return $this->raw(function (Redis $redis) use ($prefix): array {
            $keys = [];
            $iterator = null;
            do {
                $batch = $redis->scan($iterator, addcslashes($prefix, '*?[]\\').'*', 1000);
                if (is_array($batch)) {
                    array_push($keys, ...array_map('strval', $batch));
                }
            } while ($iterator > 0);

            return array_values(array_unique($keys));
        });
    }

    public function delete(array $keys): int
    {
        return $keys === [] ? 0 : $this->raw(fn (Redis $redis): int => array_sum(array_map(
            fn (array $chunk): int => (int) $redis->del($chunk),
            array_chunk($keys, 500),
        )));
    }

    /**
     * @template T
     *
     * @param  callable(Redis): T  $fn
     * @return T
     */
    private function raw(callable $fn): mixed
    {
        /** @var Redis $redis */
        $redis = $this->client;
        $prefix = $redis->getOption(Redis::OPT_PREFIX);
        $redis->setOption(Redis::OPT_PREFIX, '');
        try {
            return $fn($redis);
        } finally {
            $redis->setOption(Redis::OPT_PREFIX, (string) $prefix);
        }
    }
}
