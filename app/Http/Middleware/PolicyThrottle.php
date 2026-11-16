<?php

namespace App\Http\Middleware;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * One throttle module with an explicit bypass policy (Candidate 01).
 *
 * Subclasses choose the policy in their constructor, never per route:
 *  - failClosed  (GuestRateLimit): enforces even when rate limiting is
 *    disabled globally — public guest endpoints must stay protected.
 *  - bypassable  (BypassThrottle): passes through when rate limiting is
 *    disabled via config.
 *
 * The named-limiter argument contract lives here in ONE place: Laravel
 * resolves a named limiter only when parent::handle receives EXACTLY three
 * arguments. Any extra argument makes func_num_args() !== 3 inside the
 * parent, so the limiter NAME is parsed as maxAttempts and silently becomes
 * zero — the bug that once 429'd the whole guest surface.
 */
abstract class PolicyThrottle extends ThrottleRequests
{
    public function __construct(RateLimiter $limiter, private readonly bool $failClosed)
    {
        parent::__construct($limiter);
    }

    public function handle($request, \Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        if (!$this->failClosed && config('app.disable_rate_limiting', true)) {
            return $next($request);
        }

        // A non-numeric string is a limiter NAME: it must reach the parent
        // with exactly three arguments. Numeric values (int or digit string)
        // use the standard max/decay path.
        if (is_string($maxAttempts) && !ctype_digit($maxAttempts)) {
            return $this->enforce($request, $next, $maxAttempts);
        }

        return $this->enforce($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }

    /**
     * Hook so subclasses can wrap enforcement (e.g. the guest fail-closed
     * envelope). The argument contract is already resolved here.
     */
    protected function enforce($request, \Closure $next, ...$args)
    {
        return parent::handle($request, $next, ...$args);
    }
}
