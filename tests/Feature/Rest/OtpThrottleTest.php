<?php

declare(strict_types=1);

namespace Tests\Feature\Rest;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Fail-closed throttle on the OTP endpoints (#77, spec #73 F3).
 *
 * `throttle:otp` used the bypassable `throttle` alias with an unregistered
 * limiter: a no-op in the shipped configuration (unbounded OTP sends — SMS
 * cost bombing) and a deny-all latent state once rate limiting was enabled.
 * The OTP routes now use the fail-closed policy (the same GuestRateLimit
 * implementation the guest surfaces use): enforced even while the global
 * disable switch is on; a limiter-backend failure answers 429 instead of
 * bypassing. No per-phone send cap (operator scope decision for #77).
 */
class OtpThrottleTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        // Deterministic gateway: no external credentials involved.
        config([
            'auth.active_otp_gateway' => 'local',
            'auth.local_otp_enabled' => true,
            'auth.local_otp_code' => '123456',
            // Hermetic against the operator environment: the shared .env may
            // ship a MASTER_OTP value (ADR-0003 bypass). The burst posts a
            // code that must match NOTHING — the 429s here must come from
            // the rate limiter, never from an accepted login attempt.
            'auth.master_otp' => null,
        ]);
    }

    /** @test */
    public function the_otp_limiter_is_registered(): void
    {
        $this->assertNotNull(
            RateLimiter::limiter('otp'),
            'The otp limiter must be registered: the OTP routes resolve it by name.'
        );
    }

    /** @test */
    public function otp_login_burst_returns_429_with_retry_after_even_while_rate_limiting_is_disabled(): void
    {
        // The shipped default: the global disable switch is ON. The
        // fail-closed policy must still enforce the named limiter — a
        // bypassable implementation fails exactly here.
        config(['app.disable_rate_limiting' => true]);

        // Wrong code through the controller (400). Unknown phone so nothing
        // is issued and no SMS is sent; the per-phone verify lockout engages
        // at 5 failures, the OTP limiter fires first at 3/min.
        $payload = [
            'phone_number' => '+20999999999',
            'otp_id' => 'local-verification-id',
            'code' => '000000',
        ];

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/otp-login', $payload)->assertStatus(400);
        }

        $burst = $this->postJson('/api/v1/otp-login', $payload);

        $burst->assertStatus(429);
        $burst->assertHeader('Retry-After');
    }
}
