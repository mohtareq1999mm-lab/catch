<?php

namespace Tests\Unit\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Services\General\OrderService;
use App\Services\Guest\GuestCancellationService;
use App\Services\Guest\GuestOrderTokenService;
use App\Services\Payment\PaymentAttemptLifecycleService;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Order;
use Marvel\Otp\Gateways\OtpGateway;
use Marvel\Otp\OtpInterface;
use Marvel\Otp\Result;
use Mockery;
use Tests\TestCase;

class GuestCancellationOtpTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

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

    /**
     * Provider-outage classification: an upstream outage at the CONFIRM
     * step must surface as a failed dispatch (502) and must NOT consume
     * the attempt budget — a legitimate user must not be locked out for
     * an outage they cannot control.
     */
    /** @test */
    public function a_provider_outage_at_confirm_is_a_502_and_consumes_no_attempt(): void
    {
        config(['auth.local_otp_enabled' => false, 'auth.active_otp_gateway' => 'twilio']);

        $rawToken = 'outage-confirm-token-1';
        $hash = 'hash-' . $rawToken;
        $order = $this->createOrderWithTokenHash($hash);

        $service = $this->serviceWithGateway(
            $this->fakeGateway(fn () => Result::providerError(['Upstream outage.']))
        );

        try {
            $service->cancel($rawToken, '445566', 'otp-id-1');
            $this->fail('Expected a 502 conflict for the provider outage.');
        } catch (GuestCheckoutConflictException $e) {
            $this->assertSame(502, $e->httpStatus());
            $this->assertSame(__('guest_checkout.otp_send_failed'), $e->getMessage());
        }

        $this->assertNull($order->fresh()->guest_cancellation_token_used_at, 'The outage must not consume the link.');
        $this->assertSame(Order::ORDER_STATUS_PENDING, $order->fresh()->status);
        $this->assertNull(Cache::get('guest_cancel_otp_lockout:' . $hash), 'The outage must not consume an attempt.');
    }

    /**
     * The same classification at the REQUEST step: a provider outage is a
     * failed dispatch (502), not a client error, and consumes nothing.
     */
    /** @test */
    public function a_provider_outage_at_request_is_a_502_and_consumes_nothing(): void
    {
        config(['auth.local_otp_enabled' => false, 'auth.active_otp_gateway' => 'twilio']);

        $rawToken = 'outage-request-token-1';
        $order = $this->createOrderWithTokenHash('hash-' . $rawToken);

        $service = $this->serviceWithGateway(
            $this->fakeGateway(fn () => Result::providerError(['Upstream outage.']))
        );

        try {
            $service->requestOtp($rawToken);
            $this->fail('Expected a 502 conflict for the provider outage.');
        } catch (GuestCheckoutConflictException $e) {
            $this->assertSame(502, $e->httpStatus());
            $this->assertSame(__('guest_checkout.otp_send_failed'), $e->getMessage());
        }

        $this->assertNull($order->fresh()->guest_cancellation_token_used_at);
    }

    /**
     * A REJECTED code (the gateway answering "wrong code") must keep the
     * old behavior: 422 invalid_otp AND one consumed attempt.
     */
    /** @test */
    public function a_rejected_code_still_consumes_an_attempt(): void
    {
        config(['auth.local_otp_enabled' => false, 'auth.active_otp_gateway' => 'twilio']);

        $rawToken = 'reject-code-token-1';
        $hash = 'hash-' . $rawToken;
        $order = $this->createOrderWithTokenHash($hash);

        $service = $this->serviceWithGateway(
            $this->fakeGateway(fn () => new Result(['Verification check failed: Invalid code.']))
        );

        try {
            $service->cancel($rawToken, '000000', 'otp-id-1');
            $this->fail('Expected a 422 conflict for the rejected code.');
        } catch (GuestCheckoutConflictException $e) {
            $this->assertSame(422, $e->httpStatus());
            $this->assertSame(__('guest_checkout.invalid_otp'), $e->getMessage());
        }

        $this->assertSame(1, (int) Cache::get('guest_cancel_otp_lockout:' . $hash), 'A wrong code consumes an attempt.');
        $this->assertNull($order->fresh()->guest_cancellation_token_used_at);
    }

    /** A stubbed gateway returning a canned Result for both OTP calls. */
    private function fakeGateway(\Closure $outcomes): OtpGateway
    {
        return new OtpGateway(new class($outcomes) implements OtpInterface {
            public function __construct(private \Closure $outcomes)
            {
            }

            public function startVerification($phone_number)
            {
                return ($this->outcomes)();
            }

            public function checkVerification($id, $code, $phone_number)
            {
                return ($this->outcomes)();
            }

            public function sendSms($phone_number, $messageBody)
            {
                return new Result(['not used by this fake']);
            }
        });
    }

    /** Their service with a stubbed gateway resolution seam. */
    private function serviceWithGateway(OtpGateway $gateway): GuestCancellationService
    {
        $tokenService = Mockery::mock(GuestOrderTokenService::class);
        $tokenService->shouldReceive('isValidTokenFormat')->andReturn(true);
        $tokenService->shouldReceive('hash')->andReturnUsing(fn (string $raw) => 'hash-' . $raw);
        $tokenService->shouldReceive('isOrderCancellable')->andReturn(true);

        $orderService = Mockery::mock(OrderService::class);
        $attemptLifecycle = Mockery::mock(PaymentAttemptLifecycleService::class);
        $attemptLifecycle->shouldReceive('hasUnresolvedAttempt')->andReturn(false);

        return new class($tokenService, $orderService, $attemptLifecycle, $gateway) extends GuestCancellationService {
            public function __construct($tokenService, $orderService, $attemptLifecycle, private OtpGateway $stub)
            {
                parent::__construct($tokenService, $orderService, $attemptLifecycle);
            }

            protected function getOtpGateway(): OtpGateway
            {
                return $this->stub;
            }
        };
    }

    /** A cancellable order row bound to the given token hash. */
    private function createOrderWithTokenHash(string $hash): Order
    {
        $order = Order::create([
            'name' => 'Outage Test',
            'user_phone' => '+201000000088',
            'address' => ['street' => '1 Test Street', 'city' => 'Test City'],
            'price' => 100,
            'total_price' => 100,
            'status' => 'pending',
        ]);

        // The hash is deliberately NOT fillable (1-way credential; hidden
        // from serialization) — production binds it through a query update,
        // and so does this fixture.
        Order::query()->whereKey($order->id)->update(['guest_cancellation_token_hash' => $hash]);

        return $order->refresh();
    }
}
