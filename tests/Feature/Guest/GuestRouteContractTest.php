<?php

namespace Tests\Feature\Guest;

use App\Enums\GuestSurface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guest route security contract — router seam (spec #43, tickets #44/#46/#52).
 *
 * Reads the router's declared middleware (what Laravel will actually run) and
 * pins the approved matrix: Origin validation and a limiter on every guest
 * route, and CSRF exactly where a cookie credential can be forged — never on
 * the mobile Bearer surface or the public token paths.
 *
 * Since #52 the matrix is DERIVED from the GuestSurface enum: each guest
 * route declares exactly one surface token and the guest prefix group owned
 * the Origin guard once. These tests pin that derivation, so a guest route
 * can be added as one coordinated edit (route + surface case) and any
 * hand-rolled or token-less guest route fails here.
 *
 * No database access: this suite is the fast guard for route edits.
 */
class GuestRouteContractTest extends TestCase
{
    /**
     * Route name → the ONE surface it declares. A guest route missing here
     * (or a surface with no route) fails the contract.
     */
    private const SURFACES = [
        'api.guest.session' => GuestSurface::SESSION_OPEN,
        'api.guest.session.revoke' => GuestSurface::SESSION_REVOKE,
        'api.guest.mobile.session' => GuestSurface::MOBILE_SESSION,
        'api.guest.mobile.session.rotate' => GuestSurface::MOBILE_SESSION,
        'api.guest.mobile.session.revoke' => GuestSurface::MOBILE_SESSION,
        'api.guest.cart.show' => GuestSurface::CART_READ,
        'api.guest.cart.store' => GuestSurface::CART_WRITE,
        'api.guest.cart.update' => GuestSurface::CART_WRITE,
        'api.guest.cart.item.destroy' => GuestSurface::CART_WRITE,
        'api.guest.cart.destroy' => GuestSurface::CART_WRITE,
        'api.guest.orders.track' => GuestSurface::ORDER_TRACK,
        'api.guest.orders.cancel.otp' => GuestSurface::ORDER_CANCEL_OTP,
        'api.guest.orders.cancel' => GuestSurface::ORDER_CANCEL,
        'api.guest.orders.show' => GuestSurface::ORDER_READ,
        'api.guest.orders.payment' => GuestSurface::ORDER_PAYMENT,
        'api.guest.orders.access-tokens' => GuestSurface::ORDER_PAYMENT,
        'api.guest.checkout' => GuestSurface::CHECKOUT,
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
        'POST api/v1/general/guest/orders/cancel/request-otp' => 'guest_cancel_otp',
        'POST api/v1/general/guest/orders/cancel' => 'guest_cancel',
        'GET api/v1/general/guest/orders/{orderId}' => 'guest_cart_read',
        'POST api/v1/general/guest/orders/{orderId}/payment' => 'guest_payment',
        'POST api/v1/general/guest/orders/{orderId}/access-tokens' => 'guest_payment',
        'POST api/v1/general/guest/checkout' => 'guest_checkout',
    ];

    /** @return array<int, \Illuminate\Routing\Route> */
    protected function guestRoutes(): array
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
            $posture = self::SURFACES[$route->getName()]
                ?? abort(500, 'Unmapped guest surface: ' . $route->getName());

            if ($posture->enforcesCsrf()) {
                $this->assertContains(
                    'guest.csrf',
                    $middleware,
                    "CSRF middleware missing on cookie-transport surface: {$route->getName()}"
                );
            } else {
                $this->assertNotContains(
                    'guest.csrf',
                    $middleware,
                    "The CSRF guard must not be mounted on this surface (reads, Bearer transport and public token paths are free): {$route->getName()}"
                );
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

    /**
     * #52: every guest route declares exactly one surface token, and the
     * token↔route map is exhaustive in BOTH directions — a new guest route
     * without a coordinated SURFACES entry fails here, as does a surface
     * that lost its route.
     */
    public function test_every_guest_route_declares_exactly_one_registered_surface_token(): void
    {
        $this->assertCount(
            count(self::SURFACES),
            $this->guestRoutes(),
            'The number of guest routes changed: add the route AND its surface declaration in this test in the same edit.'
        );

        foreach ($this->guestRoutes() as $route) {
            $name = $route->getName();

            $this->assertArrayHasKey(
                $name,
                self::SURFACES,
                "Guest route [{$name}] has no surface declaration — one coordinated edit means adding the route and its GuestSurface entry together."
            );

            $tokens = array_filter(
                $route->middleware(),
                fn ($m) => is_string($m) && str_starts_with($m, 'guest.throttle:')
            );

            $this->assertCount(
                1,
                $tokens,
                "Guest route [{$name}] must declare exactly one surface token (the limiter it already names)."
            );

            $expected = 'guest.throttle:' . self::SURFACES[$name]->limiter();
            $this->assertContains(
                $expected,
                $route->middleware(),
                "The surface limiter is part of the route contract: [{$name}] resolved to {$expected}."
            );
        }
    }

    /**
     * #52: the full stack of every guest route is the surface derivation —
     * the group-owned Origin guard exactly once, then exactly what
     * GuestSurface::middleware() derives for the route's surface. Fourteen
     * hand-written copies in routes/api.php cannot drift anymore.
     */
    public function test_guest_stack_is_derived_from_the_surface_registry(): void
    {
        foreach ($this->guestRoutes() as $route) {
            $middleware = $route->middleware();
            $name = $route->getName();

            $originPositions = array_keys($middleware, 'guest.origin', true);

            $this->assertCount(
                1,
                $originPositions,
                "The Origin guard belongs on the guest prefix group exactly once, never per route: [{$name}]."
            );

            $tail = array_slice($middleware, $originPositions[0] + 1);

            $this->assertSame(
                self::SURFACES[$name]->middleware(),
                $tail,
                "The middleware after Origin must be exactly the GuestSurface derivation (route: {$name})."
            );
        }
    }
}
