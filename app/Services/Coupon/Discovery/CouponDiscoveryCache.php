<?php

namespace App\Services\Coupon\Discovery;

use App\Enums\FrontendResource;
use Illuminate\Support\Facades\Cache;

/**
 * Targeted invalidation for customer coupon discovery caches.
 *
 * Two mechanisms, both scoped to coupon discovery (never the whole app):
 * - Version counter: embedded in `available` keys and index keys, so a bump
 *   retires every variant on ANY cache store (including stores without tag
 *   support, where keys cannot be enumerated for deletion).
 * - Tag flush: promptly drops tagged index entries where supported instead
 *   of orphaning them until TTL.
 *
 * Call invalidate() after any mutation that changes customer-visible coupon
 * state (coupon/targeting/assignment/claim/usage writes). Reads stay cheap:
 * one cached integer per listing request.
 */
class CouponDiscoveryCache
{
    public const VERSION_KEY = 'coupon:discovery:version';

    public static function version(): int
    {
        try {
            return (int) Cache::get(self::VERSION_KEY, 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function invalidate(): void
    {
        try {
            Cache::increment(self::VERSION_KEY);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            Cache::tags([FrontendResource::COUPONS->value])->flush();
        } catch (\BadMethodCallException) {
            // Store lacks tagging; version-keyed keys already bust.
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
