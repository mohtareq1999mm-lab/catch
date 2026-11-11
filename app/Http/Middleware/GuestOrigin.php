<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exact-origin enforcement + no-wildcard guarantee for guest cookie traffic.
 *
 * Rules:
 *  - Requests WITHOUT an Origin header (same-origin navigations, mobile apps,
 *    server-to-server) are unaffected.
 *  - SAME-ORIGIN requests (Origin === request scheme+host) are always allowed:
 *    the Frontend may be served through a same-site reverse proxy/rewrite, in
 *    which case CORS is not involved at all.
 *  - CROSS-ORIGIN requests must match an EXACT allowed origin
 *    (config('cors.allowed_origins')) or an allowed pattern
 *    (config('cors.allowed_origins_patterns')).
 *  - A wildcard "*" in the CORS config NEVER authorizes a guest route: these
 *    routes are cookie-authenticated and a wildcard is illegal with
 *    credentials.
 *  - Defense in depth: a guest response must never advertise a wildcard, and
 *    it must never echo an allowed origin other than the caller's own Origin.
 *    Any such header is removed (fail closed).
 */
class GuestOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        self::assertAllowed($request);

        $response = $next($request);

        $this->stripUnsafeCorsHeaders($request, $response);

        return $response;
    }

    public static function assertAllowed(Request $request): void
    {
        $origin = $request->headers->get('Origin');

        if (!is_string($origin) || $origin === '') {
            return;
        }

        $origin = rtrim($origin, '/');

        if (self::isSameOrigin($request, $origin)) {
            return;
        }

        $allowed = array_map(fn ($o) => rtrim($o, '/'), (array) config('cors.allowed_origins', []));

        // A wildcard must never authorize a cookie-authenticated guest route.
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

    /**
     * Guest responses must not advertise wildcard access and must not echo an
     * allowed origin that is not the caller's own Origin.
     */
    private function stripUnsafeCorsHeaders(Request $request, Response $response): void
    {
        $allowedOrigin = $response->headers->get('Access-Control-Allow-Origin');
        $requestOrigin = $request->headers->get('Origin');

        if ($allowedOrigin === null) {
            return;
        }

        $unsafe = $allowedOrigin === '*'
            || ($requestOrigin !== null && !hash_equals((string) $allowedOrigin, (string) $requestOrigin));

        if ($unsafe) {
            $response->headers->remove('Access-Control-Allow-Origin');
            $response->headers->remove('Access-Control-Allow-Credentials');
        }
    }

    /**
     * Same-origin (including same-site reverse proxy rewrites): the browser
     * sends the frontend origin, which equals the request's own scheme+host.
     */
    private static function isSameOrigin(Request $request, string $origin): bool
    {
        $requestOrigin = rtrim($request->getSchemeAndHttpHost(), '/');

        return rtrim($origin, '/') === $requestOrigin;
    }
}
