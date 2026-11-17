<?php

namespace Tests\Unit\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Services\General\OrderService;
use App\Services\Guest\GuestCancellationService;
use App\Services\Guest\GuestOrderTokenService;
use App\Services\Payment\PaymentAttemptLifecycleService;
use Mockery;
use Tests\TestCase;

class GuestCancellationOtpTest extends TestCase
{
    private GuestCancellationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $tokenService = Mockery::mock(GuestOrderTokenService::class);
        $orderService = Mockery::mock(OrderService::class);
        $attemptLifecycle = Mockery::mock(PaymentAttemptLifecycleService::class);

        $this->service = new GuestCancellationService($tokenService, $orderService, $attemptLifecycle);
    }

    /** @test */
    public function it_accepts_configured_master_otp_in_bypass_mode(): void
    {
        config([
            'auth.local_otp_enabled' => true,
            'auth.master_otp' => '999888',
        ]);

        $this->assertTrue($this->service->isBypassActive());
        $this->assertTrue($this->service->isBypassCodeAccepted('999888'));
        $this->assertFalse($this->service->isBypassCodeAccepted('123456'));
        $this->assertFalse($this->service->isBypassCodeAccepted('000000'));
    }

    /** @test */
    public function it_accepts_local_otp_code_when_enabled_and_master_otp_is_empty(): void
    {
        config([
            'auth.local_otp_enabled' => true,
            'auth.master_otp' => '',
            'auth.local_otp_code' => '123456',
        ]);

        $this->assertTrue($this->service->isBypassActive());
        $this->assertTrue($this->service->isBypassCodeAccepted('123456'));
        $this->assertFalse($this->service->isBypassCodeAccepted('654321'));
    }

    /** @test */
    public function it_rejects_bypass_when_local_otp_is_disabled(): void
    {
        config([
            'auth.local_otp_enabled' => false,
            'auth.master_otp' => '123456',
            'auth.local_otp_code' => '123456',
        ]);

        $this->assertFalse($this->service->isBypassActive());
        $this->assertFalse($this->service->isBypassCodeAccepted('123456'));
    }

    /** @test */
    public function it_rejects_empty_or_null_codes(): void
    {
        config([
            'auth.local_otp_enabled' => true,
            'auth.local_otp_code' => '123456',
        ]);

        $this->assertFalse($this->service->isBypassCodeAccepted(''));
        $this->assertFalse($this->service->isBypassCodeAccepted(null));
    }
}
