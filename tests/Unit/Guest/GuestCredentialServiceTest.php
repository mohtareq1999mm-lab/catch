<?php

namespace Tests\Unit\Guest;

use App\Enums\GuestCredentialDomain;
use App\Services\Guest\GuestCredentialService;
use Tests\TestCase;

/**
 * Guest credential primitives (#103): one pattern, one mint, one HMAC with
 * explicit domain separation.
 */
class GuestCredentialServiceTest extends TestCase
{
    private GuestCredentialService $credentials;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentials = app(GuestCredentialService::class);
    }

    public function test_same_raw_value_hashes_differently_per_domain(): void
    {
        $raw = $this->credentials->mint();

        $legacy = $this->credentials->hash($raw);
        $mobile = $this->credentials->hash($raw, GuestCredentialDomain::Mobile);
        $csrf = $this->credentials->hash($raw, GuestCredentialDomain::Csrf);

        $this->assertNotSame($legacy, $mobile);
        $this->assertNotSame($legacy, $csrf);
        $this->assertNotSame($mobile, $csrf);
        $this->assertSame(64, strlen($legacy), 'HMAC-SHA256 is 64 hex characters.');
    }

    public function test_hash_is_deterministic_within_a_domain(): void
    {
        $raw = $this->credentials->mint();

        $this->assertSame(
            $this->credentials->hash($raw, GuestCredentialDomain::Mobile),
            $this->credentials->hash($raw, GuestCredentialDomain::Mobile)
        );
        $this->assertSame($this->credentials->hash($raw), $this->credentials->hash($raw));
    }

    public function test_pattern_accepts_the_boundary_lengths(): void
    {
        $this->assertTrue($this->credentials->isValidFormat(str_repeat('a', 20)));
        $this->assertTrue($this->credentials->isValidFormat(str_repeat('a', 120)));
        $this->assertTrue($this->credentials->isValidFormat(str_repeat('A', 10) . str_repeat('0', 5) . '_-' . str_repeat('z', 3)));
    }

    public function test_pattern_rejects_out_of_bound_and_malformed_values(): void
    {
        $this->assertFalse($this->credentials->isValidFormat(str_repeat('a', 19)));
        $this->assertFalse($this->credentials->isValidFormat(str_repeat('a', 121)));
        $this->assertFalse($this->credentials->isValidFormat(null));
        $this->assertFalse($this->credentials->isValidFormat(''));
        $this->assertFalse($this->credentials->isValidFormat(str_repeat('a', 19) . '+'));
        $this->assertFalse($this->credentials->isValidFormat('id|sanctum-shaped-token-value'));
        $this->assertFalse($this->credentials->isValidFormat(str_repeat('a b', 10)));
    }

    public function test_minted_tokens_always_match_the_pattern_and_are_unique(): void
    {
        $seen = [];

        for ($i = 0; $i < 100; $i++) {
            $raw = $this->credentials->mint();

            $this->assertTrue($this->credentials->isValidFormat($raw), $raw);
            $this->assertArrayNotHasKey($raw, $seen, 'Minted tokens must never repeat.');
            $seen[$raw] = true;
        }
    }

    public function test_claim_retirement_clears_both_order_credentials(): void
    {
        $columns = $this->credentials->claimRetirement();

        $this->assertNull($columns['guest_cancellation_token_hash']);
        $this->assertNull($columns['guest_tracking_token_hash']);
        $this->assertNotNull($columns['guest_cancellation_token_used_at']);
        $this->assertNotNull($columns['guest_tracking_revoked_at']);
    }

    public function test_tracking_and_mobile_revocations_clear_their_credentials(): void
    {
        $tracking = $this->credentials->trackingRevocation();
        $this->assertNull($tracking['guest_tracking_token_hash']);
        $this->assertNotNull($tracking['guest_tracking_revoked_at']);

        $mobile = $this->credentials->mobileRevocation();
        $this->assertNull($mobile['mobile_token_hash']);
        $this->assertNotNull($mobile['revoked_at']);
    }
}
