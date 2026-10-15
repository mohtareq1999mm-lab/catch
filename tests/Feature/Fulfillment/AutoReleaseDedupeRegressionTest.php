<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * OF-001 / WMS-001 regression: keyless and automatic releases must converge.
 *
 * Real `catch` evidence (2026-10-14): order 13 gained two pending rows —
 * a NULL-key keyless release followed by `auto-release-order-13` — because
 * the keyed path skipped pending reuse. Orders 4/5 gained an extra pending
 * alongside active rows, stalling sync aggregation.
 */
class AutoReleaseDedupeRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function makeWarehouse(string $code = 'WH-A', bool $default = true): Warehouse
    {
        return Warehouse::create([
            'code' => $code, 'name' => $code, 'status' => 'active', 'is_default' => $default,
        ]);
    }

    private function makeOrder(User $user, Product $product): Order
    {
        $order = Order::create([
            'user_id' => $user->id, 'name' => $user->name, 'user_email' => $user->email,
            'user_phone' => '01000000001', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => $product->name,
            'product_sku' => $product->sku, 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        return $order->refresh();
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'name' => 'P', 'slug' => 'p-'.uniqid(), 'sku' => 'SKU-'.strtoupper(uniqid()),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0,
        ]);
    }

    public function test_keyless_then_automatic_returns_same_row(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());

        $keyless = app(FulfillmentService::class)->releaseForOrder($order->refresh(), null, null);
        $this->assertNull($keyless->idempotency_key);

        $auto = app(FulfillmentService::class)->releaseForOrder(
            $order->refresh(), null, FulfillmentService::automaticReleaseKey((int) $order->id)
        );

        $this->assertEquals($keyless->id, $auto->id);
        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
        $this->assertEquals(
            FulfillmentService::automaticReleaseKey((int) $order->id),
            $auto->refresh()->idempotency_key
        );
    }

    public function test_keyless_does_not_duplicate_active_fulfillment(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());

        $first = app(FulfillmentService::class)->releaseForOrder($order->refresh(), null, null);
        app(FulfillmentTransition::class)->transition($first->refresh(), 'picking');

        $again = app(FulfillmentService::class)->releaseForOrder($order->refresh(), null, null);

        $this->assertEquals($first->id, $again->id);
        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_custom_split_key_still_creates_second_row(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());

        $first = app(FulfillmentService::class)->releaseForOrder($order->refresh(), null, null);
        $split = app(FulfillmentService::class)->releaseForOrder($order->refresh(), null, 'op-split-same-wh');

        $this->assertNotEquals($first->id, $split->id);
        $this->assertEquals(2, Fulfillment::where('order_id', $order->id)->count());
    }
}
