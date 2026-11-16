<?php

namespace App\Http\Middleware;

use Illuminate\Cache\RateLimiter;

/**
 * The `throttle` alias: a BYPASSABLE policy.
 *
 * If rate limiting is disabled via env / config (default: disabled), pass
 * through immediately. Otherwise delegate to the shared enforcement contract.
 * The argument contract (named limiter vs numeric) lives in PolicyThrottle.
 */
class BypassThrottle extends PolicyThrottle
{
    public function __construct(RateLimiter $limiter)
    {
        parent::__construct($limiter, failClosed: false);
    }
}
