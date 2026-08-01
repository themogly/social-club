<?php

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store whose every operation throws — standing in for an unreachable Redis in tests (prompt 124).
 * The message carries the Redis port so the app's degraded-render handler recognises it as a Redis outage.
 */
class ExplodingStore implements Store
{
    private function boom(): never
    {
        throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
    }

    public function get($key)
    {
        $this->boom();
    }

    public function many(array $keys)
    {
        $this->boom();
    }

    public function put($key, $value, $seconds)
    {
        $this->boom();
    }

    public function putMany(array $values, $seconds)
    {
        $this->boom();
    }

    public function increment($key, $value = 1)
    {
        $this->boom();
    }

    public function decrement($key, $value = 1)
    {
        $this->boom();
    }

    public function forever($key, $value)
    {
        $this->boom();
    }

    public function touch($key, $seconds)
    {
        $this->boom();
    }

    public function forget($key)
    {
        $this->boom();
    }

    public function flush()
    {
        $this->boom();
    }

    public function getPrefix()
    {
        return '';
    }
}
