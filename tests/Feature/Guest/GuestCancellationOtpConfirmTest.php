<?php

namespace Tests\Feature\Guest;

use App\Events\OrderCancelled;
use App\DTOs\GatewayResult;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentGatewayRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Otp\OtpInterface;

/**
 * Guest cancellation OTP CONFIRM step (ticket #70).
 *
 * The confirm endpoint carries the disclosed breaking change: it now
 * requires { token, otp_id, code } (design D6); response shape and
 * success semantics stay identical. Verification is a gate IN FRONT of
 * the canonical cancellation authority — never a second one: success
 * delegates to GuestCancellationService::cancel() unchanged (atomic
 * single-use mark, single canonical event stream, unpaid-promotion
 * semantics), and the static bypass is wired through the shared gate
 * (D4). Brute-force lockout per D8: five failed code attempts per token
 * HASH → 429, cleared on success; policy failures never consume an
 * attempt.
 *
 * Failure matrix under test (design §4, confirm column), all through
 * the HTTP seam:
 *   invalid/used/expired/non-cancellable → existing keys/statuses (no attempt)
 *   unresolved payment attempt           → 409 (no attempt)
 *   no phone on the order                → 422 cancel_no_phone (no attempt)
 *   wrong/refused code                   → 422 cancel_otp_invalid (attempt)
 *   exhausted attempt budget             → 429
 *   success                              → 200 (existing shape)
 */
class GuestCancellationOtpConfirmTest extends GuestCheckoutTestCase
{
    /** A code only the provider-path fake accepts (≠ the static value). */
    private const GATEWAY_CODE = '445566';

    /** The static code resolved when master_otp is unset. */
    private const STATIC_CODE = '123456';

    private RecordingCancellationOtpGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new RecordingCancellationOtpGateway();
        $this->app->instance(OtpInterface::class, $this->gateway);

        // Explicit resolved values: the committed .env ships MASTER_OTP=123456,
        // so the accepted static value is pinned here instead of trusting the
        // environment (design record, section 6).
        config([
            'auth.local_otp_enabled' => true,
            'auth.local_otp_code' => self::STATIC_CODE,
            'auth.master_otp' => null,
        ]);
    }

    private function requestOtp(array $data): string
    {
        return $this->postJson(self::PREFIX . '/orders/cancel/request-otp', [
            'token' => $data['cancellation_token'],
        ])->assertOk()->json('data.otp_id');
    }

    /** POST the confirm endpoint with full D6 credentials. */
    private function confirm(string $token, string $code, ?string $otpId = null)
    {
        return $this->postJson(self::PREFIX . '/orders/cancel', [
            'token' => $token,
            'otp_id' => $otpId ?? 'unused-otp-id',
            'code' => $code,
        ]);
    }

    /**
     * Neutralise the per-IP route limiter so a 429 can only originate
     * from the D8 attempt-budget lockout under test.
     */
    private function raiseCancelRouteLimiter(): void
    {
        RateLimiterFacade::for('guest_cancel', fn (Request $request) => Limit::perMinute(1000)->by('test-ip:' . $request->ip()));
    }

    private function mockOnlineGateway(): void
    {
        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('supportsCurrency')->andReturn(true);
        $gateway->shouldReceive('name')->andReturn('mock');
        $gateway->shouldReceive('code')->andReturn('mock');
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('createInvoice')
            ->andReturn(new GatewayResult(
                true,
                'https://pay.example.test/session',
                'INV-OTP-CONFIRM',
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
    public function a_correct_code_through_the_real_verification_path_executes_the_canonical_cancellation(): void
    {
        $data = $this->createGuestOrder('cod');
        $otpId = $this->requestOtp($data);

        $response = $this->confirm($data['cancellation_token'], self::GATEWAY_CODE, $otpId);

        // The response shape and message are unchanged: the disclosed
        // breaking change is the required credentials only.
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('checkout.order_cancelled_successfully'))
            ->assertJsonPath('data.order.order_number', $data['order']->order_number)
            ->assertJsonPath('data.order.status', Order::ORDER_STATUS_CANCELLED);

        $order = $data['order']->fresh();
        $this->assertSame(Order::ORDER_STATUS_CANCELLED, $order->status);
        $this->assertNotNull($order->guest_cancellation_token_used_at, 'The single-use mark must be set.');
        $this->assertNotNull($order->cancelled_at);

        // Verified on the REAL path: the provider was consulted with the
        // issued id, the submitted code and the phone on the token's order.
        $this->assertSame(
            [[$otpId, self::GATEWAY_CODE, '+201000000001']],
            $this->gateway->checked
        );

        // The confirm step dispatches nothing itself: the request step
        // sent the only SMS (design D3/D9 — no second dispatch on confirm).
        $this->assertSame(['+201000000001'], $this->gateway->started);
    }

    /** @test */
    public function the_static_code_is_accepted_with_any_gateway_selected_and_skips_the_provider(): void
    {
        config(['auth.active_otp_gateway' => 'twilio']);

        $data = $this->createGuestOrder('cod');

        // otp_id stays a required field on the bypass path (D4) but is unused.
        $this->confirm($data['cancellation_token'], self::STATIC_CODE, 'unused-bypass-id')
            ->assertOk()
            ->assertJsonPath('data.order.status', Order::ORDER_STATUS_CANCELLED);

        // Bypass mode skips the provider call entirely (D4).
        $this->assertSame([], $this->gateway->checked);
        $this->assertSame([], $this->gateway->started);
    }

    /** @test */
    public function the_static_code_is_refused_when_the_local_flag_is_disabled(): void
    {
        config(['auth.local_otp_enabled' => false]);
        $this->gateway->verificationFails = true;

        $data = $this->createGuestOrder('cod');

        $this->confirm($data['cancellation_token'], self::STATIC_CODE)
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_otp_invalid'));

        $order = $data['order']->fresh();
        $this->assertNotSame(Order::ORDER_STATUS_CANCELLED, $order->status);
        $this->assertNull($order->guest_cancellation_token_used_at);

        // The refusal fell through to the provider decision point — the
        // bypass skip is proven through HTTP, not only the unit seam.
        $this->assertCount(1, $this->gateway->checked);
    }

    /** @test */
    public function the_static_code_is_hard_blocked_in_production_even_when_enabled(): void
    {
        $data = $this->createGuestOrder('cod');

        $this->gateway->verificationFails = true;

        // The container 'env' snapshot is what app()->isProduction() reads;
        // mutating config('app.env') cannot flip it, so the environment is
        // simulated the same way the app itself detects it.
        $this->app['env'] = 'production';

        $this->confirm($data['cancellation_token'], self::STATIC_CODE)
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_otp_invalid'));

        // The hard block pushed the code to the provider decision point.
        $this->assertCount(1, $this->gateway->checked);
        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at);
    }

    /** @test */
    public function the_master_otp_value_overrides_the_local_code_as_the_accepted_value(): void
    {
        config(['auth.master_otp' => '998877', 'auth.local_otp_code' => self::STATIC_CODE]);

        $data = $this->createGuestOrder('cod');

        $this->confirm($data['cancellation_token'], '998877')
            ->assertOk()
            ->assertJsonPath('data.order.status', Order::ORDER_STATUS_CANCELLED);

        $this->assertSame([], $this->gateway->checked, 'Master OTP acceptance is bypass-only (no provider call).');
    }

    /** @test */
    public function the_local_code_is_no_longer_the_accepted_value_when_a_master_otp_is_set(): void
    {
        config(['auth.master_otp' => '998877', 'auth.local_otp_code' => self::STATIC_CODE]);
        $this->gateway->verificationFails = true;

        $data = $this->createGuestOrder('cod');

        $this->confirm($data['cancellation_token'], self::STATIC_CODE)
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_otp_invalid'));

        // The displaced local code took the provider path instead.
        $this->assertCount(1, $this->gateway->checked);
    }

    /** @test */
    public function missing_credentials_fail_validation_with_the_breaking_change(): void
    {
        $data = $this->createGuestOrder('cod');

        $this->postJson(self::PREFIX . '/orders/cancel', ['token' => $data['cancellation_token']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp_id', 'code']);

        $this->postJson(self::PREFIX . '/orders/cancel', [
            'token' => $data['cancellation_token'],
            'otp_id' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);

        $this->postJson(self::PREFIX . '/orders/cancel', [
            'token' => $data['cancellation_token'],
            'code' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['otp_id']);

        // Validation failure never reaches the gateway or the order.
        $this->assertSame([], $this->gateway->checked);
        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at);
    }

    /** @test */
    public function a_wrong_code_fails_clearly_and_the_token_remains_usable(): void
    {
        $data = $this->createGuestOrder('cod');
        $otpId = $this->requestOtp($data);

        $statusBefore = $data['order']->fresh()->status;

        $this->gateway->verificationFails = true;

        $this->confirm($data['cancellation_token'], '000000', $otpId)
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_otp_invalid'));

        $order = $data['order']->fresh();
        $this->assertSame($statusBefore, $order->status);
        $this->assertNull($order->guest_cancellation_token_used_at);
        $this->assertNull($order->cancelled_at);

        // The single-use link is NOT burned by a failed verification.
        $this->gateway->verificationFails = false;

        $this->confirm($data['cancellation_token'], self::GATEWAY_CODE, $otpId)
            ->assertOk()
            ->assertJsonPath('data.order.status', Order::ORDER_STATUS_CANCELLED);

        $this->assertCount(2, $this->gateway->checked);
    }

    /** @test */
    public function five_failed_code_attempts_lock_the_token_out(): void
    {
        $this->raiseCancelRouteLimiter();
        $this->gateway->verificationFails = true;

        $data = $this->createGuestOrder('cod');

        for ($i = 0; $i < 5; $i++) {
            $this->confirm($data['cancellation_token'], '00000' . $i)
                ->assertStatus(422)
                ->assertJsonPath('message', __('guest_checkout.cancel_otp_invalid'));
        }

        // Sixth attempt: the budget is exhausted BEFORE any verification.
        $this->confirm($data['cancellation_token'], '000005')
            ->assertStatus(429)
            ->assertJsonPath('message', __('guest_checkout.rate_limited'));

        $this->assertCount(5, $this->gateway->checked, 'The locked-out attempt must not reach the provider.');
        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at);
    }

    /** @test */
    public function the_attempt_budget_is_cleared_by_a_successful_verification(): void
    {
        $this->raiseCancelRouteLimiter();

        $data = $this->createGuestOrder('cod');
        $token = $data['cancellation_token'];

        // Three real failures set the budget to 3 of 5…
        $this->gateway->verificationFails = true;
        for ($i = 0; $i < 3; $i++) {
            $this->confirm($token, '00000' . $i)->assertStatus(422);
        }

        // …then a SUCCESSFUL verification clears it (D8). To keep the token
        // observable afterwards, the canonical cancellation itself fails on
        // a REAL pre-commit guard: the flow-transition gate refuses, the
        // canonical transaction rolls the used-mark back, and the outcome
        // is the canonical cancel_failed path with the token still alive.
        $this->gateway->verificationFails = false;

        $flowService = \Mockery::mock(\App\Services\OrderFlow\OrderFlowService::class);
        $flowService->shouldReceive('allowsFlowTransition')->andReturn(false);
        $this->app->instance(\App\Services\OrderFlow\OrderFlowService::class, $flowService);

        $this->confirm($token, self::GATEWAY_CODE)
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_failed'));

        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at, 'The failed canonical cancel must roll the used-mark back.');

        // Five MORE failures after the cleared budget: every one must still
        // be a code failure (422) — a retained budget would have 429'd at
        // the THIRD — and only the sixth trips the fresh lockout.
        $this->gateway->verificationFails = true;
        for ($i = 0; $i < 5; $i++) {
            $this->confirm($token, '11111' . $i)
                ->assertStatus(422)
                ->assertJsonPath('message', __('guest_checkout.cancel_otp_invalid'));
        }
        $this->confirm($token, '111115')->assertStatus(429);
    }

    /** @test */
    public function the_lockout_is_per_token_and_leaves_other_tokens_unaffected(): void
    {
        $this->raiseCancelRouteLimiter();
        $this->gateway->verificationFails = true;

        $first = $this->createGuestOrder('cod');
        $second = $this->createGuestOrder('cod');

        for ($i = 0; $i < 5; $i++) {
            $this->confirm($first['cancellation_token'], '00000' . $i)->assertStatus(422);
        }
        $this->confirm($first['cancellation_token'], '000005')->assertStatus(429);

        // A different token keeps its own budget.
        $this->confirm($second['cancellation_token'], '999999')
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_otp_invalid'));
    }

    /** @test */
    public function policy_failures_never_consume_a_code_attempt(): void
    {
        $this->raiseCancelRouteLimiter();
        $this->gateway->verificationFails = true;

        // An ONLINE order: the unresolved-attempt interlude initiates a
        // real online payment, which requires a matching payment method.
        $data = $this->createGuestOrder('online');
        $order = $data['order'];
        $token = $data['cancellation_token'];

        // Two real code failures → budget 2 of 5.
        $this->confirm($token, '000001')->assertStatus(422);
        $this->confirm($token, '000002')->assertStatus(422);

        // Used link: refused at the policy gate, no attempt consumed.
        $order->forceFill(['guest_cancellation_token_used_at' => now()])->save();
        $this->confirm($token, '000003')
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_link_used'));
        $order->forceFill(['guest_cancellation_token_used_at' => null])->save();

        // Expired link.
        $order->forceFill(['guest_cancellation_token_expires_at' => now()->subHour()])->save();
        $this->confirm($token, '000003')
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_link_expired'));
        $order->forceFill(['guest_cancellation_token_expires_at' => now()->addDay()])->save();

        // Non-cancellable state.
        $previousStatus = $order->status;
        $order->forceFill(['status' => Order::ORDER_STATUS_DELIVERED])->save();
        $this->confirm($token, '000003')
            ->assertStatus(422)
            ->assertJsonPath('message', __('checkout.order_cancel_not_allowed'));
        $order->forceFill(['status' => $previousStatus])->save();

        // Well-formed token that resolves no order.
        $this->confirm(str_repeat('a', 43), '000003')
            ->assertStatus(404)
            ->assertJsonPath('message', __('guest_checkout.cancel_link_invalid'));

        // Unresolved payment attempt → 409, still no attempt consumed.
        $this->mockOnlineGateway();
        $this->initiateGuestOnline($order->id, 'otp-confirm-race-key')->assertOk();
        $this->confirm($token, '000003')
            ->assertStatus(409)
            ->assertJsonPath('message', __('checkout.order_payment_in_progress'));
        Transaction::query()->where('order_id', $order->id)->delete();

        // The budget must still be 2: three more real failures are plain
        // 422s and only the SIXTH real failure trips the lockout — any
        // counting of the policy failures above would 429 earlier.
        $this->confirm($token, '000003')->assertStatus(422);
        $this->confirm($token, '000004')->assertStatus(422);
        $this->confirm($token, '000005')->assertStatus(422);
        $this->confirm($token, '000006')->assertStatus(429);

        // Only the 5 real code failures ever reached the provider.
        $this->assertCount(5, $this->gateway->checked);
        $this->assertNull($order->fresh()->guest_cancellation_token_used_at);
    }

    /** @test */
    public function an_order_without_a_recorded_phone_is_refused_before_verification(): void
    {
        $data = $this->createGuestOrder('cod');
        $data['order']->forceFill(['user_phone' => null])->save();

        $this->confirm($data['cancellation_token'], self::GATEWAY_CODE)
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_no_phone'));

        $this->assertSame([], $this->gateway->checked, 'No verification may run when the order has no phone.');
    }

    /** @test */
    public function the_canonical_event_stream_fires_exactly_once_per_cancellation(): void
    {
        $orderCancelled = 0;
        Event::listen(OrderCancelled::class, function () use (&$orderCancelled) {
            $orderCancelled++;
        });

        $data = $this->createGuestOrder('cod');
        $otpId = $this->requestOtp($data);

        $this->confirm($data['cancellation_token'], self::GATEWAY_CODE, $otpId)
            ->assertOk()
            ->assertJsonPath('data.order.status', Order::ORDER_STATUS_CANCELLED);

        $this->assertSame(
            1,
            $orderCancelled,
            'The canonical status writer must own the whole event stream — no duplicate emission.'
        );
    }
}
