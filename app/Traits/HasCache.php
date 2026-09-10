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

        $callback = $data instanceof Closure ? $data : fn() => $data;

        // Preferred path for redis/memcached etc. — fallback for file/database/array
        // which throw BadMethodCallException during artisan db:seed.
        try {
            return Cache::tags([$tag])->remember($key, $ttl, $callback);
        } catch (\BadMethodCallException) {
            return Cache::remember($tag . ':' . $key, $ttl, $callback);
        }
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
        try {
            return Cache::tags([$tag])->flush();
        } catch (\BadMethodCallException) {
            // Store does not support tagging (used during db:seed with file/database
            // cache). Silently succeed so seeders/observers do not throw.
            // We intentionally do NOT flush the entire cache here to avoid wiping
            // unrelated keys when tagging is unavailable; the prefixed keys from
            // remember() will expire via TTL.
            return true;
        }
    }
}