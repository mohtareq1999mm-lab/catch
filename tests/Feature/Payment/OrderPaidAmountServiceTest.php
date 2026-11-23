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

    private function capture(Order $order, float $amount, array $gatewayResponse = []): void
    {
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => $amount,
            'currency' => $order->currency_code,
            'capture_state' => 'succeeded',
            'gateway_transaction_id' => 'capture-'.uniqid(),
            'gateway_response' => $gatewayResponse === [] ? null : $gatewayResponse,
        ]);
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

    public function test_unexecuted_refunds_do_not_change_the_collectible_amount(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 100);
        $this->refund($order, 30, 'pending');
        $this->refund($order, 20, 'rejected');

        $amount = app(OrderPaidAmountService::class)->forOrder($order);

        $this->assertSame(0, $amount->refundedMinor);
        $this->assertSame(0, $amount->collectibleMinor);
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

    public function test_it_sums_all_captured_transactions_and_reads_without_mutating_evidence(): void
    {
        $this->usd();
        $order = $this->order(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);

        $this->capture($order, 20);
        $this->capture($order, 30);
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
}
