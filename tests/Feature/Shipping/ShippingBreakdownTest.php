<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Currency;
use App\Services\Shipping\ShippingBreakdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ShippingBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function seedCurrency(string $code = 'USD', int $decimalPlaces = 2): Currency
    {
        return Currency::query()->create([
            'code' => $code,
            'name' => ['en' => $code],
            'symbol' => ['en' => $code],
            'country_name' => ['en' => 'Country'],
            'decimal_places' => $decimalPlaces,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_builds_versioned_breakdown_reconciled_to_order_shipping_scalars(): void
    {
        $this->seedCurrency('USD', 2);

        $service = app(ShippingBreakdownService::class);

        $breakdown = $service->build([
            'currency' => 'USD',
            'base_shipping' => 20.00,
            'threshold_waiver' => 5.00,
            'coupon_waiver' => 0.00,
            'cod_fee' => null,
            'fast_shipping_fee' => 10.00,
        ]);

        $this->assertSame(1, $breakdown['version']);
        $this->assertSame('USD', $breakdown['currency']);
        $this->assertSame(2, $breakdown['precision']);
        $this->assertSame(20.00, $breakdown['base_shipping']);
        $this->assertSame(5.00, $breakdown['threshold_waiver']);
        $this->assertSame(0.00, $breakdown['coupon_waiver']);
        $this->assertNull($breakdown['cod_fee']);
        $this->assertSame(10.00, $breakdown['fast_shipping_fee']);
        $this->assertSame(15.00, $breakdown['net_shipping']);
        $this->assertSame(25.00, $breakdown['total']);
    }

    public function test_breakdown_total_is_shipping_subtotal_not_order_total(): void
    {
        $this->seedCurrency('USD', 2);
        $service = app(ShippingBreakdownService::class);

        $breakdown = $service->build([
            'currency' => 'USD',
            'base_shipping' => 30.00,
            'threshold_waiver' => 0.00,
            'coupon_waiver' => 10.00,
            'cod_fee' => null,
            'fast_shipping_fee' => 5.00,
        ]);

        // total = net_shipping + cod_fee + fast_shipping_fee = 20 + 0 + 5 = 25
        $this->assertSame(20.00, $breakdown['net_shipping']);
        $this->assertSame(25.00, $breakdown['total']);
        $this->assertArrayNotHasKey('order_total', $breakdown);
    }

    public function test_precision_comes_from_currency_definition(): void
    {
        $this->seedCurrency('KWD', 3);
        $service = app(ShippingBreakdownService::class);

        $breakdown = $service->build([
            'currency' => 'KWD',
            'base_shipping' => 1.255,
            'threshold_waiver' => 0.250,
            'coupon_waiver' => 0.000,
            'cod_fee' => null,
            'fast_shipping_fee' => 0.500,
        ]);

        $this->assertSame(3, $breakdown['precision']);
        $this->assertSame(1.005, $breakdown['net_shipping']);
        $this->assertSame(1.505, $breakdown['total']);
    }

    public function test_rejects_inconsistent_snapshot_net_shipping(): void
    {
        $this->seedCurrency('USD', 2);
        $service = app(ShippingBreakdownService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->build([
            'currency' => 'USD',
            'base_shipping' => 20.00,
            'threshold_waiver' => 5.00,
            'coupon_waiver' => 0.00,
            'cod_fee' => null,
            'fast_shipping_fee' => 10.00,
            'net_shipping' => 99.00,
        ]);
    }

    public function test_rejects_inconsistent_snapshot_total(): void
    {
        $this->seedCurrency('USD', 2);
        $service = app(ShippingBreakdownService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->build([
            'currency' => 'USD',
            'base_shipping' => 20.00,
            'threshold_waiver' => 0.00,
            'coupon_waiver' => 0.00,
            'cod_fee' => null,
            'fast_shipping_fee' => 0.00,
            'total' => 999.00,
        ]);
    }

    public function test_rejects_inconsistent_snapshot_precision(): void
    {
        $this->seedCurrency('USD', 2);
        $service = app(ShippingBreakdownService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->build([
            'currency' => 'USD',
            'precision' => 3,
            'base_shipping' => 20.00,
            'threshold_waiver' => 0.00,
            'coupon_waiver' => 0.00,
            'cod_fee' => null,
            'fast_shipping_fee' => 0.00,
        ]);
    }

    public function test_no_cod_fee_amount_is_invented_or_defaulted(): void
    {
        $this->seedCurrency('USD', 2);
        $service = app(ShippingBreakdownService::class);

        $breakdown = $service->build([
            'currency' => 'USD',
            'base_shipping' => 20.00,
            'threshold_waiver' => 0.00,
            'coupon_waiver' => 0.00,
            'fast_shipping_fee' => 0.00,
        ]);

        $this->assertNull($breakdown['cod_fee']);
        $this->assertSame(20.00, $breakdown['total']);
    }

    public function test_rejects_invented_cod_fee_default_value(): void
    {
        $this->seedCurrency('USD', 2);
        $service = app(ShippingBreakdownService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->build([
            'currency' => 'USD',
            'base_shipping' => 20.00,
            'threshold_waiver' => 0.00,
            'coupon_waiver' => 0.00,
            'cod_fee' => 0.00,
            'fast_shipping_fee' => 0.00,
        ], allowCodFee: false);
    }

    public function test_order_persists_breakdown_and_rejects_inconsistent_snapshot(): void
    {
        $this->seedCurrency('USD', 2);

        $order = \Marvel\Database\Models\Order::query()->forceCreate([
            'user_id' => null,
            'name' => 'T',
            'user_phone' => '1',
            'user_email' => 't@example.com',
            'address' => 'a',
            'status' => \Marvel\Database\Models\Order::ORDER_STATUS_PENDING,
            'shipping_price' => 15.00,
            'fast_shipping_fee' => 10.00,
            'cod_fee' => null,
            'price' => 100.00,
            'total_price' => 125.00,
            'currency_code' => 'USD',
            'shipping_breakdown' => [
                'version' => 1,
                'currency' => 'USD',
                'precision' => 2,
                'base_shipping' => 20.00,
                'threshold_waiver' => 5.00,
                'coupon_waiver' => 0.00,
                'cod_fee' => null,
                'fast_shipping_fee' => 10.00,
                'net_shipping' => 15.00,
                'total' => 25.00,
            ],
        ]);

        $order->refresh();
        $this->assertSame(25.00, (float) $order->shipping_breakdown['total']);
        $this->assertSame(15.00, (float) $order->shipping_breakdown['net_shipping']);
        $this->assertNull($order->cod_fee);

        $this->expectException(InvalidArgumentException::class);
        $order->shipping_breakdown = array_merge($order->shipping_breakdown, ['net_shipping' => 1.00, 'total' => 11.00]);
        $order->save();
    }

    public function test_cod_fee_scalar_cannot_be_invented(): void
    {
        $this->seedCurrency('USD', 2);

        $this->expectException(InvalidArgumentException::class);
        \Marvel\Database\Models\Order::query()->forceCreate([
            'user_id' => null,
            'name' => 'T',
            'user_phone' => '1',
            'user_email' => 't@example.com',
            'address' => 'a',
            'status' => \Marvel\Database\Models\Order::ORDER_STATUS_PENDING,
            'shipping_price' => 0.00,
            'fast_shipping_fee' => 0.00,
            'cod_fee' => 5.00,
            'price' => 100.00,
            'total_price' => 100.00,
            'currency_code' => 'USD',
        ]);
    }
}
