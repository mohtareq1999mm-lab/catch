<?php

namespace Tests\Unit;

use Marvel\Otp\FailingGateway;
use Marvel\Otp\Gateways\LocalGateway;
use Marvel\Otp\OtpInterface;
use Tests\TestCase;

/**
 * The ONE OTP gateway resolution (container binding) and its ADR-0003
 * policy, pinned for every consumer — guest cancellation, login, SMS.
 *
 * Before the unification, login and SMS carried hand-rolled resolvers:
 * login silently fell back to the Local gateway in production, and SMS
 * jobs fataled on a misconfigured gateway. The container is the only
 * resolution now, so its policy is asserted here.
 */
class OtpGatewayResolutionTest extends TestCase
{
    /** @test */
    public function the_container_resolves_the_configured_provider()
    {
        config(['auth.active_otp_gateway' => 'local']);

        $this->assertInstanceOf(LocalGateway::class, app(OtpInterface::class));
    }

    /** @test */
    public function an_unknown_gateway_falls_back_to_the_local_gateway_outside_production()
    {
        $this->app['env'] = 'testing';
        config(['auth.active_otp_gateway' => 'nonexistent']);

        $this->assertInstanceOf(LocalGateway::class, app(OtpInterface::class));
    }

    /** @test */
    public function an_unknown_gateway_in_production_resolves_to_a_loud_failing_gateway()
    {
        $this->app['env'] = 'production';
        config(['auth.active_otp_gateway' => 'nonexistent']);

        $gateway = app(OtpInterface::class);

        $this->assertInstanceOf(FailingGateway::class, $gateway);

        $this->expectException(\RuntimeException::class);
        $gateway->startVerification('+2011185151');
    }

    /** @test */
    public function the_local_gateway_is_constructible_in_production_but_inert()
    {
        // The binding's production guard covers UNKNOWN/unconstructable
        // classes; a KNOWN static-value gateway constructs fine — and is
        // held inert by its own methods (the layered defense).
        $this->app['env'] = 'production';
        config(['auth.active_otp_gateway' => 'local']);

        $gateway = app(OtpInterface::class);

        $this->assertInstanceOf(LocalGateway::class, $gateway);
        $this->assertFalse($gateway->startVerification('+2011185151')->isValid());
    }
}
