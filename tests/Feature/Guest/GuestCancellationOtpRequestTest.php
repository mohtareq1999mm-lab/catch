<?php

namespace Tests\Feature\Guest;

use App\DTOs\GatewayResult;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentGatewayRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Marvel\Database\Models\Order;
use Marvel\Otp\OtpInterface;
use Mockery;

/**
 * Guest cancellation OTP REQUEST step (dispatch only; ticket #69).
 *
 * The HTTP seam is the new route; the gateway seam is the OtpInterface
 * container binding (recording fake). The failure matrix mirrors the
 * existing cancellation endpoint's keys/statuses (design record §4):
 * the request step never consumes the single-use token and refuses
 * BEFORE any dispatch when the order cannot be cancelled.
 */
class GuestCancellationOtpRequestTest extends GuestCheckoutTestCase
{
    private RecordingCancellationOtpGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new RecordingCancellationOtpGateway();
        $this->app->instance(OtpInterface::class, $this->gateway);
    }

    /** Bind the recording fake and request the OTP for a cancellable order. */
    private function requestOtp(array $data)
    {
        return $this->postJson(self::PREFIX . '/orders/cancel/request-otp', [
            'token' => $data['cancellation_token'],
        ]);
    }

    /** @test */
    public function a_valid_token_dispatches_the_otp_through_the_active_gateway(): void
    {
        $data = $this->createGuestOrder('cod');

        $response = $this->requestOtp($data);

        $response->assertOk()
            ->assertJsonPath('message', __('guest_checkout.cancel_otp_sent'))
            ->assertJsonPath('data.otp_id', 'otp-verification-id-1')
            ->assertJsonPath('data.provider', config('auth.active_otp_gateway'));

        // Dispatched exactly once, to the phone recorded on the order.
        $this->assertSame(['+201000000001'], $this->gateway->started);
    }

    /** @test */
    public function requesting_the_otp_never_consumes_the_cancellation_token(): void
    {
        $data = $this->createGuestOrder('cod');

        $statusBefore = $data['order']->fresh()->status;

        $this->requestOtp($data)->assertOk();

        $order = $data['order']->fresh();

        $this->assertNull(
            $order->guest_cancellation_token_used_at,
            'The request step must leave the single-use mark untouched.'
        );
        $this->assertSame($statusBefore, $order->status, 'The request step must not change the order state.');
    }

    /** @test */
    public function an_invalid_token_format_is_refused_without_any_dispatch(): void
    {
        $this->postJson(self::PREFIX . '/orders/cancel/request-otp', ['token' => 'short'])
            ->assertStatus(404)
            ->assertJsonPath('message', __('guest_checkout.cancel_link_invalid'));

        $this->assertSame([], $this->gateway->started, 'No SMS may be dispatched for a malformed token.');
    }

    /** @test */
    public function a_well_formed_token_that_resolves_no_order_is_refused_without_any_dispatch(): void
    {
        $this->postJson(self::PREFIX . '/orders/cancel/request-otp', ['token' => str_repeat('a', 43)])
            ->assertStatus(404)
            ->assertJsonPath('message', __('guest_checkout.cancel_link_invalid'));

        $this->assertSame([], $this->gateway->started);
    }

    /** @test */
    public function a_used_token_is_refused_without_any_dispatch(): void
    {
        $data = $this->createGuestOrder('cod');

        $data['order']->forceFill(['guest_cancellation_token_used_at' => now()])->save();

        $this->requestOtp($data)
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_link_used'));

        $this->assertSame([], $this->gateway->started);
    }

    /** @test */
    public function an_expired_token_is_refused_without_any_dispatch(): void
    {
        $data = $this->createGuestOrder('cod');

        $data['order']->forceFill(['guest_cancellation_token_expires_at' => now()->subHour()])->save();

        $this->requestOtp($data)
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_link_expired'));

        $this->assertSame([], $this->gateway->started);
    }

    /** @test */
    public function a_non_cancellable_order_is_refused_without_any_dispatch(): void
    {
        $data = $this->createGuestOrder('cod');

        // A delivered order is outside the cancellable policy (status +
        // unpaid rule in GuestOrderTokenService::isOrderCancellable).
        $data['order']->forceFill(['status' => Order::ORDER_STATUS_DELIVERED])->save();

        $this->requestOtp($data)
            ->assertStatus(422)
            ->assertJsonPath('message', __('checkout.order_cancel_not_allowed'));

        $this->assertSame([], $this->gateway->started);
    }

    /** @test */
    public function an_unresolved_payment_attempt_is_refused_before_any_dispatch(): void
    {
        $this->mockOnlineGateway();
        $data = $this->createGuestOrder('online');

        // A pending payment attempt exists (initiated online payment).
        $this->initiateGuestOnline($data['order']->id, 'otp-request-race-key')->assertOk();

        $this->requestOtp($data)
            ->assertStatus(409)
            ->assertJsonPath('message', __('checkout.order_payment_in_progress'));

        $this->assertSame([], $this->gateway->started, 'The 409 must land BEFORE any gateway dispatch.');
    }

    /** @test */
    public function a_provider_dispatch_failure_is_reported_without_leaking_details(): void
    {
        $data = $this->createGuestOrder('cod');

        $this->gateway->dispatchFails = true;

        $response = $this->requestOtp($data);

        $response->assertStatus(502)
            ->assertJsonPath('message', __('guest_checkout.cancel_otp_send_failed'))
            ->assertJsonMissing(['otp_id' => 'otp-verification-id-1']);

        // The attempt reached the gateway, but the failure message carries
        // no internal provider error text.
        $this->assertCount(1, $this->gateway->started);
        $this->assertStringNotContainsString('fake', (string) $response->getContent());
    }

    /** @test */
    public function the_production_limiter_keys_both_per_token_and_per_ip(): void
    {
        // The REAL registration from RateLimitServiceProvider (config-driven):
        // D7 wants the OTP-request budget split into a per-token and a
        // per-IP counter. The raw credential never lands in the cache key.
        $limiter = app(RateLimiter::class)->limiter('guest_cancel_otp');

        $this->assertNotNull($limiter, 'The guest_cancel_otp limiter must be registered.');

        $limits = $limiter(new Request(['token' => 'raw-cancellation-token']));

        $this->assertCount(2, $limits, 'guest_cancel_otp must carry BOTH a per-token and a per-IP limit.');

        $keys = array_map(fn ($limit) => $limit->key, $limits);

        $this->assertStringStartsWith('cancel-otp:token:', $keys[0]);
        $this->assertStringStartsWith('cancel-otp:ip:', $keys[1]);
        $this->assertStringNotContainsString('raw-cancellation-token', $keys[0], 'The raw token must never be a cache key.');
    }

    /** @test */
    public function a_flood_of_otp_requests_is_rate_limited(): void
    {
        RateLimiterFacade::for('guest_cancel_otp', function (Request $request) {
            return [
                Limit::perMinute(2)->by('test-token:' . $request->input('token', '')),
                Limit::perMinute(2)->by('test-ip:' . $request->ip()),
            ];
        });

        $payload = ['token' => str_repeat('c', 43)];

        $this->postJson(self::PREFIX . '/orders/cancel/request-otp', $payload)->assertStatus(404);
        $this->postJson(self::PREFIX . '/orders/cancel/request-otp', $payload)->assertStatus(404);

        $fourth = $this->postJson(self::PREFIX . '/orders/cancel/request-otp', $payload);

        $fourth->assertStatus(429);
        $fourth->assertHeader('Retry-After');
        $this->assertSame([], $this->gateway->started, 'Rate-limited requests must not reach the gateway.');
    }

    private function mockOnlineGateway(): void
    {
        $gateway = Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('supportsCurrency')->andReturn(true);
        $gateway->shouldReceive('name')->andReturn('mock');
        $gateway->shouldReceive('code')->andReturn('mock');
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('createInvoice')
            ->andReturn(new GatewayResult(
                true,
                'https://pay.example.test/session',
                'INV-OTP-REQ',
                null,
                'EGP',
                'pending',
                null,
                []
            ));

        $this->mock(PaymentGatewayFactory::class, function ($mock) use ($gateway) {
            $mock->shouldReceive('make')->andReturn($gateway);
        });

        $this->mock(PaymentGatewayRegistry::class, function ($mock) {
            $mock->shouldReceive('canInitiate')->andReturn(['ok' => true, 'reason' => 'ok']);
        });
    }

    /** @test */
    public function an_order_without_a_recorded_phone_is_refused_before_dispatch(): void
    {
        $data = $this->createGuestOrder('cod');

        $data['order']->forceFill(['user_phone' => null])->save();

        $this->requestOtp($data)
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_no_phone'));

        $this->assertSame([], $this->gateway->started, 'No OTP may be dispatched when the order has no phone.');
    }
}
