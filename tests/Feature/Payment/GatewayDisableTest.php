<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\Events\PaymentSucceeded;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Transaction;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * §46 gateway-disable semantics + F-1 payment-permission hardening + admin
 * refund endpoint.
 *
 * - Disabling a gateway mid-payment never blocks the in-flight verify /
 *   callback completion; it only blocks NEW initiations (422).
 * - Manual mark-paid requires payments.mark_paid: update-order-status alone
 *   is a 403.
 * - POST /api/v1/admin/payments/{order}/refund requires payments.refund and
 *   is fail-closed (422) on every validation or provider refusal.
 */
class GatewayDisableTest extends CurrencyTestCase
{
    private const CALLBACK = '/api/v1/general/checkout/callback';
    private const MARK_COD = '/api/v1/general/checkout/cod/';
    private const ADMIN_REFUND = '/api/v1/admin/payments/';

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private bool $currencySeeded = false;

    private function makeKwdOrder(float $subtotal = 100.0): Order
    {
        if (!$this->currencySeeded) {
            $this->seedCurrencyData();

            app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
            app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

            $this->currencySeeded = true;
        }

        config(['payment.gateways.myfatoorah.api_key' => 'test-key']);

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => $subtotal]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'Disable Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Disable Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

        return $order->fresh();
    }

    private function makePendingTxn(Order $order, string $payId): Transaction
    {
        return $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => (float) $order->total_price,
            'currency' => 'KWD',
            'gateway_transaction_id' => $payId,
            'invoice_id' => $payId,
        ]);
    }

    private function makePaidCompletedOrder(string $payId, float $total = 100.0): array
    {
        $order = $this->makeKwdOrder($total);

        $txn = $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => $total,
            'currency' => 'KWD',
            'gateway_transaction_id' => $payId,
            'invoice_id' => $payId,
            'paid_at' => now(),
        ]);

        $order->update([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'paid_at' => now(),
        ]);

        return [$order->fresh(), $txn->fresh()];
    }

    /**
     * Mock the provider transport so the REAL gateway adapter code runs.
     */
    private function mockProviderTransport(?array $checkInvoice = null, ?array $makeRefund = null): void
    {
        $mock = \Mockery::mock(\App\Services\General\MyfatoraService::class);

        if ($checkInvoice !== null) {
            $mock->shouldReceive('checkInvoice')->andReturn($checkInvoice);
        } else {
            $mock->shouldReceive('checkInvoice')->andReturnNull()->byDefault();
        }

        if ($makeRefund !== null) {
            $mock->shouldReceive('makeRefund')->andReturn($makeRefund);
        }

        $this->app->instance(\App\Services\General\MyfatoraService::class, $mock);
    }

    private function paidVerifyPayload(string $payId, float $amount): array
    {
        return [
            'IsSuccess' => true,
            'Message' => 'Paid',
            'Data' => [
                'InvoiceId' => $payId,
                'InvoiceStatus' => 'Paid',
                'InvoiceValue' => $amount,
                'DisplayCurrencyIso' => 'KWD',
                'InvoiceURL' => 'https://pay.example.test/' . $payId,
            ],
        ];
    }

    private function refundConfirmedPayload(string $refundId): array
    {
        return [
            'IsSuccess' => true,
            'Message' => 'Refunded',
            'Data' => [
                'RefundId' => $refundId,
                'RefundStatus' => 'Refunded',
            ],
        ];
    }

    private function disableGateway(string $code): void
    {
        $settings = Settings::query()->firstOrFail();
        $options = $settings->options ?? [];
        $gateways = $options['payment_gateways'] ?? [];
        $gateways[$code] = array_merge(is_array($gateways[$code] ?? null) ? $gateways[$code] : [], ['enabled' => false]);
        $options['payment_gateways'] = $gateways;
        $settings->options = $options;
        $settings->save();
    }

    private function actingAdminWith(array $permissions)
    {
        $admin = $this->createUserWithPermissions($permissions, 'admin');
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function makePendingCodOrder(): Order
    {
        $order = $this->makeKwdOrder(130.0);

        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 130.0,
            'currency' => 'KWD',
            'uuid' => (string) Str::uuid(),
        ]);

        return $order->fresh();
    }

    // -----------------------------------------------------------------
    // §46 — disable mid-payment still completes; new initiation 422
    // -----------------------------------------------------------------

    /** @test */
    public function disable_mid_payment_still_completes_then_new_initiation_422(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $order = $this->makeKwdOrder(100.0);
        $txn = $this->makePendingTxn($order, 'PAY-DIS-1');

        $this->mockProviderTransport($this->paidVerifyPayload('PAY-DIS-1', 100.0));

        // Disable AFTER the pending transaction exists (mid-payment).
        $this->disableGateway('myfatoorah');
        $this->assertFalse(app(\App\Services\Payment\GatewaySettingsService::class)->isEnabled('myfatoorah'));

        // In-flight verify/callback STILL completes.
        $response = $this->get(self::CALLBACK . '?paymentId=PAY-DIS-1&type=mobile');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $txn->fresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);

        // A NEW initiation through the same (disabled) gateway is a 422 —
        // the exact code path the checkout controller delegates to.
        $order2 = $this->makeKwdOrder(50.0);
        $request = \Illuminate\Http\Request::create('/dummy', 'POST');
        $request->setUserResolver(fn () => $order2->user);

        $initiation = app(\App\Services\Payment\PaymentCheckoutHandler::class)
            ->handleOnlinePayment($request, $order2->fresh(), 50.0, 'myfatoorah');

        $this->assertSame(422, $initiation->status());
        $this->assertFalse((bool) $initiation->getData()->success);
        $this->assertStringContainsString(
            'unavailable',
            strtolower((string) $initiation->getData()->message)
        );
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order2->id,
            'payment_method' => 'myfatoorah',
        ]);
    }

    // -----------------------------------------------------------------
    // F-1 — mark-paid authorization
    // -----------------------------------------------------------------

    /** @test */
    public function mark_paid_forbidden_with_only_update_order_status(): void
    {
        $order = $this->makePendingCodOrder();
        $this->actingAdminWith(['update-order-status']);

        $this->postJson(self::MARK_COD . $order->id . '/mark-paid')
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function mark_paid_succeeds_with_dedicated_permission_and_records_audit(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $order = $this->makePendingCodOrder();
        $admin = $this->actingAdminWith(['payments.mark_paid']);

        $this->postJson(self::MARK_COD . $order->id . '/mark-paid', ['reason' => 'Cash collected on delivery'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $order->transactions()->latest()->first()->status);

        $history = \App\Models\OrderStatusHistory::where('order_id', $order->id)
            ->where('new_status', 'completed')
            ->latest('id')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame($admin->id, (int) $history->changed_by);
        $this->assertSame('admin', $history->changed_by_type);
        $this->assertStringContainsString('Cash collected on delivery', (string) $history->notes);
        $this->assertSame('manual_mark_paid', $history->metadata['action'] ?? null);
        $this->assertSame('cod', $history->metadata['payment_method'] ?? null);
        $this->assertNotNull($history->metadata['transaction_id'] ?? null);
        $this->assertSame('Cash collected on delivery', $history->metadata['reason'] ?? null);
    }

    // -----------------------------------------------------------------
    // Admin refund endpoint
    // -----------------------------------------------------------------

    /** @test */
    public function refund_full_success_updates_states_and_ledger(): void
    {
        [$order, $txn] = $this->makePaidCompletedOrder('PAY-RF-1', 100.0);
        $this->actingAdminWith(['payments.refund']);
        $this->mockProviderTransport(null, $this->refundConfirmedPayload('RF-1'));

        $response = $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', [
            'amount' => 100.0,
            'reason' => 'Customer request',
            'idempotency_key' => 'refund-key-1',
        ]);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $response->assertJsonPath('data.full_refund', true);
        $this->assertEquals(100.0, $response->json('data.refunded_amount'));
        $this->assertEquals(0.0, $response->json('data.remaining_refundable'));
        $response->assertJsonPath('data.idempotent_replay', false);

        $this->assertSame('refunded', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $order->fresh()->payment_status);
        $this->assertSame('completed', $order->fresh()->status);

        $ledger = $txn->fresh()->gateway_response['_refunds'] ?? [];
        $this->assertCount(1, $ledger);
        $this->assertSame('refund-key-1', $ledger[0]['idempotency_key']);
        $this->assertSame(100.0, (float) $ledger[0]['amount']);
        $this->assertSame('RF-1', $ledger[0]['provider_ref']);

        $history = \App\Models\OrderStatusHistory::where('order_id', $order->id)
            ->latest('id')
            ->first();
        $this->assertSame('gateway_refund', $history->metadata['action'] ?? null);
        $this->assertSame('admin', $history->changed_by_type);
    }

    /** @test */
    public function refund_partial_keeps_order_payable_then_exceeding_amount_422(): void
    {
        [$order, $txn] = $this->makePaidCompletedOrder('PAY-RF-2', 100.0);
        $this->actingAdminWith(['payments.refund']);
        $this->mockProviderTransport(null, $this->refundConfirmedPayload('RF-2'));

        $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', [
            'amount' => 40.0,
            'idempotency_key' => 'refund-key-2a',
        ])->assertStatus(200)->assertJsonPath('data.full_refund', false);

        $this->assertSame('partially_refunded', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->fresh()->payment_status);
        $this->assertSame('completed', $order->fresh()->status);

        // 40 used of 100 → 70 exceeds the remaining 60.
        $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', [
            'amount' => 70.0,
            'idempotency_key' => 'refund-key-2b',
        ])->assertStatus(422);

        $this->assertSame('partially_refunded', $txn->fresh()->status);
        $this->assertCount(1, $txn->fresh()->gateway_response['_refunds'] ?? []);
    }

    /** @test */
    public function refund_duplicate_idempotency_key_returns_original_without_second_provider_call(): void
    {
        [$order, $txn] = $this->makePaidCompletedOrder('PAY-RF-3', 100.0);
        $this->actingAdminWith(['payments.refund']);

        $mock = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $mock->shouldReceive('checkInvoice')->andReturnNull()->byDefault();
        $mock->shouldReceive('makeRefund')->once()->andReturn($this->refundConfirmedPayload('RF-3'));
        $this->app->instance(\App\Services\General\MyfatoraService::class, $mock);

        $payload = ['amount' => 25.0, 'idempotency_key' => 'refund-key-3'];

        $first = $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', $payload);
        $first->assertStatus(200)->assertJsonPath('data.idempotent_replay', false);

        $second = $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', $payload);
        $second->assertStatus(200)->assertJsonPath('data.idempotent_replay', true);
        $this->assertEquals(25.0, $second->json('data.refunded_amount'));

        // Single provider call: exactly one ledger entry.
        $this->assertCount(1, $txn->fresh()->gateway_response['_refunds'] ?? []);
        $this->assertSame('partially_refunded', $txn->fresh()->status);
    }

    /** @test */
    public function refund_forbidden_without_dedicated_permission(): void
    {
        [$order] = $this->makePaidCompletedOrder('PAY-RF-4', 100.0);
        $this->actingAdminWith(['update-order-status']);

        $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', [
            'amount' => 10.0,
            'idempotency_key' => 'refund-key-4',
        ])->assertStatus(403);
    }

    /** @test */
    public function refund_422_when_gateway_disabled_or_unconfigured(): void
    {
        [$order] = $this->makePaidCompletedOrder('PAY-RF-5', 100.0);
        $this->actingAdminWith(['payments.refund']);
        $this->mockProviderTransport();

        // Disabled gateway: new outbound provider calls fail closed.
        $this->disableGateway('myfatoorah');

        $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', [
            'amount' => 10.0,
            'idempotency_key' => 'refund-key-5a',
        ])->assertStatus(422);

        $this->assertSame('paid', $order->transactions()->latest()->first()->status);
    }

    /** @test */
    public function refund_422_for_unpaid_order_and_validation_failures(): void
    {
        $this->actingAdminWith(['payments.refund']);
        $this->mockProviderTransport();

        // Pending (unpaid) order.
        $order = $this->makeKwdOrder(100.0);
        $this->makePendingTxn($order, 'PAY-RF-6');

        $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', [
            'amount' => 10.0,
            'idempotency_key' => 'refund-key-6',
        ])->assertStatus(422);

        // Paid order but FormRequest validation failures.
        [$paidOrder] = $this->makePaidCompletedOrder('PAY-RF-7', 100.0);

        $this->postJson(self::ADMIN_REFUND . $paidOrder->id . '/refund', [
            'amount' => 0,
            'idempotency_key' => 'refund-key-7a',
        ])->assertStatus(422);

        $this->postJson(self::ADMIN_REFUND . $paidOrder->id . '/refund', [
            'amount' => 10.0,
        ])->assertStatus(422);

        $this->assertSame('paid', $paidOrder->transactions()->latest()->first()->status);
    }

    /** @test */
    public function refund_422_when_provider_refuses_and_no_state_changes(): void
    {
        [$order, $txn] = $this->makePaidCompletedOrder('PAY-RF-8', 100.0);
        $this->actingAdminWith(['payments.refund']);
        $this->mockProviderTransport(null, [
            'IsSuccess' => true,
            'Message' => 'Refund failed',
            'Data' => ['RefundStatus' => 'RefundFailed'],
        ]);

        $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', [
            'amount' => 10.0,
            'idempotency_key' => 'refund-key-8',
        ])->assertStatus(422);

        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->fresh()->payment_status);
        $this->assertSame([], $txn->fresh()->gateway_response['_refunds'] ?? []);
    }
}
