<?php

namespace Tests\Feature\Guest;

use App\Http\Middleware\GuestRateLimit;
use App\Models\GuestIdentity;
use App\Services\Guest\GuestIdentityService;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentGatewayRegistry;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Mockery;

class GuestSecurityContractTest extends GuestCheckoutTestCase
{
    /** @test */
    public function an_ambiguous_gateway_dispatch_stays_unresolved_and_blocks_a_second_session(): void
    {
        $gateway = Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('supportsCurrency')->andReturn(true);
        $gateway->shouldReceive('name')->andReturn('mock');
        $gateway->shouldReceive('code')->andReturn('mock');
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('createInvoice')->andThrow(new \RuntimeException('connection reset'));

        $this->mock(PaymentGatewayFactory::class, function ($mock) use ($gateway) {
            $mock->shouldReceive('make')->andReturn($gateway);
        });
        $this->mock(PaymentGatewayRegistry::class, function ($mock) {
            $mock->shouldReceive('canInitiate')->andReturn(['ok' => true, 'reason' => 'ok']);
        });

        $data = $this->createGuestOrder('online');

        $this->initiateGuestOnline($data['order']->id, 'ambiguous-key')->assertStatus(500);

        $txn = Transaction::query()->where('order_id', $data['order']->id)->firstOrFail();
        $this->assertSame('pending', $txn->status, 'Ambiguous dispatch must stay unresolved');
        $this->assertSame('dispatch_unknown', $txn->attempt_state);

        $this->initiateGuestOnline($data['order']->id, 'ambiguous-key-2')->assertStatus(409);
        $this->assertSame(1, Transaction::query()->where('order_id', $data['order']->id)->count());
    }

    /** @test */
    public function a_failed_idempotency_claim_can_be_retried_with_the_same_key(): void
    {
        $this->startGuestSession();
        $guestId = $this->currentGuestId();
        $service = app(\App\Services\Guest\GuestIdempotencyService::class);

        $first = $service->claim($guestId, 'checkout', 'retry-key', ['cart' => 1]);
        $this->assertFalse($first['replay']);
        $service->fail($first['claim'], 'transient');

        $second = $service->claim($guestId, 'checkout', 'retry-key', ['cart' => 1]);
        $this->assertFalse($second['replay'], 'A handled failure must be retryable, not a permanent 409');
    }

    /** @test */
    public function guest_fast_shipping_lines_are_rejected_instead_of_silently_dropped(): void
    {
        $fast = $this->makeProduct(['is_fast_shipping_available' => true]);
        $this->startGuestSession();

        $this->addToGuestCart([
            'product_id' => $fast->id,
            'quantity' => 1,
            'shipping_method' => 'fast',
        ])->assertStatus(422);

        $this->assertSame(0, $this->guestItemCount());
    }

    /** @test */
    public function session_endpoint_returns_the_csrf_token_for_cross_host_frontends(): void
    {
        $response = $this->postJson(self::PREFIX . '/session');
        $response->assertOk();

        $token = $response->json('data.csrf_token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $csrfCookie = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === GuestIdentityService::CSRF_COOKIE) {
                $csrfCookie = $cookie->getValue();
            }
        }

        $this->assertSame($csrfCookie, $token);
    }

    /** @test */
    public function guest_tokens_are_long_random_and_url_safe(): void
    {
        $tokens = [];

        for ($i = 0; $i < 3; $i++) {
            $this->guestCookies = [];
            $this->startGuestSession(true);
            $token = $this->guestCookies[GuestIdentityService::COOKIE];

            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
            $tokens[] = $token;
        }

        $this->assertCount(3, array_unique($tokens), 'Tokens must never repeat');
    }

    /** @test */
    public function expired_guest_tokens_are_rejected(): void
    {
        $this->startGuestSession();
        $this->currentGuestId(); // ensure identity exists

        GuestIdentity::query()->whereKey($this->currentGuestId())->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->asGuest()->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('data.cart', null);
    }

    /** @test */
    public function checkout_requires_the_csrf_header(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $this->asGuest()->postJson(self::PREFIX . '/checkout', $this->checkoutPayload())
            ->assertStatus(419);

        $this->assertSame(0, $this->currentGuestOrders()->count());
    }

    /** @test */
    public function pay_at_cashier_is_rejected_for_guests_at_every_layer(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        // OrderCreateRequest allows the value globally; the guest layer must
        // fail closed before any order is created.
        $this->submitGuestCheckout(['payment_method' => 'pay_at_cashier'], 'cashier-key')
            ->assertStatus(422);

        $this->assertSame(0, $this->currentGuestOrders()->count());
    }

    /** @test */
    public function guest_payment_method_cannot_be_switched_after_order_creation(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $this->submitGuestCheckout(['payment_method' => 'cod'], 'method-key')->assertCreated();

        $order = $this->currentGuestOrders()->firstOrFail();

        $res = $this->asGuest()->postJson(
            self::PREFIX . '/orders/' . $order->id . '/payment',
            ['payment_method' => 'online'],
            $this->csrfHeaders()
        );
        $res->assertStatus(422);

        $this->assertSame(0, Transaction::query()->where('order_id', $order->id)->count());
    }

    /** @test */
    public function a_provider_redirect_never_proves_payment_for_a_guest_attempt(): void
    {
        $data = $this->createOnlineGuestOrder();

        // The lifecycle attempt redirect is UX-only.
        $response = $this->getJson(
            '/api/v1/general/checkout/callback?paymentId=' . urlencode($data['txn']->gateway_transaction_id)
        );

        $response->assertOk();

        $order = $data['order']->fresh();
        $this->assertSame(Order::ORDER_STATUS_PENDING, $order->status);
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->payment_status);
        $this->assertSame('pending', $data['txn']->fresh()->status);
    }

    /** @test */
    public function cors_is_credentialed_without_a_wildcard(): void
    {
        config([
            'cors.supports_credentials' => true,
            'cors.allowed_origins' => ['https://shop.example.test'],
        ]);

        $this->assertTrue((bool) config('cors.supports_credentials'));
        $this->assertNotContains('*', (array) config('cors.allowed_origins'));

        $this->withHeaders(['Origin' => 'https://evil.example.test'])
            ->postJson(self::PREFIX . '/session')
            ->assertStatus(403);
    }

    /** @test */
    public function limiter_backend_failure_fails_closed(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('limiter')->andThrow(new \RuntimeException('cache unavailable'));

        $middleware = new GuestRateLimit($limiter);
        $response = $middleware->handle(
            Request::create('/api/v1/general/guest/checkout', 'POST'),
            fn () => response()->json(['ok' => true]),
            'guest_checkout'
        );

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertFalse((bool) $response->getData(true)['success']);
    }

    private function createOnlineGuestOrder(): array
    {
        $gateway = Mockery::mock(\App\Services\Payment\Contracts\PaymentGatewayContract::class);
        $gateway->shouldReceive('supportsCurrency')->andReturn(true);
        $gateway->shouldReceive('name')->andReturn('mock');
        $gateway->shouldReceive('code')->andReturn('mock');
        $gateway->shouldReceive('isConfigured')->andReturn(true);
        $gateway->shouldReceive('createInvoice')->andReturn(new \App\DTOs\GatewayResult(
            true,
            'https://pay.example.test/session',
            'INV-REDIRECT-1',
            null,
            'EGP',
            'pending',
            null,
            []
        ));

        $this->mock(\App\Services\Payment\PaymentGatewayFactory::class, function ($mock) use ($gateway) {
            $mock->shouldReceive('make')->andReturn($gateway);
        });
        $this->mock(\App\Services\Payment\PaymentGatewayRegistry::class, function ($mock) {
            $mock->shouldReceive('canInitiate')->andReturn(['ok' => true, 'reason' => 'ok']);
        });

        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $this->submitGuestCheckout([], 'redirect-key')->assertCreated();
        $order = $this->currentGuestOrders()->firstOrFail();

        $this->asGuest()->postJson(
            self::PREFIX . '/orders/' . $order->id . '/payment',
            ['payment_method' => 'online', 'gateway' => 'stripe'],
            array_merge($this->csrfHeaders(), ['Idempotency-Key' => 'redirect-pay'])
        )->assertOk();

        return [
            'order' => $order,
            'txn' => Transaction::query()->where('order_id', $order->id)->firstOrFail(),
        ];
    }
}
