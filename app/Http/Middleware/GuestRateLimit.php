<?php

namespace App\Http\Middleware;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Guest-surface throttle: a FAIL-CLOSED policy.
 *
 * Deliberately NOT the project-wide bypassable `throttle` alias: public guest
 * endpoints must remain protected even when global throttling is disabled.
 * Any limiter-backend failure fails CLOSED with a safe retry response.
 *
 * The named-limiter argument contract lives in PolicyThrottle.
 */
class GuestRateLimit extends PolicyThrottle
{
    public function __construct(RateLimiter $limiter)
    {
        parent::__construct($limiter, failClosed: true);
    }

    protected function enforce($request, \Closure $next, ...$args)
    {
        try {
            return parent::enforce($request, $next, ...$args);
        } catch (ThrottleRequestsException $e) {
            // Keep the limiter's Retry-After/X-RateLimit headers and a stable
            // JSON contract (the generic Handler render drops them).
            $headers = $e->getHeaders();

            return response()->json([
                'status' => 429,
                'message' => __('guest_checkout.rate_limited'),
                'success' => false,
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers);
        } catch (HttpException $e) {
            // Legitimate 429 (or other HTTP) from the limiter: keep it.
            throw $e;
        } catch (\Throwable $e) {
            // Cache/limiter backend unavailable: fail closed, never bypass.
            report($e);

            return response()->json([
                'status' => 429,
                'message' => __('guest_checkout.rate_limited'),
                'success' => false,
                'retry_after' => 60,
            ], 429)->header('Retry-After', '60');
        }
    }
}
