<?php

namespace App\Support\Reset;

use Predis\Client;

/**
 * A {@see KeyStore} over a RAW predis client built from the connection's settings WITHOUT the app's key prefix, so SCAN
 * matches and DEL deletes the exact raw key names — never the prefix doubled, never another app's keys. (The project runs
 * predis: `REDIS_CLIENT=predis`, SETUP.md.)
 */
final class PredisKeyStore implements KeyStore
{
    public function __construct(private Client $client) {}

    public static function forConnection(string $connection): self
    {
        $config = (array) config("database.redis.{$connection}");
        $parameters = filled($config['url'] ?? null) ? (string) $config['url'] : array_filter([
            'scheme' => $config['scheme'] ?? 'tcp',
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => (int) ($config['port'] ?? 6379),
            'username' => $config['username'] ?? null,
            'password' => in_array($config['password'] ?? null, [null, '', 'null'], true) ? null : $config['password'],
            'database' => (int) ($config['database'] ?? 0),
        ], fn ($v): bool => $v !== null);

        return new self(new Client($parameters));
    }

    public function keysStartingWith(string $prefix): array
    {
        $keys = [];
        $cursor = '0';
        do {
            [$cursor, $batch] = $this->client->scan($cursor, ['match' => addcslashes($prefix, '*?[]\\').'*', 'count' => 1000]);
            array_push($keys, ...array_map('strval', (array) $batch));
        } while ((string) $cursor !== '0');

        return array_values(array_unique($keys));
    }

    public function delete(array $keys): int
    {
        return array_sum(array_map(fn (array $chunk): int => (int) $this->client->del($chunk), array_chunk($keys, 500)));
    }
}
