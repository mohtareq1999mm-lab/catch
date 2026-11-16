<?php

namespace Tests\Feature\Guest;

/**
 * Guest route behaviour under the contract (spec #43, tickets #44-#46).
 *
 * The metadata matrix lives in GuestRouteContractTest (no DB); these two
 * checks need the real request pipeline and fixtures.
 */
class GuestRouteBehaviourTest extends GuestCheckoutTestCase
{
    public function test_guest_responses_are_not_cacheable(): void
    {
        $this->asGuest()->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private');
    }

    public function test_guest_routes_do_not_break_when_rate_limiting_is_enabled(): void
    {
        config(['app.disable_rate_limiting' => false]);

        // A missing named limiter throws inside ThrottleRequests -> HTTP 500.
        $this->getJson(self::PREFIX . '/cart')->assertStatus(200);
    }
}
