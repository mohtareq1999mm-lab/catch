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

    /**
     * ADR-0003: production can never accept the static code, even with the
     * enabled flag on (misconfigured environment). The container 'env'
     * snapshot is what app()->isProduction() reads, so it is simulated the
     * same way the app itself detects the environment.
     */
    /** @test */
    public function it_never_accepts_the_static_code_in_production()
    {
        $this->app['env'] = 'production';
        config(['auth.local_otp_enabled' => true, 'auth.local_otp_code' => '123456']);

        $result = (new LocalGateway())->checkVerification('local-verification-id', '123456', '+2011185151');

        $this->assertFalse($result->isValid(), 'The static code must be dead in production.');
    }

    /** @test */
    public function it_never_starts_a_verification_in_production()
    {
        $this->app['env'] = 'production';
        config(['auth.local_otp_enabled' => true, 'auth.local_otp_code' => '123456']);

        $result = (new LocalGateway())->startVerification('+2011185151');

        $this->assertFalse($result->isValid(), 'The local gateway must not hand out verification ids in production.');
    }

    /**
     * Total inertness (ADR-0003): a fake message receipt in production
     * would let a caller believe a delivery happened when nothing left the
     * machine. Every LocalGateway answer is refused in production.
     *
     * @test */
    public function it_never_reports_a_sent_sms_in_production()
    {
        $this->app['env'] = 'production';

        $result = (new LocalGateway())->sendSms('+2011185151', 'anything');

        $this->assertFalse($result->isValid(), 'The local gateway must not hand out fake message receipts in production.');
    }
}
