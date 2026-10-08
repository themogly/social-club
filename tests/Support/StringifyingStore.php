<?php

namespace Tests\Support;

use Illuminate\Cache\ArrayStore;

/**
 * Prompt 369 — an array store that hands numbers back as STRINGS, as Laravel's Redis store does (it stores a number raw
 * and returns what Redis returns: a string). The array store the suite uses keeps the PHP int, so `is_int(Cache::get(…))`
 * passed every test and failed in production («Token de Telegram rechazado» never showed). Use this wherever a number is
 * read back from the cache.
 */
class StringifyingStore extends ArrayStore
{
    public function get($key)
    {
        $value = parent::get($key);

        return is_int($value) || is_float($value) ? (string) $value : $value;
    }
}
