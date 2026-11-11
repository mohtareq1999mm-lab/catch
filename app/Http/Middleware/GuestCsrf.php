<?php

namespace App\Http\Middleware;

use App\Services\Guest\GuestIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Double-submit CSRF + origin allowlist for cookie-authenticated guest
 * state changes.
 *
 * - A request with NO guest identity cannot be CSRF'd (there is no ambient
 *   credential to ride); the controller may lazily create one.
 * - A request with a guest identity MUST present X-CSRF-Token equal to the
 *   readable guest CSRF cookie AND to the server-derived value.
 * - If an Origin header is present it must be an explicitly allowed frontend
 *   origin (no wildcard with credentials).
 */
class GuestCsrf
{
    public function __construct(private GuestIdentityService $identities)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        GuestOrigin::assertAllowed($request);

        $identity = $this->identities->current($request);
        if (!$identity) {
            // No ambient credential: nothing to forge. The controller creates
            // a fresh identity for this request (if the endpoint allows it).
            return $next($request);
        }

        $cookie = $request->cookie(GuestIdentityService::csrfCookieName());
        $header = $request->header('X-CSRF-Token');

        if (
            !is_string($cookie) || $cookie === ''
            || !is_string($header) || $header === ''
            || !hash_equals($cookie, $header)
            || !hash_equals($this->identities->csrfToken($identity), $cookie)
        ) {
            return response()->json([
                'status' => 419,
                'message' => __('guest_checkout.csrf_failed'),
                'success' => false,
            ], 419);
        }

        return $next($request);
    }
}
