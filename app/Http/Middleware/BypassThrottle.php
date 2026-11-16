<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

class BypassThrottle extends ThrottleRequests
{
    /**
     * Handle an incoming request.
     *
     * If rate limiting is disabled via env / config (default: disabled), pass through immediately.
     * Otherwise, delegate to standard Laravel ThrottleRequests.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  int|string  $maxAttempts
     * @param  float|int  $decayMinutes
     * @param  string  $prefix
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        if (config('app.disable_rate_limiting', true)) {
            return $next($request);
        }

        // Named limiter: it must reach the parent with EXACTLY three
        // arguments (the same contract GuestRateLimit documents). Forwarding
        // the unused defaults makes func_num_args() === 5 inside the parent,
        // so the limiter NAME is parsed as maxAttempts and silently becomes
        // zero — rejecting every request once rate limiting is enabled.
        if (is_string($maxAttempts) && !ctype_digit($maxAttempts)) {
            return parent::handle($request, $next, $maxAttempts);
        }

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }
}
