<?php

namespace Tests\Unit;

use App\ValueObjects\WebhookSignature;
use Tests\TestCase;

class WebhookSignatureTest extends TestCase
{
    /** @test */
    public function it_generates_hmac_sha256_signature()
    {
        $signature = new WebhookSignature(secret: 'test-secret');
        $payload = '{"resource":"products"}';

        $result = $signature->generate($payload);

        $this->assertNotNull($result);
        $this->assertIsString($result);
        $this->assertEquals(64, strlen($result));
    }

    /** @test */
    public function it_generates_different_signatures_for_different_secrets()
    {
        $payload = '{"resource":"products"}';

        $sig1 = new WebhookSignature(secret: 'secret-1');
        $sig2 = new WebhookSignature(secret: 'secret-2');

        $this->assertNotEquals(
            $sig1->generate($payload),
            $sig2->generate($payload)
        );
    }

    /** @test */
    public function it_generates_different_signatures_for_different_payloads()
    {
        $signature = new WebhookSignature(secret: 'test-secret');

        $this->assertNotEquals(
            $signature->generate('{"resource":"products"}'),
            $signature->generate('{"resource":"categories"}')
        );
    }

    /** @test */
    public function it_generates_consistent_signature_for_same_input()
    {
        $signature = new WebhookSignature(secret: 'test-secret');
        $payload = '{"resource":"products"}';

        $this->assertEquals(
            $signature->generate($payload),
            $signature->generate($payload)
        );
    }

    /** @test */
    public function it_verifies_correct_signature()
    {
        $signature = new WebhookSignature(secret: 'test-secret');
        $payload = '{"resource":"products"}';
        $hash = $signature->generate($payload);

        $this->assertTrue($signature->verify($payload, $hash));
    }

    /** @test */
    public function it_rejects_incorrect_signature()
    {
        $signature = new WebhookSignature(secret: 'test-secret');
        $payload = '{"resource":"products"}';

        $this->assertFalse($signature->verify($payload, 'invalid-signature'));
    }

    /** @test */
    public function it_works_with_empty_secret()
    {
        $signature = new WebhookSignature(secret: '');
        $payload = '{"resource":"test"}';

        $hash = $signature->generate($payload);
        $this->assertNotNull($hash);
        $this->assertTrue($signature->verify($payload, $hash));
    }
}
