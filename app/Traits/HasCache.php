<?php

namespace App\Traits;

use Closure;
use Illuminate\Support\Facades\Cache;

trait HasCache
{
    /**
     * Get data from cache or execute the callback and cache the result.
     *
     * When a Closure is provided, it is only executed on a cache miss so the
     * cache actually avoids the underlying work.
     */
    protected function remember(
        string $tag,
        string $key,
        mixed $data,
        $ttl = null,
    ): mixed {
        $ttl ??= now()->addHours(4);

        return Cache::tags([$tag])->remember($key, $ttl, $data instanceof Closure ? $data : fn() => $data);
    }

    /**
     * Remove one or more cache keys.
     */
    protected function forget(string|array $keys): void
    {
        foreach ((array) $keys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Clear all cache.
     */
    protected function flush(): bool
    {
        return Cache::flush();
    }

    protected function flushTag(string $tag): bool
    {
        return Cache::tags([$tag])->flush();
    }
}