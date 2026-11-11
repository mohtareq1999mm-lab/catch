<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exact-origin allowlist for guest cookie traffic.
 *
 * Credentialed CORS must never accept a wildcard; this is the server-side
 * companion to config/cors.php (preflight alone is not an authorization
 * control). Requests without an Origin header (mobile apps, same-origin
 * navigations, server-to-server) are unaffected.
 */
class GuestOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        self::assertAllowed($request);

        return $next($request);
    }

    public static function assertAllowed(Request $request): void
    {
        $origin = $request->headers->get('Origin');

        if (!is_string($origin) || $origin === '') {
            return;
        }

        $allowed = (array) config('cors.allowed_origins', []);

        if (in_array('*', $allowed, true)) {
            return;
        }

        if (in_array($origin, $allowed, true)) {
            return;
        }

        foreach ((array) config('cors.allowed_origins_patterns', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, $origin) === 1) {
                return;
            }
        }

        abort(403, __('guest_checkout.origin_not_allowed'));
    }
}
