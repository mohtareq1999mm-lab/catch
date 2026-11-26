<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Exceptions\CodAmountEvidenceException;
use App\Models\Currency;
use App\Services\Payment\OrderPaidAmountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Refund;
use Tests\Feature\Currency\CurrencyTestCase;

class OrderPaidAmountServiceTest extends CurrencyTestCase
{
    use RefreshDatabase;

    private function usd(int $decimalPlaces = 2): Currency
    {
        return $this->createCurrency('USD', ['decimal_places' => $decimalPlaces]);
    }

    private function orderWithUsdPrecision(int $decimalPlaces, array $overrides = []): Order
    {
        $this->usd($decimalPlaces);

        return $this->order($overrides);
    }

    private function order(array $overrides = [], string $currencyCode = 'USD'): Order
    {
        $customer = $this->createCustomer();
        $failedAttempt = (bool) ($overrides['failed'] ?? false);
        unset($overrides['failed']);

        $order = Order::create(array_merge([
            'user_id' => $customer->id,
            'name' => 'COD Evidence Customer',
            'user_email' => $customer->email,
            'user_phone' => '01000000000',
            'status' => Order::ORDER_STATUS_PENDING,
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'currency_code' => $currencyCode,
            'base_currency_code' => 'USD',
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ], $overrides));

        if ($failedAttempt) {
            $order->transactions()->create([
                'user_id' => $order->user_id,
                'payment_method' => 'myfatoorah',
                'status' => 'failed',
                'amount' => 40,
                'currency' => $currencyCode,
                'capture_state' => 'unknown',
                'attempt_state' => 'claimed',
            ]);
        }

        return $order;
    }

    public function test_it_computes_collectible_cod_from_server_side_capture_evidence(): void
    {
        $this->usd();
        $order = $this->order();

        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => 40,
            'currency' => 'USD',
            'capture_state' => 'succeeded',
            'gateway_transaction_id' => 'capture-1',
        ]);

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame($order->id, $amount->orderId);
        $this->assertSame('USD', $amount->currencyCode);
        $this->assertSame(2, $amount->decimalPlaces);
        $this->assertSame(10000, $amount->orderTotalMinor);
        $this->assertSame(4000, $amount->capturedMinor);
        $this->assertSame(0, $amount->refundedMinor);
        $this->assertSame(6000, $amount->collectibleMinor);
    }

    private function capture(Order $order, float $amount, array $gatewayResponse = [], array $overrides = []): void
    {
        $order->transactions()->create(array_merge([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => $amount,
            'currency' => $order->currency_code,
            'capture_state' => 'succeeded',
            'gateway_transaction_id' => 'capture-'.uniqid(),
            'gateway_response' => $gatewayResponse === [] ? null : $gatewayResponse,
        ], $overrides));
    }

    private function refund(Order $order, float $amount, string $status = 'approved', ?string $currency = 'USD'): Refund
    {
        return Refund::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'title' => 'Test refund',
        ]);
    }

    public function test_only_exact_cod_payment_method_can_produce_a_collectible_amount(): void
    {
        $this->usd();
        $cod = $this->order(['payment_method' => 'cod', 'payment_status' => Order::PAYMENT_STATUS_SUCCESS]);
        $this->capture($cod, 40);

        $codAmount = app(OrderPaidAmountService::class)->forOrder($cod);

        $this->assertSame(4000, $codAmount->capturedMinor);
        $this->assertSame(6000, $codAmount->collectibleMinor);
    }

    public function test_non_cod_payment_methods_always_snapshot_zero(): void
    {
        $this->usd();

        foreach (['cash_on_delivery', 'online', 'pay_at_cashier', 'stripe', 'myfatoorah', 'bank_transfer', 'unknown_gateway', null, ''] as $method) {
            $order = $this->order([
                'payment_method' => $method,
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            ]);
            $this->capture($order, 100);

            $amount = app(OrderPaidAmountService::class)->forOrder($order);

            $label = $method ?? 'null';
            $this->assertSame(0, $amount->orderTotalMinor, $label);
            $this->assertSame(0, $amount->capturedMinor, $label);
            $this->assertSame(0, $amount->refundedMinor, $label);
            $this->assertSame(0, $amount->collectibleMinor, $label);
            $this->assertSame('USD', $amount->currencyCode, $label);
        }
    }

    public function test_non_exact_cod_spellings_snapshot_zero_without_cod_formula(): void
    {
        foreach ([' COD ', 'Cod', 'cod '] as $method) {
            $order = $this->order([
                'payment_method' => $method,
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            ]);
            $this->capture($order, 40);

            $amount = app(OrderPaidAmountService::class)->forOrder($order);

            $this->assertSame('USD', $amount->currencyCode, $method);
            $this->assertSame(0, $amount->decimalPlaces, $method);
            $this->assertSame(0, $amount->orderTotalMinor, $method);
            $this->assertSame(0, $amount->capturedMinor, $method);
            $this->assertSame(0, $amount->refundedMinor, $method);
            $this->assertSame(0, $amount->collectibleMinor, $method);
        }
    }

    public function test_non_cod_paid_order_with_missing_evidence_still_snapshots_zero(): void
    {
        $this->usd();
        $order = $this->order([
            'payment_method' => 'stripe',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ]);

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(0, $amount->capturedMinor);
        $this->assertSame(0, $amount->collectibleMinor);
    }

    public function test_duplicate_capture_evidence_does_not_inflate_captured_amount(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 30, [], ['gateway_transaction_id' => 'capture-same']);
        $this->capture($order, 30, [], ['gateway_transaction_id' => 'capture-same']);

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(3000, $amount->capturedMinor);
        $this->assertSame(0, $amount->refundedMinor);
        $this->assertSame(7000, $amount->collectibleMinor);
    }

    public function test_distinct_legitimate_captures_are_summed(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 20, [], ['gateway_transaction_id' => 'capture-1']);
        $this->capture($order, 30, [], ['gateway_transaction_id' => 'capture-2']);

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(5000, $amount->capturedMinor);
        $this->assertSame(0, $amount->refundedMinor);
        $this->assertSame(5000, $amount->collectibleMinor);
    }

    public function test_duplicate_capture_identity_with_conflicting_amount_fails_closed(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 20, [], ['gateway_transaction_id' => 'capture-same']);
        $this->capture($order, 30, [], ['gateway_transaction_id' => 'capture-same']);

        $this->expectException(CodAmountEvidenceException::class);
        $this->expectExceptionMessage(__('cod_amount.inconsistent_evidence'));

        app(OrderPaidAmountService::class)->forOrder($order);
    }

    public function test_missing_gateway_and_idempotency_identity_ambiguous_duplicates_fail_closed(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 30, [], ['gateway_transaction_id' => null, 'idempotency_key' => null]);
        $this->capture($order, 30, [], ['gateway_transaction_id' => null, 'idempotency_key' => null]);

        $this->expectException(CodAmountEvidenceException::class);
        $this->expectExceptionMessage(__('cod_amount.inconsistent_evidence'));

        app(OrderPaidAmountService::class)->forOrder($order);
    }

    public function test_distinct_legitimate_captures_with_stable_distinct_identities_still_sum(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 20, [], ['gateway_transaction_id' => 'gateway-capture-1']);
        $this->capture($order, 30, [], ['gateway_transaction_id' => null, 'idempotency_key' => 'idem-capture-2']);

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(5000, $amount->capturedMinor);
        $this->assertSame(5000, $amount->collectibleMinor);
    }

    public function test_approval_records_alone_never_reduce_the_captured_amount(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 100);
        $this->refund($order, 30, 'pending');
        $this->refund($order, 20, 'rejected');

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(10000, $amount->capturedMinor);
        $this->assertSame(0, $amount->refundedMinor);
        $this->assertSame(0, $amount->collectibleMinor);
    }

    public function test_executed_refund_is_added_once_and_legacy_gateway_ledger_is_not_counted_again(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);
        $refund = $this->refund($order, 25);

        $this->capture($order, 100, [
            '_refunds' => [[
                'id' => 'refund-'.$refund->id,
                'idempotency_key' => 'refund-'.$refund->id,
                'amount' => 25,
                'currency' => 'USD',
            ]],
        ]);

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(2500, $amount->refundedMinor);
        $this->assertSame(2500, $amount->collectibleMinor);
    }

    public function test_refunds_are_deduplicated_by_identity_not_by_amount(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 100);
        $this->refund($order, 10);
        $this->refund($order, 10);

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(2000, $amount->refundedMinor);
        $this->assertSame(2000, $amount->collectibleMinor);
    }

    public function test_currency_and_precision_come_from_the_order_currency_metadata(): void
    {
        $this->createCurrency('KWD', ['decimal_places' => 3]);
        $order = $this->order([
            'total_price' => 100.25,
            'currency_code' => 'KWD',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ], 'KWD');

        $this->capture($order, 40.125);

        $amount = app(OrderPaidAmountService::class)->forOrder($order->id);

        $this->assertSame('KWD', $amount->currencyCode);
        $this->assertSame(3, $amount->decimalPlaces);
        $this->assertSame(100250, $amount->orderTotalMinor);
        $this->assertSame(40125, $amount->capturedMinor);
        $this->assertSame(60125, $amount->collectibleMinor);
    }

    public function test_ineligible_payment_methods_snapshot_zero_and_never_reach_cod_formula(): void
    {
        $this->usd();
        $order = $this->order([
            'payment_method' => 'online',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'total_price' => 100,
        ]);

        // Poison evidence that would fail/alter a COD calculation if reached.
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => null,
            'currency' => 'EGP',
            'capture_state' => 'succeeded',
        ]);
        $this->refund($order, 999, 'approved', 'EGP');

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame($order->id, $amount->orderId);
        $this->assertSame('USD', $amount->currencyCode);
        $this->assertSame(2, $amount->decimalPlaces);
        $this->assertSame(0, $amount->orderTotalMinor);
        $this->assertSame(0, $amount->capturedMinor);
        $this->assertSame(0, $amount->refundedMinor);
        $this->assertSame(0, $amount->collectibleMinor);
    }

    public function test_pay_at_cashier_is_ineligible_and_snapshots_zero(): void
    {
        $this->usd();
        $order = $this->order([
            'payment_method' => 'pay_at_cashier',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ]);

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(0, $amount->collectibleMinor);
        $this->assertSame(0, $amount->capturedMinor);
        $this->assertSame(0, $amount->refundedMinor);
    }

    public function test_legacy_null_payment_method_is_ineligible_and_compatible(): void
    {
        $this->usd();
        $order = $this->order([
            'payment_method' => null,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'total_price' => 55.50,
        ]);

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(0, $amount->collectibleMinor);
        $this->assertSame('USD', $amount->currencyCode);
        $this->assertSame(0, $amount->orderTotalMinor);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonCanonicalPaymentMethodProvider(): array
    {
        return [
            'uppercase' => ['COD'],
            'mixed case' => ['Cod'],
            'leading whitespace' => [' cod'],
            'trailing whitespace' => ['cod '],
            'padded mixed case' => [' COD '],
            'unknown method' => ['cash_on_delivery'],
            'empty string' => [''],
            'whitespace only' => ['   '],
        ];
    }

    /** @dataProvider nonCanonicalPaymentMethodProvider */
    public function test_non_canonical_payment_methods_are_ineligible_and_snapshot_zero(string $paymentMethod): void
    {
        $this->usd();
        $order = $this->order([
            'payment_method' => $paymentMethod,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'total_price' => 100,
        ]);

        // Poison evidence that would throw or alter the amount if the gate were bypassed.
        $this->capture($order, 40);
        $this->refund($order, 10, 'approved', 'EGP');

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(0, $amount->orderTotalMinor);
        $this->assertSame(0, $amount->capturedMinor);
        $this->assertSame(0, $amount->refundedMinor);
        $this->assertSame(0, $amount->collectibleMinor);
    }

    public function test_eligibility_gate_runs_before_amount_calculation_for_ineligible_orders(): void
    {
        $this->usd();
        $order = $this->order([
            'payment_method' => 'online',
            'currency_code' => null, // would fail currency resolution in COD formula
            'total_price' => 10,
        ]);

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(0, $amount->collectibleMinor);
        $this->assertSame('', $amount->currencyCode);
        $this->assertSame(0, $amount->decimalPlaces);
    }

    /**
     * @return array<string, array{0: callable, 1: class-string<CodAmountEvidenceException>, 2: string}>
     */
    public static function missingOrInconsistentEvidenceProvider(): array
    {
        return [
            'missing order currency' => [
                fn (self $test) => [$test->order(['currency_code' => null])],
                CodAmountEvidenceException::class,
                'cod_amount.missing_currency',
            ],
            'missing precision metadata' => [
                fn (self $test) => [$test->order()],
                CodAmountEvidenceException::class,
                'cod_amount.missing_precision',
            ],
            'impossible precision metadata' => [
                fn (self $test) => [$test->orderWithUsdPrecision(5)],
                CodAmountEvidenceException::class,
                'cod_amount.invalid_precision',
            ],
            'paid order without captured evidence' => [
                fn (self $test) => [$test->orderWithUsdPrecision(2, [
                    'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                ])],
                CodAmountEvidenceException::class,
                'cod_amount.missing_paid_evidence',
            ],
            'failed attempts are not capture evidence' => [
                fn (self $test) => [$test->orderWithUsdPrecision(2, [
                    'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                    'failed' => true,
                ])],
                CodAmountEvidenceException::class,
                'cod_amount.missing_paid_evidence',
            ],
        ];
    }

    /** @dataProvider missingOrInconsistentEvidenceProvider */
    public function test_it_fails_closed_on_missing_or_inconsistent_evidence(
        callable $fixture,
        string $exceptionClass,
        string $messageKey
    ): void {
        [$order] = $fixture($this);

        try {
            app(OrderPaidAmountService::class)->forOrder($order);
            $this->fail('Expected the COD amount service to fail closed.');
        } catch (CodAmountEvidenceException $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
            $this->assertSame(__($messageKey), $exception->getMessage());
        }
    }

    public function test_it_fails_closed_when_a_paid_transaction_has_no_amount(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => null,
            'currency' => 'USD',
            'capture_state' => 'succeeded',
        ]);

        $this->expectException(CodAmountEvidenceException::class);
        $this->expectExceptionMessage(__('cod_amount.invalid_amount'));

        app(OrderPaidAmountService::class)->forOrder($order);
    }

    public function test_it_fails_closed_on_mixed_transaction_currency(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => 40,
            'currency' => 'EGP',
            'capture_state' => 'succeeded',
        ]);

        $this->expectException(CodAmountEvidenceException::class);
        $this->expectExceptionMessage(__('cod_amount.mixed_currency'));

        app(OrderPaidAmountService::class)->forOrder($order);
    }

    public function test_it_fails_closed_on_mixed_refund_currency(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 100);
        $this->refund($order, 10, 'approved', 'EGP');

        $this->expectException(CodAmountEvidenceException::class);
        $this->expectExceptionMessage(__('cod_amount.mixed_currency'));

        app(OrderPaidAmountService::class)->forOrder($order);
    }

    public function test_refund_without_currency_fails_closed_instead_of_adopting_the_order_currency(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 100);
        $this->refund($order, 10, 'approved', null);

        $this->expectException(CodAmountEvidenceException::class);
        $this->expectExceptionMessage(__('cod_amount.mixed_currency'));

        app(OrderPaidAmountService::class)->forOrder($order);
    }

    public function test_refund_with_blank_currency_fails_closed_instead_of_adopting_the_order_currency(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 100);
        $this->refund($order, 10, 'approved', '   ');

        $this->expectException(CodAmountEvidenceException::class);
        $this->expectExceptionMessage(__('cod_amount.mixed_currency'));

        app(OrderPaidAmountService::class)->forOrder($order);
    }

    public function test_float_amount_with_excess_precision_fails_closed_without_rounding(): void
    {
        $order = $this->orderWithUsdPrecision(2, ['total_price' => 40.125]);

        $this->expectException(CodAmountEvidenceException::class);
        $this->expectExceptionMessage(__('cod_amount.invalid_amount'));

        app(OrderPaidAmountService::class)->forOrder($order);
    }

    public function test_it_fails_closed_when_executed_refunds_exceed_captured_money(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 20);
        $this->refund($order, 30);

        $this->expectException(CodAmountEvidenceException::class);
        $this->expectExceptionMessage(__('cod_amount.inconsistent_evidence'));

        app(OrderPaidAmountService::class)->forOrder($order);
    }

    public function test_it_sums_distinct_qualifying_captured_transactions_and_reads_without_mutating_evidence(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 20, [], ['gateway_transaction_id' => 'capture-1']);
        $this->capture($order, 30, [], ['gateway_transaction_id' => 'capture-2']);
        $this->refund($order, 10);

        $tables = ['orders', 'transactions', 'refunds', 'currencies'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(5000, $amount->capturedMinor);
        $this->assertSame(1000, $amount->refundedMinor);
        $this->assertSame(6000, $amount->collectibleMinor);

        foreach ($tables as $table) {
            $after = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $this->assertSame($before[$table], $after, "{$table} was mutated by a read-only calculation");
        }
    }

    public function test_non_cod_zero_snapshot_does_not_require_currency_metadata(): void
    {
        foreach (['stripe', 'pay_at_cashier'] as $method) {
            $order = $this->order([
                'payment_method' => $method,
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            ]);
            $this->capture($order, 100);

            $amount = app(OrderPaidAmountService::class)->forOrder($order);

            $this->assertSame('USD', $amount->currencyCode, $method);
            $this->assertSame(0, $amount->decimalPlaces, $method);
            $this->assertSame(0, $amount->orderTotalMinor, $method);
            $this->assertSame(0, $amount->capturedMinor, $method);
            $this->assertSame(0, $amount->refundedMinor, $method);
            $this->assertSame(0, $amount->collectibleMinor, $method);
        }
    }
}
