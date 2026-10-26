<?php

namespace App\Traits;

use Closure;
use Illuminate\Support\Facades\Cache;

trait HasCache
{
    /**
     * Prefix for the per-tag key registry used by stores without native tag
     * support (file/database/array). Redis/memcached never touch it.
     */
    private const TAG_REGISTRY = 'cache-tags:';

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
            // Store has no native tag support: emulate tags with a per-tag key
            // registry. Tracking is wrapped INSIDE the miss-only callback, so
            // cache hits pay zero registry reads/writes.
            return Cache::remember(
                $tag . ':' . $key,
                $ttl,
                $this->withTagTracking($tag, $key, $callback),
            );
        }
    }

    /**
     * Wrap a value callback so the key is registered in the per-tag registry
     * only when the value is actually rebuilt (cache miss) — and only written
     * once per key, not on subsequent misses of an already-tracked key.
     */
    private function withTagTracking(string $tag, string $key, Closure $callback): Closure
    {
        return function () use ($tag, $key, $callback) {
            $cacheKey = $tag . ':' . $key;
            $registryKey = self::TAG_REGISTRY . $tag;

            // Track every key written under this tag so flushTag() below can
            // still invalidate them on stores without tag support. The
            // registry is best-effort (read/modify/write, not atomic): worst
            // case one key survives a flush until its own TTL expires.
            $tracked = Cache::get($registryKey, []);

            if (!in_array($cacheKey, $tracked, true)) {
                $tracked[] = $cacheKey;

                // The registry must outlive every key it tracks.
                Cache::put($registryKey, $tracked, now()->addDays(90));
            }

            return $callback();
        };
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
            // cache). Drop every key tracked under this tag instead of silently
            // succeeding while leaving the entries live — that gap used to serve
            // stale catalog lists until natural TTL expiry even after Eloquent
            // writes through observers.
            $registryKey = self::TAG_REGISTRY . $tag;

            foreach (Cache::get($registryKey, []) as $cacheKey) {
                Cache::forget($cacheKey);
            }

            Cache::forget($registryKey);

            return true;
        }
    }
}