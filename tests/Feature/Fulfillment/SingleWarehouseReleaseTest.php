<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\ProductLocation;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 2 (P2-4) — P2-3 single-warehouse fulfillment.
 *
 * Guard: keyless (normal) release must not open a second warehouse for an
 * order with a non-cancelled fulfillment elsewhere. Keyed calls are explicit
 * split intent and bypass the guard (existing supported mechanism).
 */
class SingleWarehouseReleaseTest extends TestCase
{
    use RefreshDatabase;

    private $whA;
    private $whB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whA = \App\Models\Fulfillment\Warehouse::create([
            'code' => 'WH-A', 'name' => 'A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->whB = \App\Models\Fulfillment\Warehouse::create([
            'code' => 'WH-B', 'name' => 'B', 'status' => 'active', 'is_default' => false,
        ]);
    }

    private function makeProduct(int $stock = 10): Product
    {
        return Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-' . strtoupper(uniqid()),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => $stock > 0, 'stock_quantity' => $stock, 'reserved_quantity' => 0,
        ]);
    }

    private function makeOrder(User $user, Product $product, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $user->id, 'name' => $user->name, 'user_email' => $user->email,
            'user_phone' => '01000000001', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ], $overrides));
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => $product->name,
            'product_sku' => $product->sku, 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        return $order->refresh();
    }

    public function test_second_normal_warehouse_rejected(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());
        $service = app(FulfillmentService::class);

        $first = $service->releaseForOrder($order, $this->whA->id);

        try {
            $service->releaseForOrder($order->refresh(), $this->whB->id);
            $this->fail('second normal warehouse must reject');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('second normal fulfillment', strtolower($e->getMessage()));
        }

        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
        $this->assertEquals($first->id, Fulfillment::where('order_id', $order->id)->first()->id);
    }

    public function test_explicit_split_key_preserved_across_warehouses(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());
        $service = app(FulfillmentService::class);

        $first = $service->releaseForOrder($order, $this->whA->id, 'sw-split-1');
        $second = $service->releaseForOrder($order->refresh(), $this->whB->id, 'sw-split-2');

        $this->assertNotEquals($first->id, $second->id);
        $this->assertEquals(2, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_same_warehouse_keyless_reuse_preserved(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());
        $service = app(FulfillmentService::class);

        $first = $service->releaseForOrder($order, $this->whA->id);
        $again = $service->releaseForOrder($order->refresh(), $this->whA->id);

        $this->assertEquals($first->id, $again->id);
        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_cancelled_fulfillment_does_not_block_other_warehouse(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());
        $service = app(FulfillmentService::class);

        $first = $service->releaseForOrder($order, $this->whA->id);
        $service->cancelFulfillment($first, 'customer_cancelled');

        $second = $service->releaseForOrder($order->refresh(), $this->whB->id);

        $this->assertEquals('cancelled', $first->refresh()->status);
        $this->assertEquals('pending', $second->status);
        $this->assertEquals($this->whB->id, (int) $second->warehouse_id);
    }

    public function test_cod_and_failed_payment_behavior_preserved(): void
    {
        $user = User::factory()->create(['type' => 'customer']);

        // COD with active reservation still releases (normal single warehouse).
        $cod = $this->makeOrder($user, $this->makeProduct(), [
            'payment_method' => 'cod',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
        ]);
        $f = app(FulfillmentService::class)->releaseForOrder($cod, $this->whA->id);
        $this->assertEquals('pending', $f->status);

        // Unpaid online still rejected (capture gate untouched).
        $unpaidUser = User::factory()->create(['type' => 'customer']);
        $unpaid = $this->makeOrder($unpaidUser, $this->makeProduct(), [
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
        ]);
        try {
            app(FulfillmentService::class)->releaseForOrder($unpaid, $this->whA->id);
            $this->fail('unpaid online must reject');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not captured', $e->getMessage());
        }
    }
}
