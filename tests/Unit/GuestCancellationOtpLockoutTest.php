<?php

namespace Tests\Unit;

use App\Services\Guest\GuestCancellationOtpLockout;
use App\Services\Guest\GuestOrderTokenService;
use Tests\TestCase;

/**
 * The lockout's cache-key policy (design D8): failed code attempts are
 * counted per Cancellation Token HASH — the same keyed HMAC digest the
 * persistence layer uses — and the raw credential never lands in a
 * cache key.
 */
class GuestCancellationOtpLockoutTest extends TestCase
{
    private function rawToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @test */
    public function the_lockout_key_uses_the_tokens_hashed_credential_never_the_raw_value(): void
    {
        $raw = $this->rawToken();
        $lockout = app(GuestCancellationOtpLockout::class);

        $key = $lockout->keyFor($raw);

        $this->assertStringStartsWith('guest-cancel-otp:lockout:', $key);
        $this->assertStringNotContainsString($raw, $key, 'The raw token must never be a cache key.');
        $this->assertStringContainsString(
            app(GuestOrderTokenService::class)->hash($raw),
            $key,
            "The key must be derived from the token's keyed hash."
        );
    }

    /** @test */
    public function distinct_tokens_map_to_distinct_lockout_keys(): void
    {
        $lockout = app(GuestCancellationOtpLockout::class);

        $this->assertNotSame(
            $lockout->keyFor($this->rawToken()),
            $lockout->keyFor($this->rawToken())
        );
    }
}
