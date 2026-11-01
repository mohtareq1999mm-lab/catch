<?php

namespace Tests\Unit;

use App\Services\Payment\TabbyWebhookVerifier;
use Tests\TestCase;

/**
 * Tabby payment-webhook verification — the registered custom auth header
 * (docs.tabby.ai/pay-in-4-custom-integration/webhooks): Tabby echoes the
 * header/value pair registered via POST /api/v1/webhooks on EVERY delivery;
 * verification IS the shared-secret comparison, constant-time (hash_equals),
 * fail-closed on any empty value.
 *
 * DB-free unit tests (MySQL unreachable in this environment — see
 * docs/audits/tabby-payment-integration-audit.md).
 */
class TabbyWebhookVerifierTest extends TestCase
{
    private const SECRET = 'unit_webhook_shared_secret';

    /** @test */
    public function it_verifies_the_registered_shared_secret_header_value()
    {
        $verifier = new TabbyWebhookVerifier();

        $this->assertTrue($verifier->verify(self::SECRET, self::SECRET));
        $this->assertFalse($verifier->verify('wrong_secret', self::SECRET));
        $this->assertFalse($verifier->verify(strrev(self::SECRET), self::SECRET));
    }

    /** @test */
    public function it_fails_closed_on_empty_values()
    {
        $verifier = new TabbyWebhookVerifier();

        $this->assertFalse($verifier->verify('', self::SECRET));
        $this->assertFalse($verifier->verify(self::SECRET, ''));
        $this->assertFalse($verifier->verify('', ''));
    }

    /** @test */
    public function it_marks_a_secret_hash_or_partial_prefix_as_rejected()
    {
        // The registered value is opaque — hashing it, prefixing it, or
        // trimming whitespace must all fail (exact opaque comparison).
        $verifier = new TabbyWebhookVerifier();

        $this->assertFalse($verifier->verify(hash('sha256', self::SECRET), self::SECRET));
        $this->assertFalse($verifier->verify(substr(self::SECRET, 0, 8), self::SECRET));
        $this->assertFalse($verifier->verify(' '.self::SECRET.' ', self::SECRET));
        $this->assertFalse($verifier->verify(strtoupper(self::SECRET), self::SECRET));
    }
}
