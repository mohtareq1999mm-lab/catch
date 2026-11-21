<?php

namespace Tests\Feature\Guest;

use App\Models\OrderStatusHistory;
use App\Services\Guest\GuestOrderTokenService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Marvel\Database\Models\Order;
use Mockery;

/**
 * Cancellation possession proof (#81, spec #73).
 *
 * The single-use cancellation link alone is no longer sufficient: the caller
 * must also prove possession of the phone on the order with an OTP. The
 * approved contract pinned here:
 *
 *  - the request endpoint sends a code and returns NO credential material;
 *  - a code is required for cancellation; wrong codes never cancel;
 *  - the static bypass is checked first but stays a VALUE-only override
 *    (ADR 0003): a configured master code never enables it;
 *  - five failed attempts lock the TOKEN HASH for 24 hours, and the lock
 *    survives fresh code requests and the correct code;
 *  - dead links cannot spend an SMS;
 *  - the audit trail records which possession proof was used.
 */
class GuestCancellationOtpTest extends GuestCheckoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pin the LOCAL OTP path (ADR 0003): no SMS provider is contacted and
        // the real provider-verification path is exercised. The master value
        // differs from the local code so a static-bypass acceptance and a
        // provider verification can never be confused in the assertions.
        config([
            'auth.active_otp_gateway' => 'local',
            'auth.local_otp_enabled' => true,
            'auth.local_otp_code' => '654321',
            'auth.master_otp' => '999999',
        ]);

        // The route-level noise limiter (5/min) would mask the SERVICE lockout
        // under test; its registration is pinned by GuestRouteContractTest.
        RateLimiter::for('guest_cancel', function ($request) {
            return Limit::perMinute(100)->by('otp-test:' . $request->ip());
        });
    }

    private function requestCode(string $token): TestResponse
    {
        return $this->postJson(self::PREFIX . '/orders/cancellation-otp', [
            'token' => $token,
        ]);
    }

    private function cancelWithCode(string $token, string $code): TestResponse
    {
        return $this->postJson(self::PREFIX . '/orders/cancel', [
            'token' => $token,
            'code' => $code,
        ]);
    }

    private function tokenHash(string $token): string
    {
        return app(GuestOrderTokenService::class)->hash($token);
    }

    private function stateKey(string $token): string
    {
        return 'guest_cancel_otp:state:' . $this->tokenHash($token);
    }

    private function lockKey(string $token): string
    {
        return 'guest_cancel_otp:lock:' . $this->tokenHash($token);
    }

    private function cancellationHistory(int $orderId): OrderStatusHistory
    {
        return OrderStatusHistory::query()
            ->where('order_id', $orderId)
            ->where('new_status', Order::ORDER_STATUS_CANCELLED)
            ->latest('id')
            ->firstOrFail();
    }

    /** @test */
    public function requesting_a_code_returns_no_credential_material(): void
    {
        $data = $this->createGuestOrder('cod');
        $token = $data['cancellation_token'];

        $response = $this->requestCode($token);
        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(__('guest_checkout.cancel_otp_sent'), $response->json('message'));

        $payload = $response->json('data') ?? [];
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertArrayNotHasKey('code', $payload);
        $this->assertArrayNotHasKey('verification_id', $payload);

        $json = (string) $response->getContent();
        $this->assertStringNotContainsString($token, $json);
        $this->assertStringNotContainsString('654321', $json);
        $this->assertStringNotContainsString('999999', $json);

        // The code state is keyed by the token HASH, never by the raw token.
        $this->assertTrue(Cache::has($this->stateKey($token)));
        $this->assertStringNotContainsString($token, $this->stateKey($token));
    }

    /** @test */
    public function the_cancellation_endpoint_now_requires_the_verification_code(): void
    {
        $data = $this->createGuestOrder('cod');

        $this->postJson(self::PREFIX . '/orders/cancel', [
            'token' => $data['cancellation_token'],
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertNotSame(Order::ORDER_STATUS_CANCELLED, $data['order']->fresh()->status, 'A rejected cancellation must not change the order status.');
        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at);
    }

    /** @test */
    public function a_wrong_code_never_cancels_and_the_correct_code_still_can(): void
    {
        $data = $this->createGuestOrder('cod');
        $token = $data['cancellation_token'];

        $this->requestCode($token)->assertOk();

        $this->cancelWithCode($token, '000000')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNotSame(Order::ORDER_STATUS_CANCELLED, $data['order']->fresh()->status, 'A rejected cancellation must not change the order status.');
        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at);

        $this->cancelWithCode($token, '654321')
            ->assertOk()
            ->assertJsonPath('data.order.status', Order::ORDER_STATUS_CANCELLED);
    }

    /** @test */
    public function a_code_that_was_never_requested_cannot_cancel(): void
    {
        $data = $this->createGuestOrder('cod');

        $this->cancelWithCode($data['cancellation_token'], '654321')->assertStatus(422);

        $this->assertNotSame(Order::ORDER_STATUS_CANCELLED, $data['order']->fresh()->status, 'A rejected cancellation must not change the order status.');
        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at);
    }

    /** @test */
    public function a_code_whose_state_expired_must_be_re_requested(): void
    {
        $data = $this->createGuestOrder('cod');
        $token = $data['cancellation_token'];

        $this->requestCode($token)->assertOk();

        // Simulate the short code TTL (10 minutes) instead of waiting.
        Cache::forget($this->stateKey($token));

        $this->cancelWithCode($token, '654321')->assertStatus(422);

        $this->assertNotSame(Order::ORDER_STATUS_CANCELLED, $data['order']->fresh()->status, 'A rejected cancellation must not change the order status.');
        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at);
    }

    /** @test */
    public function the_static_bypass_is_checked_first_outside_production(): void
    {
        $data = $this->createGuestOrder('cod');

        // No code was requested: only the universal bypass can accept this.
        $this->cancelWithCode($data['cancellation_token'], '999999')->assertOk();

        $history = $this->cancellationHistory($data['order']->id);
        $this->assertSame('static', $history->metadata['possession_proof'] ?? null);
    }

    /** @test */
    public function a_configured_master_code_is_not_an_enable_switch_for_the_bypass(): void
    {
        // ADR 0003: MASTER_OTP is a value override. With the Local OTP switch
        // off, the value alone must never unlock a cancellation.
        config(['auth.local_otp_enabled' => false, 'auth.master_otp' => '999999']);

        $data = $this->createGuestOrder('cod');
        $token = $data['cancellation_token'];

        $this->requestCode($token)->assertOk();
        $this->cancelWithCode($token, '999999')->assertStatus(422);

        $this->assertNotSame(Order::ORDER_STATUS_CANCELLED, $data['order']->fresh()->status, 'A rejected cancellation must not change the order status.');
        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at);
    }

    /** @test */
    public function a_provider_verified_code_records_the_otp_proof(): void
    {
        $data = $this->createGuestOrder('cod');
        $token = $data['cancellation_token'];

        $this->requestCode($token)->assertOk();

        // 654321 is the LOCAL provider code; the static bypass only accepts
        // 999999, so this can only pass provider verification.
        $this->cancelWithCode($token, '654321')->assertOk();

        $history = $this->cancellationHistory($data['order']->id);
        $this->assertSame('otp', $history->metadata['possession_proof'] ?? null);
        $this->assertSame('guest_cancellation_link', $history->metadata['trigger'] ?? null);
    }

    /** @test */
    public function five_failed_attempts_lock_the_token_hash_for_twenty_four_hours(): void
    {
        $data = $this->createGuestOrder('cod');
        $token = $data['cancellation_token'];

        $this->requestCode($token)->assertOk();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->cancelWithCode($token, '000000')
                ->assertStatus(422)
                ->assertJsonPath('success', false);
        }

        // The FIFTH failure is the lock: 429 immediately.
        $this->cancelWithCode($token, '000000')->assertStatus(429);

        // The lock cannot be escaped by requesting a fresh code...
        $this->requestCode($token)->assertStatus(429);
        // ...nor by presenting the correct code afterwards.
        $this->cancelWithCode($token, '654321')->assertStatus(429);

        $this->assertTrue(Cache::has($this->lockKey($token)));
        $this->assertSame(
            24,
            (int) config('guest_checkout.cancellation_otp.lockout_hours'),
            'The approved lockout window is 24 hours.'
        );

        $this->assertNotSame(Order::ORDER_STATUS_CANCELLED, $data['order']->fresh()->status, 'A rejected cancellation must not change the order status.');
        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at);
    }

    /** @test */
    public function the_lockout_is_scoped_to_one_token_hash(): void
    {
        $locked = $this->createGuestOrder('cod');
        $this->requestCode($locked['cancellation_token'])->assertOk();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->cancelWithCode($locked['cancellation_token'], '000000');
        }

        $other = $this->createGuestOrder('cod');
        $this->requestCode($other['cancellation_token'])->assertOk();
        $this->cancelWithCode($other['cancellation_token'], '654321')->assertOk();
    }

    /** @test */
    public function a_used_or_expired_link_cannot_spend_an_sms(): void
    {
        $used = $this->createGuestOrder('cod');
        $used['order']->forceFill(['guest_cancellation_token_used_at' => now()])->save();

        $usedResponse = $this->requestCode($used['cancellation_token'])->assertStatus(404);

        $expired = $this->createGuestOrder('cod');
        $expired['order']->forceFill([
            'guest_cancellation_token_expires_at' => now()->subMinute(),
        ])->save();

        $expiredResponse = $this->requestCode($expired['cancellation_token'])->assertStatus(404);

        // No oracle: used, expired and unknown links are indistinguishable.
        $unknownResponse = $this->requestCode(str_repeat('a', 43))->assertStatus(404);

        $this->assertSame($unknownResponse->json(), $usedResponse->json());
        $this->assertSame($unknownResponse->json(), $expiredResponse->json());
    }

    /** @test */
    public function a_non_cancellable_order_cannot_request_a_code(): void
    {
        $data = $this->createGuestOrder('cod');

        $data['order']->forceFill([
            'status' => Order::ORDER_STATUS_COMPLETED,
        ])->save();

        $this->requestCode($data['cancellation_token'])->assertStatus(422);
    }

    /** @test */
    public function an_unknown_token_cannot_request_a_code(): void
    {
        $this->requestCode(str_repeat('a', 43))->assertStatus(404);
        $this->requestCode('short')->assertStatus(404);
    }

    /** @test */
    public function the_cancellation_link_ttl_defaults_to_twenty_four_hours(): void
    {
        $data = $this->createGuestOrder('cod');

        $expiresAt = $data['order']->fresh()->guest_cancellation_token_expires_at;

        $this->assertNotNull($expiresAt);
        $this->assertEqualsWithDelta(
            24 * 3600,
            now()->diffInSeconds($expiresAt, false),
            60,
            'The approved cancellation link lifetime is 24 hours.'
        );
    }

    /** @test */
    public function the_code_request_surface_is_throttled_per_token(): void
    {
        $data = $this->createGuestOrder('cod');
        $token = $data['cancellation_token'];

        // Five sends are allowed, each from a DIFFERENT IP: only the
        // token-scoped counter can explain the sixth response.
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.' . $i]);
            $this->requestCode($token)->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.99']);
        $this->requestCode($token)->assertStatus(429);
    }

    /** @test */
    public function the_code_request_surface_is_throttled_per_ip(): void
    {
        // Five unknown links from one IP exhaust the IP budget; distinct
        // token strings keep every per-token counter separate.
        for ($i = 0; $i < 5; $i++) {
            $this->requestCode(str_repeat(chr(97 + $i), 43))->assertStatus(404);
        }

        $this->requestCode(str_repeat('z', 43))->assertStatus(429);
    }

    /** @test */
    public function production_rejects_the_static_bypass_and_the_inert_local_gateway_fails_closed(): void
    {
        $data = $this->createGuestOrder('cod');
        $token = $data['cancellation_token'];

        // Simulate production on the app container (ADR 0003 env variant).
        $this->app['env'] = 'production';
        $this->assertTrue(app()->environment('production'));

        // Even with the Local OTP switch on and a master value configured,
        // production must never accept the static bypass.
        config(['auth.local_otp_enabled' => true, 'auth.master_otp' => '999999']);
        $this->cancelWithCode($token, '999999')->assertStatus(422);
        $this->assertNotSame(
            Order::ORDER_STATUS_CANCELLED,
            $data['order']->fresh()->status,
            'A production static-bypass attempt must not cancel.'
        );

        // The Local gateway is inert in production (#88): a code request
        // through it is a failed dispatch (502), never a fake success.
        $this->requestCode($token)->assertStatus(502);
        $this->cancelWithCode($token, '654321')->assertStatus(422);
        $this->assertNotSame(
            Order::ORDER_STATUS_CANCELLED,
            $data['order']->fresh()->status,
            'A production code that was never delivered must not cancel.'
        );
    }

    /** @test */
    public function production_never_falls_back_to_a_silent_local_gateway(): void
    {
        $data = $this->createGuestOrder('cod');

        // A misconfigured provider in production must surface as a LOUD 502
        // failed dispatch (#88 classification): the pre-#89-review binding
        // silently substituted the LocalGateway, whose answer looks like a
        // successful send while nothing was sent.
        $this->app['env'] = 'production';
        config(['auth.active_otp_gateway' => 'nonexistent']);

        $this->requestCode($data['cancellation_token'])
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('guest_checkout.cancel_otp_failed'));

        // Nothing was stored: the state key must not exist for a failed send.
        $this->assertFalse(Cache::has($this->stateKey($data['cancellation_token'])));
    }

    /** @test */
    public function a_provider_outage_is_reported_without_leaking_the_phone_number(): void
    {
        $data = $this->createGuestOrder('cod');
        $token = $data['cancellation_token'];

        $logEvents = [];
        Log::listen(function ($event) use (&$logEvents) {
            $logEvents[] = ['message' => (string) $event->message, 'context' => $event->context];
        });

        // A configured-but-broken provider (constructor throws): non-production
        // falls back to the committed LocalGateway, which WORKS — so prove the
        // leak rule where an outage actually occurs: a startVerification that
        // throws. Gateway errors must answer the translated failure and must
        // never place the recipient number in any log line.
        $gateway = Mockery::mock(\Marvel\Otp\OtpInterface::class);

        $gateway->shouldReceive('startVerification')
            ->andThrow(new \RuntimeException('Twilio: unable to create record for +201000000001'));

        $this->app->instance(\Marvel\Otp\OtpInterface::class, $gateway);

        $this->requestCode($token)
            ->assertStatus(502)
            ->assertJsonPath('message', __('guest_checkout.cancel_otp_failed'));

        $outageLogs = array_filter($logEvents, fn ($e) => str_contains($e['message'], 'OTP dispatch failed'));
        $this->assertNotEmpty($outageLogs, 'The outage must be observable.');

        $flat = collect($logEvents)->map(fn ($e) => $e['message'] . json_encode($e['context']))->implode("\n");
        $this->assertStringNotContainsString(
            '+201000000001',
            $flat,
            'The provider error text must not carry the phone number into logs.'
        );
    }

    /** @test */
    public function the_cancellation_otp_strings_exist_in_english_and_arabic(): void
    {
        $keys = [
            'cancel_otp_sent',
            'cancel_otp_invalid',
            'cancel_otp_expired',
            'cancel_otp_locked',
            'cancel_otp_unavailable',
            'cancel_otp_failed',
        ];

        foreach (['en', 'ar'] as $locale) {
            foreach ($keys as $key) {
                $value = trans('guest_checkout.' . $key, [], $locale);

                $this->assertIsString($value);
                $this->assertNotSame(
                    'guest_checkout.' . $key,
                    $value,
                    "Missing {$locale} translation: {$key}"
                );
                $this->assertNotSame('', trim($value), "Empty {$locale} translation: {$key}");
            }
        }
    }

    /**
     * D5 (design record §4): an order with no phone cannot receive a code —
     * a CLIENT-FACING 422 (cancel_otp_unavailable), not a provider outage.
     * Regression for the review finding: the phone resolution ran inside
     * the provider try/catch, so this 422 was swallowed and misreported as
     * 502 cancel_otp_failed.
     *
     * @test */
    public function an_order_without_a_phone_answers_422_not_a_provider_outage(): void
    {
        $data = $this->createGuestOrder('cod');
        $data['order']->forceFill(['user_phone' => null])->save();

        $this->requestCode($data['cancellation_token'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('guest_checkout.cancel_otp_unavailable'));

        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at);
    }
}
