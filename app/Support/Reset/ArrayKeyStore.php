<?php

namespace App\Support\Reset;

/** An in-memory {@see KeyStore} — what the reset's tests run against (they must never need a Redis server). */
final class ArrayKeyStore implements KeyStore
{
    /** @param  list<string>  $keys */
    public function __construct(private array $keys = []) {}

    public function keysStartingWith(string $prefix): array
    {
        return array_values(array_filter($this->keys, fn (string $key): bool => str_starts_with($key, $prefix)));
    }

    public function delete(array $keys): int
    {
        $before = count($this->keys);
        $this->keys = array_values(array_diff($this->keys, $keys));

        return $before - count($this->keys);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->keys;
    }
}
