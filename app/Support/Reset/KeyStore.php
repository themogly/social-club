<?php

namespace App\Support\Reset;

/** Prompt 304 — the two Redis operations the pre-launch reset needs, on RAW (unprefixed) key names. */
interface KeyStore
{
    /** @return list<string> every key starting with `$prefix` */
    public function keysStartingWith(string $prefix): array;

    /** @param  list<string>  $keys */
    public function delete(array $keys): int;
}
