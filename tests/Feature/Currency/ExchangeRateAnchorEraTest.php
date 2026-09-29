<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\DTOs\CheckoutTotals;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Settings;

/**
 * Exchange-rate anchor + base-era compatibility (§11/§18).
 *
 * Proves the FX engine is Model 1: stored currency_rates are permanently
 * relative to the CONFIG anchor (config/currency.php, default USD) and never
 * to the settings Base Currency — so a Base change cannot break the
 * interpretation of historical rates.
 */
class ExchangeRateAnchorEraTest extends CurrencyTestCase
{
    private function switchSettingsEra(string $base, string $catalog): void
    {
        $settings = Settings::query()->firstOrFail();
        $options = $settings->options ?? [];
        $options['base_currency_code'] = $base;
        $options['currency'] = $base;
        $options['catalog_currency_code'] = $catalog;
        $settings->options = $options;
        $settings->save();

        $this->app->forgetInstance(CurrencyService::class);
    }

    private function createOrder(float $subtotal = 100.0): Order
    {
        Event::fake();
        $cart = Cart::create([
            'user_id' => $this->createCustomer()->id,
            'status' => 'active',
            'total_price' => $subtotal,
        ]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $cart->user_id,
                'name' => 'Anchor Customer',
                'user_phone' => '01000000000',
                'user_email' => 'anchor@example.com',
                'address' => '1 Anchor Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals(
                subtotal: $subtotal,
                promotionDiscount: 0,
                couponDiscount: 0,
                finalTotal: $subtotal,
            ),
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

        return $order;
    }

    /** @test */
    public function stored_rates_keep_meaning_across_base_change(): void
    {
        $this->seedCurrencyData();
        // Seed: USD 1.0, KWD 0.221, SAR 3.75 — units per 1 anchor USD.

        $before = \App\Models\CurrencyRate::query()
            ->orderBy('currency_id')->orderBy('effective_date')
            ->get(['currency_id', 'exchange_rate', 'effective_date'])
            ->map(fn ($r) => $r->only(['currency_id', 'exchange_rate', 'effective_date']))
            ->all();

        // Ratio math is anchor-relative: 100 SAR -> USD = 100/3.75.
        $usdValue = app(CurrencyService::class)->convertPrice('100', 'SAR', 'USD');
        $this->assertEqualsWithDelta(26.67, $usdValue, 0.01);

        // Simulate a Base era change (guard would block via API; the point
        // here is rate-row semantics, covered for the guard elsewhere).
        $this->switchSettingsEra('SAR', 'SAR');

        // Same rows, same meaning: 100 SAR -> KWD still uses 0.221/3.75.
        $kwdValue = app(CurrencyService::class)->convertPrice('100', 'SAR', 'KWD');
        $this->assertEqualsWithDelta(5.89, $kwdValue, 0.01);

        $after = \App\Models\CurrencyRate::query()
            ->orderBy('currency_id')->orderBy('effective_date')
            ->get(['currency_id', 'exchange_rate', 'effective_date'])
            ->map(fn ($r) => $r->only(['currency_id', 'exchange_rate', 'effective_date']))
            ->all();

        $this->assertEquals($before, $after);
    }

    /** @test */
    public function usd_era_and_sar_era_orders_coexist_with_exact_snapshots(): void
    {
        $this->seedCurrencyData();

        $era1 = $this->createOrder(100.0);
        $era1->update([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ]);

        $this->switchSettingsEra('SAR', 'SAR');

        $era2 = $this->createOrder(200.0);
        $era2->update([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ]);

        // Era 1 row is byte-identical to creation time.
        $this->assertSame('USD', $era1->fresh()->base_currency_code);
        $this->assertSame('USD', $era1->fresh()->currency_code);
        $this->assertEqualsWithDelta(100.0, (float) $era1->fresh()->converted_total_price, 0.001);

        // Era 2 row snapshots the new base; same-code conversion is identity.
        $this->assertSame('SAR', $era2->base_currency_code);
        $this->assertSame('SAR', $era2->currency_code);
        $this->assertSame('1.0000000000', (string) $era2->currency_rate);
        $this->assertEqualsWithDelta(200.0, (float) $era2->converted_total_price, 0.001);

        // Reporting distinguishes the eras instead of summing 100+200 blindly.
        $overview = app(DashboardService::class)->getOverview(new Request());
        $this->assertEquals(['SAR' => 200.0, 'USD' => 100.0], $overview['revenue_by_base_currency']);
        $this->assertTrue($overview['mixed_base_eras']);
    }

    /** @test */
    public function missing_today_rate_falls_back_to_latest_history(): void
    {
        $currencies = $this->seedCurrencyData();
        $yesterday = now()->subDay()->toDateString();

        // Remove today's SAR row, keep a yesterday row: lookup must fall back.
        \App\Models\CurrencyRate::query()
            ->where('currency_id', $currencies['SAR']->id)
            ->whereDate('effective_date', now()->toDateString())
            ->delete();
        \App\Models\CurrencyRate::create([
            'currency_id' => $currencies['SAR']->id,
            'exchange_rate' => '3.8000000000',
            'effective_date' => $yesterday,
        ]);
        $this->app->forgetInstance(CurrencyService::class);

        $result = app(CurrencyService::class)->convert('100', 'SAR', 'USD');

        // The RATE VALUE falls back to yesterday's row (3.8, not the deleted
        // 3.75). effectiveDate is the request date (conversion performed
        // today using the latest row <= today) — not the row's own date.
        $this->assertEqualsWithDelta(100 / 3.8, (float) $result->convertedAmount, 0.02);
        $this->assertSame('3.8000000000', (string) $result->sourceRate);
        $this->assertSame(now()->toDateString(), $result->effectiveDate);
    }

    /** @test */
    public function conversion_without_any_historical_rate_fails_closed(): void
    {
        $currencies = $this->seedCurrencyData();

        \App\Models\CurrencyRate::query()
            ->where('currency_id', $currencies['SAR']->id)
            ->delete();
        $this->app->forgetInstance(CurrencyService::class);

        $this->expectException(\App\Exceptions\CurrencyRateNotFoundException::class);

        app(CurrencyService::class)->convert('100', 'SAR', 'USD');
    }
}
