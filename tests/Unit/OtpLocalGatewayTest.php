<?php

namespace Tests\Unit;

use Marvel\Otp\Gateways\LocalGateway;
use Tests\TestCase;

class OtpLocalGatewayTest extends TestCase
{
    /** @test */
    public function it_accepts_the_configured_static_code_for_the_local_verification_id()
    {
        config(['auth.local_otp_enabled' => true, 'auth.local_otp_code' => '123456']);

        $result = (new LocalGateway())->checkVerification('local-verification-id', '123456', '+2011185151');

        $this->assertTrue($result->isValid());
    }

    /** @test */
    public function it_rejects_a_wrong_code()
    {
        config(['auth.local_otp_enabled' => true, 'auth.local_otp_code' => '123456']);

        $result = (new LocalGateway())->checkVerification('local-verification-id', '000000', '+2011185151');

        $this->assertFalse($result->isValid());
    }

    /** @test */
    public function it_rejects_an_unknown_verification_id_even_with_the_right_code()
    {
        config(['auth.local_otp_enabled' => true, 'auth.local_otp_code' => '123456']);

        $result = (new LocalGateway())->checkVerification('some-other-id', '123456', '+2011185151');

        $this->assertFalse($result->isValid());
    }

    /** @test */
    public function it_rejects_everything_when_the_local_path_is_disabled()
    {
        config(['auth.local_otp_enabled' => false, 'auth.local_otp_code' => '123456']);

        $result = (new LocalGateway())->checkVerification('local-verification-id', '123456', '+2011185151');

        $this->assertFalse($result->isValid());
    }

    /** @test */
    public function it_rejects_everything_when_no_code_is_configured()
    {
        config(['auth.local_otp_enabled' => true, 'auth.local_otp_code' => '']);

        $result = (new LocalGateway())->checkVerification('local-verification-id', '123456', '+2011185151');

        $this->assertFalse($result->isValid());
    }
}
