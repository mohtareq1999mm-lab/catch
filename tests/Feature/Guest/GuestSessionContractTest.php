<?php

namespace Tests\Feature\Guest;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guest session resolution contract — router seam.
 *
 * Every guest surface that needs a VERIFIED EXISTING session carries the
 * `guest.session` middleware, so the 401 lives at one seam and no controller
 * re-proves the credential. Session creation, idempotent revocation and the
 * public one-way token paths are exempt by design.
 */
class GuestSessionContractTest extends TestCase
{
    /** Routes that require a verified existing Guest Identity. */
    private const SESSION_REQUIRED = [
        'POST api/v1/general/guest/cart/items',
        'PUT api/v1/general/guest/cart/items',
        'DELETE api/v1/general/guest/cart/items/{itemId}',
        'DELETE api/v1/general/guest/cart',
        'POST api/v1/general/guest/checkout',
        'GET api/v1/general/guest/orders/{orderId}',
        'POST api/v1/general/guest/orders/{orderId}/payment',
        'POST api/v1/general/guest/orders/{orderId}/access-tokens',
        'POST api/v1/general/guest/orders/{orderId}/resend-tracking-link',
    ];

    /** Routes that must resolve the credential themselves (lazy create, idempotent revoke, safe empty read, one-way tokens). */
    private const SESSION_EXEMPT = [
        'GET api/v1/general/guest/cart',
        'POST api/v1/general/guest/session',
        'DELETE api/v1/general/guest/session',
        'POST api/v1/general/guest/mobile/session',
        'POST api/v1/general/guest/mobile/session/rotate',
        'DELETE api/v1/general/guest/mobile/session',
        'POST api/v1/general/guest/orders/track',
        'POST api/v1/general/guest/orders/cancel',
        'POST api/v1/general/guest/orders/cancellation-otp',
    ];

    /** Mutating cart surfaces: a successful response re-queues the cookie transport at the seam. */
    private const COOKIE_REFRESHED = [
        'POST api/v1/general/guest/cart/items',
        'PUT api/v1/general/guest/cart/items',
        'DELETE api/v1/general/guest/cart/items/{itemId}',
        'DELETE api/v1/general/guest/cart',
    ];

    /** Surfaces that never re-queue the cookie (reads, non-cart mutations, one-way tokens). */
    private const COOKIE_NOT_REFRESHED = [
        'GET api/v1/general/guest/cart',
        'POST api/v1/general/guest/checkout',
        'GET api/v1/general/guest/orders/{orderId}',
        'POST api/v1/general/guest/orders/{orderId}/payment',
        'POST api/v1/general/guest/orders/{orderId}/access-tokens',
        'POST api/v1/general/guest/orders/{orderId}/resend-tracking-link',
        'POST api/v1/general/guest/session',
        'POST api/v1/general/guest/mobile/session',
        'POST api/v1/general/guest/orders/track',
        'POST api/v1/general/guest/orders/cancel',
        'POST api/v1/general/guest/orders/cancellation-otp',
    ];

    /** @return array<string, \Illuminate\Routing\Route> */
    private function guestRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/general/guest/')) {
                $routes[$route->methods()[0] . ' ' . $route->uri()] = $route;
            }
        }

        return $routes;
    }

    public function test_session_required_surfaces_carry_guest_session_middleware(): void
    {
        $routes = $this->guestRoutes();
        $this->assertNotEmpty($routes);

        foreach (self::SESSION_REQUIRED as $key) {
            $this->assertArrayHasKey($key, $routes, "Route missing from the guest surface: {$key}");
            $this->assertContains(
                'guest.session',
                $routes[$key]->middleware(),
                "Missing guest.session middleware: {$key}"
            );
        }
    }

    public function test_session_exempt_surfaces_do_not_carry_guest_session_middleware(): void
    {
        $routes = $this->guestRoutes();

        foreach (self::SESSION_EXEMPT as $key) {
            $this->assertArrayHasKey($key, $routes, "Route missing from the guest surface: {$key}");
            $this->assertNotContains(
                'guest.session',
                $routes[$key]->middleware(),
                "Unexpected guest.session middleware: {$key}"
            );
        }
    }

    public function test_cart_mutations_requeue_the_cookie_transport_at_the_seam(): void
    {
        $routes = $this->guestRoutes();

        foreach (self::COOKIE_REFRESHED as $key) {
            $this->assertArrayHasKey($key, $routes, "Route missing from the guest surface: {$key}");
            $this->assertContains(
                'guest.cookie_refresh',
                $routes[$key]->middleware(),
                "Missing guest.cookie_refresh middleware: {$key}"
            );
        }

        foreach (self::COOKIE_NOT_REFRESHED as $key) {
            $this->assertArrayHasKey($key, $routes, "Route missing from the guest surface: {$key}");
            $this->assertNotContains(
                'guest.cookie_refresh',
                $routes[$key]->middleware(),
                "Unexpected guest.cookie_refresh middleware: {$key}"
            );
        }
    }
}
