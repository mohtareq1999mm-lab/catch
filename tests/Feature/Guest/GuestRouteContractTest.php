<?php

namespace Tests\Feature\Guest;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guest route security contract — router seam (spec #43, tickets #44/#46).
 *
 * Reads the router's declared middleware (what Laravel will actually run) and
 * pins the approved matrix: Origin validation and a limiter on every guest
 * route, and CSRF exactly where a cookie credential can be forged — never on
 * the mobile Bearer surface or the public token paths.
 *
 * No database access: this suite is the fast guard for route edits.
 */
class GuestRouteContractTest extends TestCase
{
    /** Routes that are CSRF-free by design, keyed by "METHOD uri". */
    private const CSRF_FREE = [
        'POST api/v1/general/guest/session',            // no credential exists yet
        'POST api/v1/general/guest/mobile/session',     // Bearer transport
        'DELETE api/v1/general/guest/mobile/session',   // Bearer transport
        'POST api/v1/general/guest/mobile/session/rotate',
        'POST api/v1/general/guest/orders/track',       // 1-way token in body
        'POST api/v1/general/guest/orders/cancel',
    ];

    /** Expected named limiter per "METHOD uri". */
    private const LIMITERS = [
        'POST api/v1/general/guest/session' => 'guest_session',
        'DELETE api/v1/general/guest/session' => 'guest_session',
        'POST api/v1/general/guest/mobile/session' => 'guest_session',
        'POST api/v1/general/guest/mobile/session/rotate' => 'guest_session',
        'DELETE api/v1/general/guest/mobile/session' => 'guest_session',
        'GET api/v1/general/guest/cart' => 'guest_cart_read',
        'DELETE api/v1/general/guest/cart' => 'guest_cart_write',
        'POST api/v1/general/guest/cart/items' => 'guest_cart_write',
        'PUT api/v1/general/guest/cart/items' => 'guest_cart_write',
        'DELETE api/v1/general/guest/cart/items/{itemId}' => 'guest_cart_write',
        'POST api/v1/general/guest/orders/track' => 'guest_tracking',
        'POST api/v1/general/guest/orders/cancel' => 'guest_cancel',
        'GET api/v1/general/guest/orders/{orderId}' => 'guest_cart_read',
        'POST api/v1/general/guest/orders/{orderId}/payment' => 'guest_payment',
        'POST api/v1/general/guest/orders/{orderId}/access-tokens' => 'guest_payment',
        'POST api/v1/general/guest/checkout' => 'guest_checkout',
    ];

    /** @return array<int, \Illuminate\Routing\Route> */
    private function guestRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn ($route) => str_starts_with($route->uri(), 'api/v1/general/guest/')
        ));
    }

    private function routeKey(\Illuminate\Routing\Route $route): string
    {
        return $route->methods()[0] . ' ' . $route->uri();
    }

    public function test_every_guest_route_validates_origin_and_has_a_limiter(): void
    {
        $routes = $this->guestRoutes();

        $this->assertNotEmpty($routes, 'The guest route group must exist.');

        foreach ($routes as $route) {
            $middleware = $route->middleware();
            $label = $this->routeKey($route);

            $this->assertContains('guest.origin', $middleware, "Missing Origin validation: {$label}");

            $throttles = array_filter(
                $middleware,
                fn ($m) => is_string($m) && str_starts_with($m, 'guest.throttle:')
            );

            $this->assertNotEmpty($throttles, "Missing guest rate limiter: {$label}");
        }
    }

    public function test_csrf_posture_matches_the_credential_transport(): void
    {
        $routes = $this->guestRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $middleware = $route->middleware();
            $key = $this->routeKey($route);
            $method = $route->methods()[0];

            $mustBeFree = in_array($key, self::CSRF_FREE, true)
                || in_array($method, ['GET', 'HEAD'], true);

            if ($mustBeFree) {
                $this->assertNotContains('guest.csrf', $middleware, "Unexpected CSRF middleware: {$key}");
            } else {
                $this->assertContains('guest.csrf', $middleware, "CSRF missing on cookie write: {$key}");
            }
        }
    }

    public function test_limiter_names_are_pinned_per_route(): void
    {
        $seen = [];

        foreach ($this->guestRoutes() as $route) {
            foreach ($route->middleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'guest.throttle:')) {
                    $seen[$this->routeKey($route)][$middleware] = true;
                }
            }
        }

        foreach (self::LIMITERS as $key => $limiter) {
            $this->assertArrayHasKey($key, $seen, "Route not found or unthrottled: {$key}");
            $this->assertArrayHasKey(
                'guest.throttle:' . $limiter,
                $seen[$key],
                "Route {$key} must use limiter {$limiter}"
            );
        }
    }

    public function test_public_api_limiter_is_registered(): void
    {
        $this->assertNotNull(
            app(RateLimiter::class)->limiter('public-api'),
            'The public-api limiter must be registered (BypassThrottle depends on it).'
        );
    }
}
