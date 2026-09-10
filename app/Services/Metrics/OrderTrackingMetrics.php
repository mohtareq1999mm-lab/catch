<?php

namespace App\Services\Metrics;

use Illuminate\Support\Facades\Cache;

class OrderTrackingMetrics
{
    /**
     * Increment status change counter
     */
    public static function incrementStatusChange(string $oldStatus, string $newStatus): void
    {
        $key = "metrics:order_status_change:{$oldStatus}_to_{$newStatus}";
        try {
            Cache::increment($key);
        } catch (\Throwable $e) {
            // fallback for array driver
            $current = (int) Cache::get($key, 0);
            Cache::put($key, $current + 1);
        }
    }

    /**
     * Record tracking access
     */
    public static function incrementTrackingAccess(string $accessType): void
    {
        $key = "metrics:order_tracking_access:{$accessType}";
        try {
            Cache::increment($key);
        } catch (\Throwable $e) {
            $current = (int) Cache::get($key, 0);
            Cache::put($key, $current + 1);
        }
    }

    /**
     * Record payment verification result
     */
    public static function incrementPaymentVerification(string $result): void
    {
        $key = "metrics:payment_verification:{$result}";
        try {
            Cache::increment($key);
        } catch (\Throwable $e) {
            $current = (int) Cache::get($key, 0);
            Cache::put($key, $current + 1);
        }
    }

    /**
     * Get all metrics (for monitoring dashboard)
     */
    public static function getAllMetrics(): array
    {
        // For array/redis, we cannot list keys reliably; return empty if not supported
        try {
            if (method_exists(Cache::getStore(), 'getRedis')) {
                $redis = Cache::getRedis();
                $prefix = config('cache.prefix') ? config('cache.prefix') . ':' : '';
                $keys = $redis->keys($prefix . 'metrics:*');
                $metrics = [];
                foreach ($keys as $key) {
                    $cleanKey = str_replace($prefix, '', $key);
                    $metrics[$cleanKey] = Cache::get($cleanKey, 0);
                }
                return $metrics;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return [];
    }

    /**
     * Reset metrics (daily cron)
     */
    public static function reset(): void
    {
        try {
            if (method_exists(Cache::getStore(), 'getRedis')) {
                $redis = Cache::getRedis();
                $prefix = config('cache.prefix') ? config('cache.prefix') . ':' : '';
                $keys = $redis->keys($prefix . 'metrics:*');
                foreach ($keys as $key) {
                    $cleanKey = str_replace($prefix, '', $key);
                    Cache::forget($cleanKey);
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
