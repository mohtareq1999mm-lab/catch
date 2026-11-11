<?php

namespace Tests\Feature\Guest;

use App\DTOs\GatewayResult;
use App\Models\GuestIdentity;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentCompletionService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentGatewayRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use App\Models\Invoice;
use Illuminate\Cache\RateLimiting\Limit;
use Mockery;

class GuestPaymentAndOrderAccessTest extends GuestCheckoutTestCase
{
    private function createOrder(string $paymentMethod = 'online', int $quantity = 1): array
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $response = $this->submitGuestCheckout([
            'payment_method' => $paymentMethod,
        ], 'order-key-' . $paymentMethod . '-' . uniqid());

        $response->assertCreated();

        return [
            'order' => $this->currentGuestOrders()->firstOrFail(),
            'tracking_token' => $response->json('data.tracking_token'),
            'cancellation_token' => $response->json('data.cancellation_token'),
        ];
    }

    private function mockOnlineGateway(string $providerRef = 'INV-GUEST-1'): void
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
                $providerRef,
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

    private function initiateOnline(int $orderId, string $key, string $gateway = 'stripe')
    {
        return $this->asGuest()->postJson(
            self::PREFIX . '/orders/' . $orderId . '/payment',
            ['payment_method' => 'online', 'gateway' => $gateway],
            array_merge($this->csrfHeaders(), ['Idempotency-Key' => $key])
        );
    }

    /** @test */
    public function guest_online_payment_uses_the_canonical_attempt_lifecycle(): void
    {
        $this->mockOnlineGateway('INV-GUEST-1');
        $data = $this->createOrder('online');

        $response = $this->initiateOnline($data['order']->id, 'pay-key-1');
        $response->assertOk();
        $this->assertSame('https://pay.example.test/session', $response->json('data.url'));

        $txn = Transaction::query()->where('order_id', $data['order']->id)->firstOrFail();
        $this->assertNull($txn->user_id, 'Guest payment transactions must not require a user');
        $this->assertSame('pending', $txn->status);
        $this->assertSame('session_created', $txn->attempt_state);
        $this->assertSame('INV-GUEST-1', $txn->gateway_transaction_id);
        $this->assertNotNull($txn->dispatch_reference);
    }

    /** @test */
    public function a_second_online_initiation_is_blocked_while_the_first_is_unresolved(): void
    {
        $this->mockOnlineGateway();
        $data = $this->createOrder('online');

        $this->initiateOnline($data['order']->id, 'pay-key-a')->assertOk();

        $this->initiateOnline($data['order']->id, 'pay-key-b')
            ->assertStatus(409);

        $this->assertSame(
            1,
            Transaction::query()->where('order_id', $data['order']->id)->count(),
            'SC-1 must prevent a second payable session'
        );
    }

    /** @test */
    public function cod_payment_is_idempotent_and_never_marks_the_order_paid(): void
    {
        $data = $this->createOrder('cod');
        $order = $data['order'];

        $first = $this->asGuest()->postJson(
            self::PREFIX . '/orders/' . $order->id . '/payment',
            ['payment_method' => 'cod'],
            array_merge($this->csrfHeaders(), ['Idempotency-Key' => 'cod-pay-1'])
        );
        $first->assertOk();

        $second = $this->asGuest()->postJson(
            self::PREFIX . '/orders/' . $order->id . '/payment',
            ['payment_method' => 'cod'],
            array_merge($this->csrfHeaders(), ['Idempotency-Key' => 'cod-pay-2'])
        );
        $second->assertOk();

        $this->assertSame(1, Transaction::query()->where('order_id', $order->id)->where('payment_method', 'cod')->count());
        $this->assertNull(Transaction::query()->where('order_id', $order->id)->first()->user_id);

        $order->refresh();
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->payment_status);
        $this->assertNotSame(Order::PAYMENT_STATUS_SUCCESS, $order->payment_status);
    }

    /** @test */
    public function payment_for_another_guests_order_is_not_found(): void
    {
        $this->mockOnlineGateway();
        $data = $this->createOrder('online');

        // New browser (new identity) tries the first guest's order id.
        $this->guestCookies = [];
        $this->startGuestSession(true);

        $this->initiateOnline($data['order']->id, 'foreign-key')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    /** @test */
    public function canonical_completion_completes_a_guest_order_and_creates_a_nullable_user_invoice(): void
    {
        $this->mockOnlineGateway('INV-GUEST-2');
        $data = $this->createOrder('online');
        $order = $data['order'];

        $this->initiateOnline($order->id, 'complete-key')->assertOk();

        $txn = Transaction::query()->where('order_id', $order->id)->firstOrFail();

        DB::transaction(function () use ($order, $txn) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedTxn = Transaction::query()->whereKey($txn->id)->lockForUpdate()->firstOrFail();

            app(PaymentCompletionService::class)->completeLocked(
                $lockedTxn,
                $lockedOrder,
                new GatewayResult(
                    true,
                    null,
                    'INV-GUEST-2',
                    (float) $lockedOrder->total_price,
                    'EGP',
                    'paid',
                    null,
                    []
                )
            );
        });

        $order->refresh();
        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $order->status);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->payment_status);
        $this->assertSame('paid', $txn->fresh()->status);

        $invoice = Invoice::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($invoice, 'Payment completion must generate the order invoice');
        $this->assertNull($invoice->user_id, 'Guest invoices keep user_id NULL');
    }

    /** @test */
    public function public_tracking_is_minimal_and_uses_only_the_token(): void
    {
        $data = $this->createOrder('cod');

        $response = $this->postJson(self::PREFIX . '/orders/track', [
            'token' => $data['tracking_token'],
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(
            $data['order']->fresh()->status,
            $response->json('data.order.status')
        );

        $payload = $response->json('data');
        $json = strtolower((string) json_encode($payload));

        foreach (['guest.buyer', '+201000000001', '1 guest street', 'address', 'total_price', 'order_items'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, 'Public tracking leaked: ' . $needle);
        }

        // Never exposes another order: only the token's own order number.
        $this->assertSame($data['order']->order_number, $response->json('data.order.order_number'));
    }

    /** @test */
    public function invalid_tokens_are_rejected_indistinguishably(): void
    {
        $this->postJson(self::PREFIX . '/orders/track', ['token' => str_repeat('a', 43)])
            ->assertStatus(404);

        $this->postJson(self::PREFIX . '/orders/cancel', ['token' => 'short'])
            ->assertStatus(404);
    }

    /** @test */
    public function tracking_expires_thirty_days_after_the_terminal_status(): void
    {
        $data = $this->createOrder('cod');
        $order = $data['order'];

        // Terminal 31 days ago.
        $order->forceFill([
            'status' => Order::ORDER_STATUS_CANCELLED,
            'cancelled_at' => now()->subDays(31),
        ])->save();

        $this->postJson(self::PREFIX . '/orders/track', ['token' => $data['tracking_token']])
            ->assertStatus(404);

        // 10 days ago remains inside the grace window.
        $order->forceFill(['cancelled_at' => now()->subDays(10)])->save();

        $this->postJson(self::PREFIX . '/orders/track', ['token' => $data['tracking_token']])
            ->assertOk();
    }

    /** @test */
    public function single_use_cancellation_link_cancels_once_and_cannot_be_reused(): void
    {
        $data = $this->createOrder('cod');

        $first = $this->postJson(self::PREFIX . '/orders/cancel', [
            'token' => $data['cancellation_token'],
        ]);
        $first->assertOk()->assertJsonPath('data.order.status', Order::ORDER_STATUS_CANCELLED);

        $this->assertSame(Order::ORDER_STATUS_CANCELLED, $data['order']->fresh()->status);
        $this->assertNotNull($data['order']->fresh()->cancelled_at);

        $this->postJson(self::PREFIX . '/orders/cancel', [
            'token' => $data['cancellation_token'],
        ])->assertStatus(422);
    }

    /** @test */
    public function an_unresolved_payment_attempt_prevents_cancellation(): void
    {
        $this->mockOnlineGateway();
        $data = $this->createOrder('online');

        $this->initiateOnline($data['order']->id, 'cancel-race-key')->assertOk();

        $this->postJson(self::PREFIX . '/orders/cancel', [
            'token' => $data['cancellation_token'],
        ])->assertStatus(409);

        $this->assertSame(Order::ORDER_STATUS_PENDING, $data['order']->fresh()->status);
        $this->assertNull($data['order']->fresh()->guest_cancellation_token_used_at);
    }

    /** @test */
    public function a_paid_order_cannot_be_cancelled_through_the_guest_link(): void
    {
        $this->mockOnlineGateway('INV-GUEST-PAID');
        $data = $this->createOrder('online');
        $order = $data['order'];

        $this->initiateOnline($order->id, 'paid-cancel-key')->assertOk();
        $txn = Transaction::query()->where('order_id', $order->id)->firstOrFail();

        DB::transaction(function () use ($order, $txn) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedTxn = Transaction::query()->whereKey($txn->id)->lockForUpdate()->firstOrFail();

            app(PaymentCompletionService::class)->completeLocked(
                $lockedTxn,
                $lockedOrder,
                new GatewayResult(true, null, 'INV-GUEST-PAID', (float) $lockedOrder->total_price, 'EGP', 'paid', null, [])
            );
        });

        $this->postJson(self::PREFIX . '/orders/cancel', [
            'token' => $data['cancellation_token'],
        ])->assertStatus(422);

        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $order->fresh()->status);
    }

    /** @test */
    public function admin_can_revoke_guest_tracking_access(): void
    {
        $data = $this->createOrder('cod');

        $admin = User::create([
            'name' => 'Guest Admin',
            'email' => 'guest-admin-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
        ]);

        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'update-order-status', 'guard_name' => 'api']
        );
        $admin->givePermissionTo($permission);

        Sanctum::actingAs($admin, ['*']);

        $this->deleteJson('/api/v1/admin/orders/' . $data['order']->id . '/guest-tracking')
            ->assertOk();

        $this->postJson(self::PREFIX . '/orders/track', ['token' => $data['tracking_token']])
            ->assertStatus(404);
    }

    /** @test */
    public function public_tracking_rate_limit_is_enforced(): void
    {
        RateLimiter::for('guest_tracking', function ($request) {
            return Limit::perMinute(2)->by('test:' . $request->ip());
        });

        $payload = ['token' => str_repeat('b', 43)];

        $this->postJson(self::PREFIX . '/orders/track', $payload)->assertStatus(404);
        $this->postJson(self::PREFIX . '/orders/track', $payload)->assertStatus(404);
        $third = $this->postJson(self::PREFIX . '/orders/track', $payload);

        $third->assertStatus(429);
        $third->assertHeader('Retry-After');
    }
}
