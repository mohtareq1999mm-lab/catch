<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\DTOs\CheckoutTotals;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Dashboard\DashboardService;
use App\Services\General\OrderService;
use App\Services\General\PromotionService;
use App\Services\Payment\PaymentCheckoutHandler;
use App\Services\Payment\PaymentRefundService;
use Database\Seeders\FinancialCurrencyVerificationSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;

/**
 * FINAL end-to-end verification of the financial currency flow.
 *
 * Fixtures come ONLY from FinancialCurrencyVerificationSeeder (currencies,
 * pinned FX, product, promotion, customer). Every Order below is created
 * through the real OrderCreationService; discounts through the real
 * PromotionService; COD payment through the real checkout handler +
 * OrderService::markCodAsPaid (zero mocks); PayPal refunds through the real
 * webhook route (only the signature verifier is the project's standard test
 * double); reporting through the real services/endpoints.
 *
 * PayPal paid-transaction rows are fixtures (project-accepted pattern, see
 * ExternalRefundTest) enabling the REAL webhook/service refund paths — the
 * payment-completion proof itself is the COD flow in
 * sar_discount_cod_payment_flow.
 */
class FinancialCurrencyEndToEndTest extends CurrencyTestCase
{
    private const PAYPAL_URL = '/api/v1/general/checkout/webhooks/paypal';

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.gateways.paypal.mode' => 'sandbox']);
        config(['payment.gateways.paypal.client_id' => 'test-client-id']);
        config(['payment.gateways.paypal.client_secret' => 'test-client-secret']);
        config(['payment.gateways.paypal.webhook_id' => 'WH-TEST-123']);
        config(['payment.gateways.paypal.supported_currencies' => ['USD', 'SAR', 'EUR', 'KWD']]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers (all real services)
    // ------------------------------------------------------------------

    private function seedE2E(): void
    {
        $this->seed(FinancialCurrencyVerificationSeeder::class);
        $this->app->forgetInstance(CurrencyService::class);
    }

    private function e2eCustomer(): User
    {
        $user = User::query()->where('email', FinancialCurrencyVerificationSeeder::CUSTOMER_EMAIL)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    private function e2eAdmin(): User
    {
        $admin = $this->createUserWithPermissions(
            array_merge(self::CURRENCY_PERMISSIONS, ['view-analytics', 'payments.mark_paid']),
            'admin'
        );
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function cartWithProduct(User $user, int $quantity = 1, float $unitCatalogPrice = 375.0): Cart
    {
        $product = Product::query()->where('slug', FinancialCurrencyVerificationSeeder::PRODUCT_SLUG)->firstOrFail();

        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => 'active',
            'total_price' => $unitCatalogPrice * $quantity,
        ]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $unitCatalogPrice,
            'total_price' => $unitCatalogPrice * $quantity,
        ]);
        $cart->load(['items', 'items.product']);

        return $cart;
    }

    private function applyVerificationPromotion(Cart $cart): CheckoutTotals
    {
        $promotion = \Marvel\Database\Models\Promotion::query()
            ->where('code', FinancialCurrencyVerificationSeeder::PROMOTION_CODE)
            ->firstOrFail();

        return app(PromotionService::class)->applySelectedPromotion($cart->fresh(), $promotion->id);
    }

    private function createOrder(User $user, Cart $cart, CheckoutTotals $totals): Order
    {
        // Event::fake mirrors the project pattern (OrderCurrencyTest etc.):
        // it suppresses async listeners only; all service writes below are
        // real and asserted from the database.
        Event::fake();
        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $user->id,
                'name' => 'E2E Customer',
                'user_phone' => '01000000000',
                'user_email' => $user->email,
                'address' => '1 E2E Street',
            ],
            cart: $cart,
            checkoutTotals: $totals,
            shippingPrice: 0,
        );
        $this->assertNotNull($order);

        $this->assertTrue(
            app(OrderCreationService::class)->createOrderItems($order, $cart, [], $totals),
            'createOrderItems must succeed; a false return means a swallowed item exception.'
        );

        // Real checkout clears the checked-out cart (one active cart/user).
        $cart->items()->delete();
        $cart->delete();

        return $order->fresh();
    }

    private function payCod(Order $order, User $user): void
    {
        $request = Request::create('/fake-checkout', 'POST');
        $request->setUserResolver(fn () => $user);

        $response = app(PaymentCheckoutHandler::class)->handleCodPayment($request, $order);
        $this->assertSame(200, $response->getStatusCode());

        app(OrderService::class)->markCodAsPaid($order->fresh(), 'e2e-verification');
    }

    private function paypalFixture(Order $order, string $payId): void
    {
        // Fixture (project pattern): enables the REAL webhook refund path.
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'paypal',
            'status' => 'paid',
            'amount' => (float) $order->total_price,
            'currency' => $order->currency_code,
            'gateway_transaction_id' => $payId,
            'invoice_id' => $payId,
            'paid_at' => now(),
        ]);
        $order->update([
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'paid_at' => now(),
        ]);
    }

    private function bindPayPalVerifier(): void
    {
        $fake = new E2EVerifyClient(['verification_status' => 'SUCCESS']);
        $this->app->instance(
            \App\Services\Payment\PayPalWebhookVerifier::class,
            new \App\Services\Payment\PayPalWebhookVerifier(fn () => $fake)
        );
    }

    private function postPayPalRefund(string $eventId, string $payId, string $value, string $currency): void
    {
        $this->bindPayPalVerifier();

        $response = $this->call('POST', self::PAYPAL_URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'transmission-test-id',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => gmdate('Y-m-d\TH:i:s\Z'),
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'test-signature',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/certs/test',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
        ], (string) json_encode([
            'id' => $eventId,
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => [
                'id' => 'REFUND-' . $eventId,
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => $currency, 'value' => $value],
                'supplementary_data' => ['related_ids' => ['order_id' => $payId]],
            ],
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    private function switchSettingsEra(string $base, string $catalog): void
    {
        // Labeled harness: the API guard (proven separately) blocks era
        // changes with history; settings-level transition is the only way to
        // fabricate Era 2 for verification.
        $settings = Settings::query()->firstOrFail();
        $options = $settings->options ?? [];
        $options['base_currency_code'] = $base;
        $options['currency'] = $base;
        $options['catalog_currency_code'] = $catalog;
        $settings->options = $options;
        $settings->save();

        $this->app->forgetInstance(CurrencyService::class);
    }

    // ------------------------------------------------------------------
    // T1 — SAR catalog, real promotion discount, real COD payment
    // ------------------------------------------------------------------

    /** @test */
    public function sar_discount_cod_payment_flow(): void
    {
        $this->seedE2E();
        $user = $this->e2eCustomer();

        // Catalog pricing proof: 100 USD base price displays as 375 SAR.
        $this->assertEqualsWithDelta(
            375.0,
            app(CurrencyService::class)->convertPrice('100', 'USD', 'SAR'),
            0.01
        );

        app(CurrencyService::class)->setBaseCurrency(
            \App\Models\Currency::query()->where('code', 'USD')->firstOrFail()
        );
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'SAR')->firstOrFail()
        );

        $cart = $this->cartWithProduct($user, 1, 375.0);
        $totals = $this->applyVerificationPromotion($cart);
        $this->assertEqualsWithDelta(50.0, (float) $totals->promotionDiscount, 0.01);
        $this->assertEqualsWithDelta(325.0, (float) $totals->finalTotal, 0.01);

        $order = $this->createOrder($user, $cart, $totals);

        // §8 snapshot: 375-50=325 SAR, rate=1/3.75, converted≈86.67 USD.
        $this->assertSame('SAR', $order->currency_code);
        $this->assertSame('SAR', $order->catalog_currency_code);
        $this->assertSame('USD', $order->base_currency_code);
        $this->assertEqualsWithDelta(325.0, (float) $order->total_price, 0.01);
        $this->assertEqualsWithDelta(0.266667, (float) $order->currency_rate, 0.000001);
        $this->assertEqualsWithDelta(86.67, (float) $order->converted_total_price, 0.01);
        $this->assertSame(now()->toDateString(), $order->currency_rate_date->toDateString());
        $this->assertEqualsWithDelta(50.0, (float) $order->promotion_discount, 0.01);

        // Items snapshotted in SAR (catalog-consistent).
        $item = $order->orderItems()->first();
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(375.0, (float) $item->product_total_price, 0.01);
        $this->assertSame('SAR', $item->currency_code);

        // Real COD payment: pending txn in SAR, then real completion.
        $this->payCod($order, $user);

        $fresh = $order->fresh();
        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $fresh->status);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $fresh->payment_status);

        $txn = $fresh->transactions()->latest()->first();
        $this->assertSame('paid', $txn->status);
        $this->assertSame('SAR', strtoupper($txn->currency));
        $this->assertEqualsWithDelta(325.0, (float) $txn->amount, 0.01);

        // Synchronous status-history persistence (not listener-dependent).
        if (\Illuminate\Support\Facades\Schema::hasTable('order_status_history')) {
            $this->assertDatabaseHas('order_status_history', ['order_id' => $fresh->id]);
        }

        // Real invoice generation preserves the SAR snapshot.
        $invoice = app(\App\Services\Invoice\InvoiceService::class)->generateFromOrder($fresh);
        $this->assertNotNull($invoice);
        $this->assertSame('SAR', $invoice->currency);
        $this->assertEqualsWithDelta(325.0, (float) $invoice->total, 0.01);
    }

    // ------------------------------------------------------------------
    // T2 — PayPal refunds (partial ×2, full) + multi-currency reporting
    // ------------------------------------------------------------------

    /** @test */
    public function paypal_refunds_and_multicurrency_reporting(): void
    {
        $this->seedE2E();
        $user = $this->e2eCustomer();
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'SAR')->firstOrFail()
        );

        // Order A2: 375 SAR, paid via PayPal (fixture), real webhook refunds.
        $orderA2 = $this->createOrder(
            $user,
            $this->cartWithProduct($user, 1, 375.0),
            new CheckoutTotals(375.0, 0, 0, 375.0)
        );
        $this->paypalFixture($orderA2, 'PAY-E2E-A2');

        $this->postPayPalRefund('WH-E2E-1', 'PAY-E2E-A2', '100.00', 'SAR');
        $this->postPayPalRefund('WH-E2E-2', 'PAY-E2E-A2', '50.00', 'SAR');

        $txn = $orderA2->transactions()->latest()->first()->fresh();
        $ledger = $txn->gateway_response['_refunds'] ?? [];
        $this->assertCount(2, $ledger);
        $this->assertSame('partially_refunded', $txn->status);
        $this->assertSame(Order::ORDER_STATUS_COMPLETED, $orderA2->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $orderA2->fresh()->payment_status);

        $remaining = app(PaymentRefundService::class)->ledgerRemaining((int) $orderA2->id);
        $this->assertNotNull($remaining, 'ledgerRemaining requires a paid/partially_refunded txn');
        $this->assertSame('SAR', $remaining['currency']);
        $this->assertEqualsWithDelta(
            225.0,
            \App\Services\Payment\CurrencyPrecision::fromMinorUnits((int) $remaining['remaining_minor'], 'SAR'),
            0.01
        );

        // Order B: full refund → net 0, stays completed.
        $orderB = $this->createOrder(
            $user,
            $this->cartWithProduct($user, 1, 375.0),
            new CheckoutTotals(375.0, 0, 0, 375.0)
        );
        $this->paypalFixture($orderB, 'PAY-E2E-B');
        $this->postPayPalRefund('WH-E2E-3', 'PAY-E2E-B', '375.00', 'SAR');

        $this->assertSame('refunded', $orderB->transactions()->latest()->first()->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_REFUNDED, $orderB->fresh()->payment_status);

        // Order C: EUR catalog, completed, no refunds — separate bucket.
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'EUR')->firstOrFail()
        );
        $orderC = $this->createOrder(
            $user,
            $this->cartWithProduct($user, 1, 100.0),
            new CheckoutTotals(100.0, 0, 0, 100.0)
        );
        $this->assertSame('EUR', $orderC->currency_code);
        $this->paypalFixture($orderC, 'PAY-E2E-C');

        // §9/§15: SAR gross 750 − gateway-ledger refunds 525 = 225;
        // EUR net = 100. Never 225+100 as one monetary number.
        // (Gateway refunds live in txn ledgers, not the marketplace table.)
        $finance = app(DashboardService::class)->getFinanceAnalytics(new Request());
        $this->assertEquals(['EUR' => 100.0, 'SAR' => 225.0], $finance['net_revenue_by_currency']);
        $this->assertTrue($finance['mixed_net_currencies']);
        $this->assertEquals(['SAR' => 525.0], $finance['gateway_refund_by_currency']);
        $this->assertEquals([], $finance['refund_by_currency']);
    }

    // ------------------------------------------------------------------
    // T3 — Era transition: guard proof + immutability + new-era order
    // ------------------------------------------------------------------

    /** @test */
    public function base_era_transition_preserves_history(): void
    {
        $this->seedE2E();
        $admin = $this->e2eAdmin();
        Sanctum::actingAs($admin);
        $user = User::query()->where('email', FinancialCurrencyVerificationSeeder::CUSTOMER_EMAIL)->firstOrFail();

        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'SAR')->firstOrFail()
        );
        $orderA = $this->createOrder($user, $this->cartWithProduct($user, 1, 375.0), new CheckoutTotals(375.0, 0, 0, 375.0));
        $this->paypalFixture($orderA, 'PAY-E2E-ERA');
        $beforeOrders = Order::query()->orderBy('id')->get()->map(
            fn ($o) => $o->only(['currency_code', 'catalog_currency_code', 'base_currency_code', 'currency_rate', 'currency_rate_date', 'converted_total_price', 'total_price'])
        )->all();
        $beforeRates = \App\Models\CurrencyRate::query()->orderBy('currency_id')->orderBy('effective_date')
            ->get(['currency_id', 'exchange_rate', 'effective_date'])
            ->map(fn ($r) => $r->only(['currency_id', 'exchange_rate', 'effective_date']))->all();
        $beforeTxns = $orderA->transactions()->orderBy('id')->get()->map(
            fn ($t) => $t->only(['amount', 'currency', 'status'])
        )->all();

        // Real guard: USD→SAR via API is 409 with financial history.
        $sar = \App\Models\Currency::query()->where('code', 'SAR')->firstOrFail();
        $this->postJson(self::PREFIX . "/currencies/{$sar->id}/set-base")->assertStatus(409);

        // Era 2 fabrication (labeled harness; guard proven above).
        $this->switchSettingsEra('SAR', 'EUR');
        app(CurrencyService::class)->setCatalogCurrency(
            \App\Models\Currency::query()->where('code', 'EUR')->firstOrFail()
        );

        // Order D: EUR catalog, SAR base — 100 × 3.75/0.85 ≈ 441.18 SAR.
        $orderD = $this->createOrder($user, $this->cartWithProduct($user, 1, 100.0), new CheckoutTotals(100.0, 0, 0, 100.0));
        $this->assertSame('EUR', $orderD->currency_code);
        $this->assertSame('SAR', $orderD->base_currency_code);
        $this->assertEqualsWithDelta(4.411764, (float) $orderD->currency_rate, 0.000001);
        $this->assertEqualsWithDelta(441.18, (float) $orderD->converted_total_price, 0.01);
        $this->paypalFixture($orderD, 'PAY-E2E-D');

        // §18: financial snapshots byte-identical (order currency columns,
        // FX rows, transaction money columns; items/promotions covered by
        // dedicated snapshot suites, not re-asserted here).
        $afterOrders = Order::query()->where('id', '<=', $orderA->id)->orderBy('id')->get()->map(
            fn ($o) => $o->only(['currency_code', 'catalog_currency_code', 'base_currency_code', 'currency_rate', 'currency_rate_date', 'converted_total_price', 'total_price'])
        )->all();
        $this->assertEquals($beforeOrders, $afterOrders);

        $afterRates = \App\Models\CurrencyRate::query()->orderBy('currency_id')->orderBy('effective_date')
            ->get(['currency_id', 'exchange_rate', 'effective_date'])
            ->map(fn ($r) => $r->only(['currency_id', 'exchange_rate', 'effective_date']))->all();
        $this->assertEquals($beforeRates, $afterRates);

        $afterTxns = $orderA->transactions()->orderBy('id')->get()->map(
            fn ($t) => $t->only(['amount', 'currency', 'status'])
        )->all();
        $this->assertEquals($beforeTxns, $afterTxns);

        // §19: 1 USD = 3.75 SAR = 0.85 EUR still, after the transition.
        $this->assertSame('3.7500000000', (string) \App\Models\CurrencyRate::query()
            ->whereHas('currency', fn ($q) => $q->where('code', 'SAR'))->latest('effective_date')->first()->exchange_rate);

        // §20: eras distinguishable in reporting.
        $overview = app(DashboardService::class)->getOverview(new Request());
        $this->assertTrue($overview['mixed_base_eras']);
        $this->assertArrayHasKey('USD', $overview['revenue_by_base_currency']);
        $this->assertArrayHasKey('SAR', $overview['revenue_by_base_currency']);
    }

    // ------------------------------------------------------------------
    // T4 — Finance API buckets + cache degradation
    // ------------------------------------------------------------------

    /** @test */
    public function finance_api_buckets_and_cache_degradation(): void
    {
        $this->seedE2E();
        $this->e2eAdmin();

        foreach (['overview', 'revenue', 'finance'] as $endpoint) {
            $response = $this->getJson(self::PREFIX . "/dashboard/{$endpoint}");
            $response->assertStatus(200);
            $response->assertJsonPath('success', true);
        }

        $finance = $this->getJson(self::PREFIX . '/dashboard/finance')->json('data');
        foreach (['net_revenue_by_currency', 'mixed_net_currencies', 'discount_by_currency', 'shipping_by_base_currency', 'refund_by_currency', 'mixed_refund_currencies', 'revenue_by_base_currency', 'mixed_base_eras'] as $key) {
            $this->assertArrayHasKey($key, $finance, "missing finance key: {$key}");
        }

        // §22: the file store has no tagging — invalidation must degrade,
        // never throw after commit. Force the file driver so the catch path
        // is genuinely exercised (array driver supports tags).
        config(['cache.default' => 'file']);

        $settings = Settings::query()->firstOrFail();
        $settings->options = array_merge($settings->options ?? [], ['base_currency_code' => 'USD']);
        $settings->save();

        app(CurrencyService::class)->invalidatePriceCaches(flushSettings: true);

        $this->assertSame('USD', Settings::query()->first()->options['base_currency_code']);
        $this->assertTrue(true);
    }
}

final class E2EVerifyClient
{
    public function __construct(private array $response) {}

    public function verifyWebHook(array $data): array
    {
        return $this->response;
    }
}
