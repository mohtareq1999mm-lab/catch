<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\DTOs\CheckoutTotals;
use App\Http\Controllers\Api\Admin\AdminOrderTrackingController;
use App\Services\Analytics\OrderAnalyticsService;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Customer\CustomerMetricsService;
use App\Services\Dashboard\DashboardService;
use App\Services\Payment\CurrencyPrecision;
use App\Services\Payment\PaymentCurrencyResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Settings;

/**
 * Financial currency reporting safety matrix (Phase 17).
 *
 * Proves the reporting contract:
 *   CURRENT CONFIGURATION != HISTORICAL FINANCIAL TRUTH
 * and that no financial report presents a mixed-currency scalar as a
 * single-currency total without an explicit currency dimension + flag.
 */
class FinancialReportingCurrencyTest extends CurrencyTestCase
{
    private function makeCart(): Cart
    {
        return Cart::create([
            'user_id' => $this->createCustomer()->id,
            'status' => 'active',
            'total_price' => 100.0,
        ]);
    }

    private function makeTotals(float $subtotal): CheckoutTotals
    {
        return new CheckoutTotals(
            subtotal: $subtotal,
            promotionDiscount: 0,
            couponDiscount: 0,
            finalTotal: $subtotal,
        );
    }

    private function createOrder(float $subtotal = 100.0): Order
    {
        Event::fake();
        $cart = $this->makeCart();
        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $cart->user_id,
                'name' => 'Currency Customer',
                'user_phone' => '01000000000',
                'user_email' => 'currency@example.com',
                'address' => '123 Currency Street',
            ],
            cart: $cart,
            checkoutTotals: $this->makeTotals($subtotal),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

        return $order;
    }

    private function completeOrder(Order $order): Order
    {
        $order->update([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ]);

        return $order->fresh();
    }

    private function switchSettingsEra(string $base, string $catalog): void
    {
        $settings = Settings::query()->firstOrFail();
        $options = $settings->options ?? [];
        $options['base_currency_code'] = $base;
        $options['currency'] = $base;
        $options['catalog_currency_code'] = $catalog;
        $settings->options = $options;
        $settings->save();

        // CurrencyService memoizes codes per instance (singleton).
        $this->app->forgetInstance(CurrencyService::class);
    }

    /** @test */
    public function scenario_1_usd_only_orders_report_single_currency_buckets(): void
    {
        $this->seedCurrencyData();

        $this->completeOrder($this->createOrder(100.0));
        $this->completeOrder($this->createOrder(50.0));

        $overview = app(DashboardService::class)->getOverview(new Request());

        $this->assertSame('USD', $overview['revenue_currency']);
        $this->assertEquals(['USD' => 150.0], $overview['revenue_by_base_currency']);
        $this->assertFalse($overview['mixed_base_eras']);
        $this->assertEquals(150.0, $overview['total_revenue']);
    }

    /** @test */
    public function scenario_2_multi_currency_orders_produce_separate_totals(): void
    {
        $this->seedCurrencyData();

        // USD order while catalog = USD.
        $usdOrder = $this->completeOrder($this->createOrder(100.0));

        // KWD-catalog order (catalog switch is allowed; only base is guarded).
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'KWD')->firstOrFail()
        );
        $kwdOrder = $this->completeOrder($this->createOrder(100.0));

        $this->assertSame('KWD', $kwdOrder->currency_code);
        $this->assertSame('USD', $kwdOrder->base_currency_code);
        // 100 KWD -> USD at 0.221 rate = 100 / 0.221.
        $this->assertEqualsWithDelta(452.49, (float) $kwdOrder->converted_total_price, 0.02);

        $controller = app(AdminOrderTrackingController::class);
        $method = new \ReflectionMethod($controller, 'getRevenueStats');
        $stats = $method->invoke($controller, [now()->subDay(), now()->addDay()]);

        $this->assertArrayHasKey('by_currency', $stats);
        $this->assertTrue($stats['mixed_currencies']);
        $this->assertEqualsWithDelta(100.0, (float) $stats['by_currency']['USD'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $stats['by_currency']['KWD'], 0.01);

        // Historical USD row is untouched by the later KWD order.
        $this->assertEquals('USD', $usdOrder->fresh()->currency_code);
        $this->assertEqualsWithDelta(100.0, (float) $usdOrder->fresh()->converted_total_price, 0.01);
    }

    /** @test */
    public function scenario_3_two_base_eras_are_never_globally_summed(): void
    {
        $this->seedCurrencyData();
        $this->completeOrder($this->createOrder(100.0));

        // Simulate Era 2 (EUR base) via settings — the guard under test would
        // block this through the API, which is exactly what scenario_9 covers.
        $eur = $this->createCurrency('EUR');
        $this->createRate($eur, '0.9200000000');
        $this->switchSettingsEra('EUR', 'EUR');
        $this->completeOrder($this->createOrder(100.0));

        $overview = app(DashboardService::class)->getOverview(new Request());

        $this->assertEquals(['EUR' => 100.0, 'USD' => 100.0], $overview['revenue_by_base_currency']);
        $this->assertTrue($overview['mixed_base_eras']);
        // The scalar is preserved for backward compatibility but flagged mixed.
        $this->assertEqualsWithDelta(200.0, (float) $overview['total_revenue'], 0.01);
    }

    /** @test */
    public function scenario_4_old_and_new_era_orders_keep_historical_values(): void
    {
        $this->seedCurrencyData();
        $order = $this->completeOrder($this->createOrder(100.0));
        $before = $order->fresh()->only([
            'currency_code', 'base_currency_code', 'catalog_currency_code',
            'currency_rate', 'converted_total_price', 'total_price',
        ]);

        $eur = $this->createCurrency('EUR');
        $this->createRate($eur, '0.9200000000');
        $this->switchSettingsEra('EUR', 'EUR');
        $newOrder = $this->completeOrder($this->createOrder(200.0));

        $this->assertEquals($before, $order->fresh()->only(array_keys($before)));
        $this->assertSame('EUR', $newOrder->base_currency_code);
        $this->assertEqualsWithDelta(200.0, (float) $newOrder->converted_total_price, 0.01);
    }

    /** @test */
    public function scenario_5_rate_change_does_not_rewrite_old_orders(): void
    {
        $this->seedCurrencyData();
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'SAR')->firstOrFail()
        );

        $old = $this->completeOrder($this->createOrder(375.0));
        $oldRate = (string) $old->currency_rate;
        $oldConverted = (float) $old->converted_total_price;
        $this->assertEqualsWithDelta(100.0, $oldConverted, 0.02);

        // Rate moves 3.75 -> 4.00 for all FUTURE conversions.
        \App\Models\CurrencyRate::query()
            ->whereHas('currency', fn ($q) => $q->where('code', 'SAR'))
            ->update(['exchange_rate' => '4.0000000000']);
        $this->app->forgetInstance(CurrencyService::class);

        $new = $this->completeOrder($this->createOrder(375.0));

        $this->assertSame($oldRate, (string) $old->fresh()->currency_rate);
        $this->assertEqualsWithDelta($oldConverted, (float) $old->fresh()->converted_total_price, 0.001);
        $this->assertEqualsWithDelta(93.75, (float) $new->converted_total_price, 0.02);
    }

    /** @test */
    public function scenario_6_refund_math_stays_in_transaction_currency(): void
    {
        $this->seedCurrencyData();
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'KWD')->firstOrFail()
        );

        $order = $this->completeOrder($this->createOrder(100.0));
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => (float) $order->total_price,
            'currency' => 'KWD',
            'gateway_transaction_id' => 'PAY-SC6-1',
            'invoice_id' => 42,
        ]);

        // Resolver and transaction agree on KWD regardless of the USD base.
        $this->assertSame('KWD', app(PaymentCurrencyResolver::class)->forOrder($order->fresh()));

        // KWD uses 3 fractional digits; refund minor units follow the
        // transaction currency exponent, never the base currency exponent.
        $this->assertSame(3, CurrencyPrecision::decimalsFor('KWD'));
        $this->assertSame(100000, CurrencyPrecision::toMinorUnits((float) $order->total_price, 'KWD'));

        // Changing the global base does not touch the captured transaction.
        $this->switchSettingsEra('EUR', 'KWD');
        $txn = $order->transactions()->latest()->first();
        $this->assertSame('KWD', $txn->currency);
        $this->assertSame('KWD', app(PaymentCurrencyResolver::class)->forOrder($order->fresh()));
    }

    /** @test */
    public function scenario_7_legacy_null_snapshot_rows_are_isolated(): void
    {
        $this->seedCurrencyData();
        $this->completeOrder($this->createOrder(100.0));

        $legacy = $this->completeOrder($this->createOrder(75.0));
        $legacy->update([
            'currency_code' => null,
            'base_currency_code' => null,
            'catalog_currency_code' => null,
            'currency_rate' => null,
            'currency_rate_date' => null,
            'converted_total_price' => null,
        ]);

        $overview = app(DashboardService::class)->getOverview(new Request());
        $buckets = $overview['revenue_by_base_currency'];

        $this->assertArrayHasKey('UNKNOWN', $buckets);
        $this->assertEqualsWithDelta(75.0, (float) $buckets['UNKNOWN'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $buckets['USD'], 0.01);
        // Legacy rows resolved via the COALESCE fallback mix units into the
        // scalar, so the dataset must be flagged even with a single real era.
        $this->assertTrue($overview['mixed_base_eras']);
        // Backward-compatible scalar still uses the COALESCE fallback.
        $this->assertEqualsWithDelta(175.0, (float) $overview['total_revenue'], 0.01);

        // Time-series points attribute legacy rows to UNKNOWN, never a currency.
        $series = app(OrderAnalyticsService::class)->getTimeSeries('revenue', '30d', 'day');
        $this->assertNotEmpty($series);
        foreach ($series as $point) {
            if ($point['mixed_currencies']) {
                $this->assertContains('UNKNOWN', $point['currencies']);
            }
        }
    }

    /** @test */
    public function scenario_8_same_base_request_is_idempotent_success(): void
    {
        $this->createAuthenticatedAdmin();
        $currencies = $this->seedCurrencyData();

        $this->completeOrder($this->createOrder(100.0));

        // Financial history exists, yet re-setting the ACTIVE base is a no-op.
        $response = $this->postJson(self::PREFIX . "/currencies/{$currencies['USD']->id}/set-base");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame('USD', Settings::query()->first()->options['base_currency_code']);
    }

    /** @test */
    public function scenario_9_different_base_request_stays_blocked_with_history(): void
    {
        $this->createAuthenticatedAdmin();
        $currencies = $this->seedCurrencyData();

        $order = $this->completeOrder($this->createOrder(100.0));
        $before = $order->fresh()->only([
            'currency_code', 'base_currency_code', 'currency_rate', 'converted_total_price',
        ]);

        $response = $this->postJson(self::PREFIX . "/currencies/{$currencies['KWD']->id}/set-base");

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath(
            'message',
            __('message.ERROR.CANNOT_CHANGE_BASE_CURRENCY_FINANCIAL_ORDERS_EXIST')
        );
        $this->assertSame('USD', Settings::query()->first()->options['base_currency_code']);
        $this->assertEquals($before, $order->fresh()->only(array_keys($before)));
    }

    /** @test */
    public function scenario_10_reports_flag_mixed_currency_scalars(): void
    {
        $this->seedCurrencyData();
        $this->completeOrder($this->createOrder(100.0));
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'KWD')->firstOrFail()
        );
        $this->completeOrder($this->createOrder(100.0));

        $analytics = app(OrderAnalyticsService::class)->getDashboardOverview('24h');

        $this->assertTrue($analytics['revenue']['mixed_currencies']);
        $this->assertArrayHasKey('USD', $analytics['revenue']['by_currency']);
        $this->assertArrayHasKey('KWD', $analytics['revenue']['by_currency']);

        // Single-currency dataset reports no mixing. forceDelete is required
        // because OrderAnalyticsService queries via DB::table (no SoftDeletes
        // scope), so soft-deleted rows would still be counted — see report.
        Order::query()->forceDelete();
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'USD')->firstOrFail()
        );
        $this->completeOrder($this->createOrder(25.0));

        // Soft-deleted mixed rows are excluded from reporting queries.
        $fresh = app(OrderAnalyticsService::class)->getDashboardOverview('30d');

        $this->assertFalse($fresh['revenue']['mixed_currencies']);
        $this->assertArrayHasKey('USD', $fresh['revenue']['by_currency']);
        $this->assertArrayNotHasKey('KWD', $fresh['revenue']['by_currency']);
    }

    /** @test */
    public function refund_buckets_group_by_order_currency_with_unknown_isolation(): void
    {
        $this->seedCurrencyData();

        $usdOrder = $this->completeOrder($this->createOrder(100.0));
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'KWD')->firstOrFail()
        );
        $kwdOrder = $this->completeOrder($this->createOrder(100.0));

        // Marketplace refunds are denominated in their order's currency.
        \Marvel\Database\Models\Refund::create([
            'title' => 'USD refund', 'amount' => 10.0,
            'status' => 'approved', 'order_id' => $usdOrder->id,
        ]);
        \Marvel\Database\Models\Refund::create([
            'title' => 'KWD refund', 'amount' => 5.0,
            'status' => 'approved', 'order_id' => $kwdOrder->id,
        ]);
        // Orphan refunds (no linked order) cannot prove a currency.
        \Marvel\Database\Models\Refund::create([
            'title' => 'Orphan refund', 'amount' => 7.0, 'status' => 'approved',
        ]);
        // Non-approved refunds are excluded from finance scope, like the scalar.
        \Marvel\Database\Models\Refund::create([
            'title' => 'Pending refund', 'amount' => 99.0,
            'status' => 'pending', 'order_id' => $usdOrder->id,
        ]);

        $finance = app(DashboardService::class)->getFinanceAnalytics(new Request());

        $this->assertEquals(
            ['KWD' => 5.0, 'UNKNOWN' => 7.0, 'USD' => 10.0],
            $finance['refund_by_currency']
        );
        $this->assertTrue($finance['mixed_refund_currencies']);
        // Scalar preserved (backward compat) and reconciles with buckets.
        $this->assertEqualsWithDelta(
            array_sum($finance['refund_by_currency']),
            (float) $finance['refund_amount'],
            0.01
        );
    }

    /**
     * Sequential conflicting transitions: last writer wins deterministically.
     * NOTE: this is a sequential contract test, not a true parallel race —
     * concurrent serialization is provided by Settings::lockForUpdate() inside
     * CurrencyService::setBaseCurrency() (single settings row) and verified by
     * inspection; true overlapping-transaction coverage is out of scope here.
     *
     * @test
     */
    public function sequential_conflicting_base_transitions_last_writer_wins(): void
    {
        $this->createAuthenticatedAdmin();
        $currencies = $this->seedCurrencyData();

        // No financial history: A (USD->KWD) then B (KWD->SAR) each see the
        // last committed base; last writer wins, nothing is lost or partial.
        $this->postJson(self::PREFIX . "/currencies/{$currencies['KWD']->id}/set-base")
            ->assertStatus(200);
        $this->assertSame('KWD', Settings::query()->first()->options['base_currency_code']);

        $this->postJson(self::PREFIX . "/currencies/{$currencies['SAR']->id}/set-base")
            ->assertStatus(200);
        $options = Settings::query()->first()->options;
        $this->assertSame('SAR', $options['base_currency_code']);
        $this->assertSame('SAR', $options['currency']);

        // With financial history, a conflicting transition is rejected and
        // the committed base is untouched.
        $this->completeOrder($this->createOrder(10.0));
        $this->postJson(self::PREFIX . "/currencies/{$currencies['KWD']->id}/set-base")
            ->assertStatus(409);
        $this->assertSame('SAR', Settings::query()->first()->options['base_currency_code']);
    }

    /** @test */
    public function finance_reports_discount_and_shipping_splits(): void
    {
        $this->seedCurrencyData();

        $order = $this->completeOrder($this->createOrder(100.0));
        $order->update([
            'coupon_discount' => 5.0,
            'promotion_discount' => 3.0,
            'shipping_price' => 2.0,
            'fast_shipping_fee' => 1.0,
        ]);

        $finance = app(DashboardService::class)->getFinanceAnalytics(new Request());

        $this->assertEquals(['USD' => 8.0], $finance['discount_by_currency']);
        $this->assertFalse($finance['mixed_discount_currencies']);
        $this->assertEquals(['USD' => 3.0], $finance['shipping_by_base_currency']);
        $this->assertEqualsWithDelta(
            8.0,
            (float) $finance['total_discount'],
            0.01
        );

        // A second transaction currency splits discounts and flags mixing.
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'KWD')->firstOrFail()
        );
        $kwd = $this->completeOrder($this->createOrder(100.0));
        $kwd->update(['coupon_discount' => 4.0]);

        // getFinanceAnalytics is Cache::remember'd: drop the first payload.
        \Illuminate\Support\Facades\Cache::forget('dashboard_finance_analytics');
        $finance = app(DashboardService::class)->getFinanceAnalytics(new Request());

        $this->assertTrue($finance['mixed_discount_currencies']);
        $this->assertEqualsWithDelta(4.0, (float) $finance['discount_by_currency']['KWD'], 0.01);
    }

    /** @test */
    public function unresolved_legacy_orders_are_excluded_from_coupon_spend(): void
    {
        $this->seedCurrencyData();
        $order = $this->completeOrder($this->createOrder(100.0));
        $order->update(['legacy_currency_status' => CustomerMetricsService::LEGACY_CURRENCY_UNRESOLVED]);

        $metrics = app(CustomerMetricsService::class)->rebuildForUser($order->user);

        // Count/date signals still include the order; monetary spend excludes it.
        $this->assertSame(1, (int) $metrics->completed_orders);
        $this->assertEqualsWithDelta(0.0, (float) $metrics->total_qualifying_order_value, 0.001);
    }
}
