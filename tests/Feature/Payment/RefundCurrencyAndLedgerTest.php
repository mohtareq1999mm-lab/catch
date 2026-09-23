<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\DTOs\GatewayResult;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentGatewayFactory;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Settings;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * MUST-FIX #5 (refund authorized in the transaction's own currency, catalog
 * drift is a warning) + MUST-FIX #6 (per-txn idempotency scope documented +
 * locked re-read summary + 100-entry ledger cap with prune-oldest).
 */
class RefundCurrencyAndLedgerTest extends CurrencyTestCase
{
    private const ADMIN_REFUND = '/api/v1/admin/payments/';

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

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
            orderData: ['user_id' => $customer->id, 'name' => 'Ledger', 'user_phone' => '01000000000', 'user_email' => $customer->email, 'address' => 'x'],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        return $order->fresh();
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

    private function actingAdminWith(array $permissions)
    {
        $admin = $this->createUserWithPermissions($permissions, 'admin');
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function mockRefunds(array $payloads): void
    {
        $mock = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $mock->shouldReceive('checkInvoice')->andReturnNull()->byDefault();
        $mock->shouldReceive('makeRefund')->times(count($payloads))->andReturn(...$payloads);
        $this->app->instance(\App\Services\General\MyfatoraService::class, $mock);
    }

    private function refundConfirmedPayload(string $refundId): array
    {
        return [
            'IsSuccess' => true,
            'Message' => 'Refunded',
            'Data' => ['RefundId' => $refundId, 'RefundStatus' => 'Refunded'],
        ];
    }

    private function mockAdapterRefund(string $gateway, GatewayResult $result, int $times = 1): void
    {
        $adapter = \Mockery::mock(PaymentGatewayContract::class);
        $adapter->shouldReceive('isConfigured')->andReturn(true);
        $adapter->shouldReceive('refund')->times($times)->andReturn($result);

        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->with($gateway)->andReturn($adapter);
        $this->app->instance(PaymentGatewayFactory::class, $factory);
    }

    // -----------------------------------------------------------------
    // MUST-FIX #5
    // -----------------------------------------------------------------

    /** @test */
    public function refund_after_catalog_switch_succeeds_in_transaction_currency(): void
    {
        [$order, $txn] = $this->makePaidCompletedOrder('PAY-SWITCH-1', 100.0);
        $this->actingAdminWith(['payments.refund']);

        // Catalog switches KWD → SAR after the charge; the txn row (and the
        // order snapshot drift simulation below) still denominates KWD.
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'SAR')->firstOrFail());
        $order->update(['currency_code' => 'SAR', 'catalog_currency_code' => 'SAR']);

        // Provider settles the ORIGINAL charge currency (KWD).
        $this->mockAdapterRefund('myfatoorah', new GatewayResult(
            success: true,
            gatewayTransactionId: 'RF-SWITCH-1',
            amount: 10.0,
            currency: 'KWD',
            status: 'Refunded',
        ));

        $response = $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', [
            'amount' => 10.0,
            'idempotency_key' => 'switch-key-1',
        ]);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $response->assertJsonPath('data.refunded_currency', 'KWD');
        $this->assertEqualsWithDelta(10.0, (float) $response->json('data.refunded_amount'), 0.0000001);
        $this->assertSame('partially_refunded', $txn->fresh()->status);
    }

    // -----------------------------------------------------------------
    // MUST-FIX #6a: per-transaction idempotency scope
    // -----------------------------------------------------------------

    /** @test */
    public function same_idempotency_key_on_different_orders_are_independent_refunds(): void
    {
        [$orderA, $txnA] = $this->makePaidCompletedOrder('PAY-SCOPE-A', 100.0);
        [$orderB, $txnB] = $this->makePaidCompletedOrder('PAY-SCOPE-B', 100.0);
        $this->actingAdminWith(['payments.refund']);
        $this->mockRefunds([$this->refundConfirmedPayload('RF-A'), $this->refundConfirmedPayload('RF-B')]);

        // Documented scope: keys are unique PER TRANSACTION. Reusing one key
        // on another order's txn is a new refund, not a replay.
        $this->postJson(self::ADMIN_REFUND . $orderA->id . '/refund', [
            'amount' => 10.0, 'idempotency_key' => 'shared-key',
        ])->assertStatus(200)->assertJsonPath('data.idempotent_replay', false);

        $this->postJson(self::ADMIN_REFUND . $orderB->id . '/refund', [
            'amount' => 10.0, 'idempotency_key' => 'shared-key',
        ])->assertStatus(200)->assertJsonPath('data.idempotent_replay', false);

        // Two provider calls, one ledger row on each txn.
        $this->assertCount(1, $txnA->fresh()->gateway_response['_refunds'] ?? []);
        $this->assertCount(1, $txnB->fresh()->gateway_response['_refunds'] ?? []);
        $this->assertSame('RF-A', $txnA->fresh()->gateway_response['_refunds'][0]['provider_ref']);
        $this->assertSame('RF-B', $txnB->fresh()->gateway_response['_refunds'][0]['provider_ref']);
    }

    // -----------------------------------------------------------------
    // MUST-FIX #6b: summary reflects committed (locked) ledger state
    // -----------------------------------------------------------------

    /** @test */
    public function summary_remaining_reflects_preexisting_committed_ledger(): void
    {
        [$order, $txn] = $this->makePaidCompletedOrder('PAY-SUMMARY-1', 100.0);
        $this->actingAdminWith(['payments.refund']);

        // A previously committed partial (e.g. an external refund) sits in
        // the ledger: 30 of 100 already returned.
        $txn->update(['gateway_response' => ['_refunds' => [[
            'id' => 'ext-1',
            'event_id' => 'WH-PREV-1',
            'idempotency_key' => 'external:WH-PREV-1',
            'amount' => 30.0,
            'currency' => 'KWD',
            'full_refund' => false,
        ]]]]);
        $txn->update(['status' => 'partially_refunded']);

        $this->mockRefunds([$this->refundConfirmedPayload('RF-SUM-1')]);

        $response = $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', [
            'amount' => 70.0, 'idempotency_key' => 'summary-key-1',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.full_refund', true);
        $this->assertEqualsWithDelta(0.0, (float) $response->json('data.remaining_refundable'), 0.0000001);
        $this->assertSame('refunded', $txn->fresh()->status);
    }

    // -----------------------------------------------------------------
    // MUST-FIX #6c: 100-entry cap with prune-oldest
    // -----------------------------------------------------------------

    /** @test */
    public function ledger_caps_at_100_entries_pruning_oldest_first(): void
    {
        [$order, $txn] = $this->makePaidCompletedOrder('PAY-CAP-1', 100.0);
        $this->actingAdminWith(['payments.refund']);

        $seeded = [];
        for ($i = 0; $i < 99; $i++) {
            $seeded[] = [
                'id' => 'seed-' . $i,
                'idempotency_key' => 'seed-key-' . $i,
                'amount' => 0.5,
                'currency' => 'KWD',
                'full_refund' => false,
            ];
        }
        $txn->update(['gateway_response' => ['_refunds' => $seeded]]);
        $txn->update(['status' => 'partially_refunded']);

        $this->mockRefunds([$this->refundConfirmedPayload('RF-CAP-1'), $this->refundConfirmedPayload('RF-CAP-2')]);

        // 99 + 1 → exactly 100, no pruning yet.
        $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', [
            'amount' => 10.0, 'idempotency_key' => 'cap-key-1',
        ])->assertStatus(200);
        $this->assertCount(100, $txn->fresh()->gateway_response['_refunds'] ?? []);

        // 100 + 1 → oldest pruned, still 100.
        $this->postJson(self::ADMIN_REFUND . $order->id . '/refund', [
            'amount' => 5.0, 'idempotency_key' => 'cap-key-2',
        ])->assertStatus(200);

        $ledger = $txn->fresh()->gateway_response['_refunds'] ?? [];
        $this->assertCount(100, $ledger);

        $ids = array_column($ledger, 'id');
        $this->assertNotContains('seed-0', $ids);
        $this->assertContains('seed-1', $ids);
    }
}
