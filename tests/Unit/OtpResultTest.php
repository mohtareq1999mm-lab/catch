<?php

namespace Tests\Unit;

use Marvel\Otp\Result;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Result's provider-originated failure marker: it must NOT change
 * isValid() semantics for existing consumers (login OTP, existing flows),
 * while allowing classification of outages as failed dispatches.
 */
class OtpResultTest extends TestCase
{
    #[Test]
    public function a_successful_result_is_valid_and_not_a_provider_error(): void
    {
        $result = new Result('verification-id');

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isProviderError());
    }

    #[Test]
    public function a_plain_error_is_invalid_and_not_a_provider_error(): void
    {
        $result = new Result(['Verification check failed: Invalid code.']);

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isProviderError());
    }

    #[Test]
    public function a_provider_error_is_invalid_but_classifiable(): void
    {
        $result = Result::providerError(['Upstream unavailable.']);

        $this->assertFalse($result->isValid(), 'Existing isValid() consumers must keep treating it as a failure.');
        $this->assertTrue($result->isProviderError());
    }
}
