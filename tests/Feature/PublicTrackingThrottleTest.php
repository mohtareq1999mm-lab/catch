<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Fail-closed throttle on the PUBLIC order-number fallback tracker (#77, spec #73 F3).
 *
 * The route used the bypassable `throttle` alias with a limiter that only
 * existed in commented-out code: unthrottled in the shipped configuration
 * (the global disable switch defaults to on) and a deny-all latent state the
 * moment rate limiting was enabled. It now uses the fail-closed policy (the
 * same GuestRateLimit implementation the guest surfaces use): enforced even
 * while the global disable switch is on, and a limiter-backend failure
 * answers 429 instead of bypassing.
 *
 * No database access: the burst drives validation-422 responses, so this
 * suite is the fast guard for limiter registration and route wiring. The
 * burst also proves registration by itself: an unregistered limiter name
 * falls through as a zero max-attempts and denies EVERY request, so
 * "first N pass, next one 429" can only happen with a registered limiter.
 */
class PublicTrackingThrottleTest extends TestCase
{
    /** @test */
    public function the_public_tracking_limiter_is_registered(): void
    {
        $this->assertNotNull(
            RateLimiter::limiter('public-tracking'),
            'The public-tracking limiter must be registered: the fallback route resolves it by name.'
        );
    }

    /** @test */
    public function public_fallback_burst_returns_429_with_retry_after_even_while_rate_limiting_is_disabled(): void
    {
        // The shipped default: the global disable switch is ON. The
        // fail-closed policy must still enforce the named limiter — a
        // bypassable implementation fails exactly here.
        config(['app.disable_rate_limiting' => true]);

        // Empty payload: deterministic 422 validation response, no DB.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/general/track-order', [])->assertStatus(422);
        }

        $burst = $this->postJson('/api/v1/general/track-order', []);

        $burst->assertStatus(429);
        $burst->assertHeader('Retry-After');
    }
}
