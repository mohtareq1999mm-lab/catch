<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\DTOs\CheckoutTotals;
use App\Exceptions\CurrencyInUseException;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Settings;

class CurrencyBugRegressionTest extends CurrencyTestCase
{
    /** @test */
    public function order_currency_snapshot_columns_are_persisted_to_the_database(): void
    {
        Event::fake();
        $kwd = $this->seedCurrencyData()['KWD'];

        app(CurrencyService::class)->setBaseCurrency($kwd);

        $user = $this->createCustomer();
        $cart = Cart::create(['user_id' => $user->id, 'status' => 'active', 'total_price' => 100.0]);
        $totals = new CheckoutTotals(100.0, 0, 0, 100.0);

$order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $user->id,
                'name' => 'Currency Customer',
                'user_phone' => '01000000000',
                'user_email' => $user->email,
                'address' => '123 Currency Street',
            ],
            cart: $cart,
            checkoutTotals: $totals,
            shippingPrice: 0,
        );

        $this->assertNotNull($order);

        $persisted = Order::query()->findOrFail($order->id);

$this->assertSame('USD', $persisted->getRawOriginal('currency_code'));
        $this->assertSame('KWD', $persisted->getRawOriginal('base_currency_code'));
        $this->assertSame('USD', $persisted->getRawOriginal('catalog_currency_code'));
        $this->assertSame(0.221, $persisted->getRawOriginal('currency_rate'));
        $this->assertSame(now()->toDateString(), substr((string) $persisted->getRawOriginal('currency_rate_date'), 0, 10));
$this->assertSame(22.1, $persisted->getRawOriginal('converted_total_price'));
        $this->assertEquals(100.0, $persisted->getRawOriginal('total_price'));
    }

    /** @test */
    public function base_currency_without_rates_is_still_protected_from_deletion(): void
    {
        $this->seedCurrencyData();

        $settings = Settings::query()->firstOrFail();
        $options = $settings->options ?? [];
        $options['base_currency_code'] = 'EUR';
        $options['currency'] = 'EUR';
        $settings->options = $options;
        $settings->save();

        $this->app->forgetInstance(CurrencyService::class);

        $eur = $this->createCurrency('EUR');

        try {
            app(CurrencyService::class)->deleteCurrency($eur);
            $this->fail('Expected CurrencyInUseException was not thrown.');
        } catch (CurrencyInUseException $e) {
            $this->assertSame(CurrencyInUseException::REASON_BASE_CURRENCY, $e->reason);
        }

        $this->assertDatabaseHas('currencies', ['id' => $eur->id, 'deleted_at' => null]);
    }

    /** @test */
    public function currency_code_is_uppercased_by_the_store_request(): void
    {
        $this->createAuthenticatedAdmin();

        $response = $this->postJson(self::PREFIX . '/currencies', $this->currencyPayload(['code' => 'egp']));

        $response->assertStatus(200);
        $response->assertJsonPath('data.code', 'EGP');
        $this->assertDatabaseHas('currencies', ['code' => 'EGP']);
    }

    /** @test */
    public function conversion_rate_ratio_is_what_gets_snapshotted_on_orders(): void
    {
        Event::fake();
        $this->seedCurrencyData();

        $result = app(CurrencyService::class)->convert(100.0, 'USD', 'KWD');

$this->assertSame('0.221000', $result->rate);
        $this->assertSame('1', $result->sourceRate);
        $this->assertSame('0.221', $result->targetRate);
        $this->assertSame(22.1, $result->convertedAmount);
    }
}
