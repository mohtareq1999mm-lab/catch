<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Guest-surface throttle.
 *
 * Deliberately NOT the project-wide BypassThrottle alias: public guest
 * endpoints must remain protected even when global throttling is disabled.
 * Any limiter-backend failure fails CLOSED with a safe retry response.
 */
class GuestRateLimit extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        try {
            // Exactly three arguments: ThrottleRequests only resolves a NAMED
            // limiter when func_num_args() === 3. Passing the unused
            // $decayMinutes/$prefix here would silently degrade the limiter to
            // "maxAttempts = 0" and reject every request.
            return parent::handle($request, $next, $maxAttempts);
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
