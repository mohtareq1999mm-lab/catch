<?php

namespace Tests\Feature\Fulfillment;

use App\Console\Commands\ReleaseReadyFulfillments;
use App\Events\OrderCreated;
use App\Events\PaymentSucceeded;
use App\Listeners\ReleaseFulfillmentOnCodPlacement;
use App\Listeners\ReleaseFulfillmentOnPayment;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\General\OrderService;
use App\Services\Inventory\OrderReservationService;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 3 hardening: mixed-cart physical-only release (D1), cashier
 * auto-release trigger (D2), bounded retries + recovery sweeper (D3),
 * and the §10/§28/§29 race matrix. Real MySQL via RefreshDatabase —
 * locks, unique backstops and transactions are genuinely exercised.
 */
class Phase3ReleaseHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeWarehouse(string $code = 'WH-P3', bool $default = true): Warehouse
    {
        return Warehouse::create([
            'code' => $code, 'name' => $code, 'status' => 'active', 'is_default' => $default,
        ]);
    }

    private function makeLocation(Warehouse $warehouse): Location
    {
        return Location::create([
            'warehouse_id' => $warehouse->id, 'code' => 'A-P3-' . uniqid(), 'barcode' => 'LOC-P3-' . uniqid(),
            'name' => 'Bin', 'type' => 'picking', 'status' => 'active', 'priority' => 1,
        ]);
    }

    private function makeProduct(int $stock = 20, string $itemType = 'PHYSICAL'): Product
    {
        $product = Product::create([
            'name' => 'P3', 'slug' => 'p3-' . uniqid(), 'sku' => 'SKU-P3-' . strtoupper(uniqid()),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'item_type' => $itemType,
            'in_stock' => $stock > 0, 'stock_quantity' => $stock, 'reserved_quantity' => 0,
        ]);

        return $product;
    }

    private function stockProduct(Warehouse $warehouse, Product $product, int $qty = 20): void
    {
        $location = $this->makeLocation($warehouse);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'warehouse_id' => $warehouse->id, 'quantity' => $qty, 'allocated_hint' => 0,
        ]);
    }

    private function makeOrder(User $user, Product $product, array $overrides = [], string $itemType = 'PHYSICAL'): Order
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
            'item_type' => $itemType,
        ]);

        return $order->refresh();
    }

    private function makeManualOrder(User $user, Product $product, string $method): Order
    {
        return $this->makeOrder($user, $product, [
            'payment_method' => $method,
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
        ]);
    }

    private function assignFlow(Order $order): Order
    {
        app(OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        return $order->refresh();
    }

    private function onPayment(Order $order): void
    {
        app(ReleaseFulfillmentOnPayment::class)->handle(new PaymentSucceeded($order));
    }

    private function onCreated(Order $order): void
    {
        app(ReleaseFulfillmentOnCodPlacement::class)->handle(new OrderCreated($order));
    }

    // ---- D1: mixed orders release physical lines only ----

    public function test_mixed_order_fulfillment_contains_physical_items_only(): void
    {
        $warehouse = $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $physical = $this->makeProduct();
        $digital = $this->makeProduct(100, 'DIGITAL');
        $this->stockProduct($warehouse, $physical);

        $order = $this->makeOrder($user, $physical);
        $order->orderItems()->create([
            'product_id' => $digital->id, 'product_name' => $digital->name,
            'product_sku' => $digital->sku, 'product_quantity' => 1,
            'product_price' => 50, 'product_total_price' => 50,
            'item_type' => 'DIGITAL',
        ]);

        $this->onPayment($order->refresh());

        $fulfillment = Fulfillment::where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(
            [$physical->id],
            $fulfillment->items()->pluck('product_id')->all(),
            'Digital lines must never enter physical WMS'
        );
        $this->assertFalse(
            FulfillmentItem::where('fulfillment_id', $fulfillment->id)
                ->whereNull('product_location_id')->exists(),
            'No unallocatable digital residue may remain'
        );
    }

    public function test_direct_release_of_digital_only_order_is_refused(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        // Digital-only COD with an ACTIVE reservation (releasable except for D1).
        $order = $this->makeManualOrder($user, $this->makeProduct(100, 'DIGITAL'), 'cod');
        $order->orderItems()->update(['item_type' => 'DIGITAL']);

        try {
            app(FulfillmentService::class)->releaseForOrder($order->refresh());
            $this->fail('Digital-only release must throw (no fake empty fulfillment)');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no physical lines', strtolower($e->getMessage()));
        }

        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
    }

    // ---- D2: cashier auto-release ----

    public function test_cashier_placement_creates_exactly_one_fulfillment(): void
    {
        $warehouse = $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeManualOrder($user, $this->makeProduct(), 'pay_at_cashier');

        $this->onCreated($order);

        $rows = Fulfillment::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('pending', $rows->first()->status);
        $this->assertEquals($warehouse->id, (int) $rows->first()->warehouse_id);
        $this->assertEquals(
            FulfillmentService::automaticReleaseKey($order->id),
            $rows->first()->idempotency_key
        );
    }

    public function test_cashier_trigger_replay_creates_no_duplicate(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeManualOrder($user, $this->makeProduct(), 'pay_at_cashier');

        $this->onCreated($order);
        $this->onCreated($order->refresh());

        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_cashier_then_mark_paid_converges_to_single_fulfillment(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeManualOrder($user, $this->makeProduct(), 'pay_at_cashier');

        $this->onCreated($order);
        $firstId = Fulfillment::where('order_id', $order->id)->firstOrFail()->id;

        // Mark-paid capture: ACTIVE → COMMITTED, pending → success.
        $order->update([
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
        ]);

        $this->onPayment($order->refresh());
        $this->onPayment($order->refresh());

        $rows = Fulfillment::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($firstId, (int) $rows->first()->id);
    }

    // ---- Zero-value release ----

    public function test_zero_value_completed_order_releases(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct(), ['total_price' => 0, 'price' => 0]);

        $this->onPayment($order);

        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
    }

    // ---- Cancellation races ----

    public function test_release_after_cancel_is_refused(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->assignFlow($this->makeOrder($user, $this->makeProduct()));

        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);

        try {
            app(FulfillmentService::class)->releaseForOrder($order->refresh());
            $this->fail('Release on a cancelled order must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cancelled', strtolower($e->getMessage()));
        }

        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_release_then_cancel_cascades_to_fulfillment(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        // COD: released at placement while pending.
        $order = $this->assignFlow($this->makeManualOrder($user, $this->makeProduct(), 'cod'));

        $this->onCreated($order);
        $fulfillment = Fulfillment::where('order_id', $order->id)->firstOrFail();

        // Reaper-style cancel with never-paid parity flags.
        app(OrderService::class)->changeOrderStatus(
            null, 'cancelled', $order->id, true, 'reservation expired', ['trigger' => 'test'],
            true, [], true, true
        );

        $this->assertEquals('cancelled', $fulfillment->refresh()->status);
        $this->assertEquals(
            Order::INVENTORY_STATE_RELEASED,
            $order->refresh()->inventory_state
        );
    }

    // ---- Split semantics (§16) ----

    public function test_distinct_keys_split_same_key_reuses(): void
    {
        $warehouse = $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());
        $service = app(FulfillmentService::class);

        $first = $service->releaseForOrder($order->refresh(), $warehouse->id, 'test-split-a');
        $again = $service->releaseForOrder($order->refresh(), $warehouse->id, 'test-split-a');
        $this->assertSame($first->id, $again->id);

        $second = $service->releaseForOrder($order->refresh(), $warehouse->id, 'test-split-b');
        $this->assertNotSame($first->id, $second->id);
        $this->assertEquals(2, Fulfillment::where('order_id', $order->id)->count());
    }

    // ---- D3: recovery sweeper ----

    public function test_sweeper_releases_missed_eligible_order(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        // Paid + committed, physical, but the event path never ran.
        $order = $this->makeOrder($user, $this->makeProduct());
        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());

        $this->artisan('fulfillment:release-ready')->assertExitCode(0);

        $rows = Fulfillment::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(FulfillmentService::automaticReleaseKey($order->id), $rows->first()->idempotency_key);
    }

    public function test_sweeper_skips_ineligible_orders(): void
    {
        $this->makeWarehouse();
        // One pending order per user (production unique invariant) — use
        // a distinct customer per fixture order.
        $unpaid = $this->makeOrder(User::factory()->create(['type' => 'customer']), $this->makeProduct(), [
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
        ]);
        $digital = $this->makeOrder(User::factory()->create(['type' => 'customer']), $this->makeProduct(100, 'DIGITAL'), [], 'DIGITAL');
        $cancelled = $this->assignFlow($this->makeOrder(User::factory()->create(['type' => 'customer']), $this->makeProduct()));
        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $cancelled->id);

        $this->artisan('fulfillment:release-ready')->assertExitCode(0);

        $this->assertEquals(0, Fulfillment::where('order_id', $unpaid->id)->count());
        $this->assertEquals(0, Fulfillment::where('order_id', $digital->id)->count());
        $this->assertEquals(0, Fulfillment::where('order_id', $cancelled->id)->count());
    }

    public function test_sweeper_is_idempotent_for_already_released_orders(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());

        $this->onPayment($order);

        $this->artisan('fulfillment:release-ready')->assertExitCode(0);
        $this->artisan('fulfillment:release-ready')->assertExitCode(0);

        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
    }

    // ---- D3: bounded retry contract ----

    public function test_listeners_declare_bounded_retry(): void
    {
        $payment = new ReleaseFulfillmentOnPayment(
            app(FulfillmentService::class),
            app(OrderReservationService::class)
        );
        $manual = new ReleaseFulfillmentOnCodPlacement(
            app(FulfillmentService::class),
            app(OrderReservationService::class)
        );

        foreach ([$payment, $manual] as $listener) {
            $this->assertSame(5, $listener->tries);
            $this->assertSame([10, 30, 60, 120, 300], $listener->backoff);
        }
    }

    public function test_release_command_is_registered_and_scheduled(): void
    {
        $this->assertInstanceOf(ReleaseReadyFulfillments::class, app(ReleaseReadyFulfillments::class));

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $this->assertTrue(
            $events->contains(fn ($e) => str_contains((string) $e->command, 'fulfillment:release-ready')),
            'fulfillment:release-ready must be scheduled'
        );
    }

    // ---- §35 end-to-end: HTTP checkout → release → row ----

    public function test_http_cod_checkout_creates_fulfillment_end_to_end(): void
    {
        $warehouse = $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct();
        $this->stockProduct($warehouse, $product);

        $country = \Marvel\Database\Models\Country::create(['name' => 'E2E Country', 'status' => true]);
        $governorate = \Marvel\Database\Models\Governorate::create([
            'country_id' => $country->id, 'name' => 'E2E Gov', 'status' => true,
        ]);
        \Marvel\Database\Models\ShippingPrice::create([
            'governorate_id' => $governorate->id, 'price' => 20, 'status' => true,
        ]);

        $cart = \Marvel\Database\Models\Cart::create([
            'user_id' => $user->id, 'status' => 'active', 'total_price' => 100.00,
        ]);
        \Marvel\Database\Models\CartItem::create([
            'cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1,
            'price' => 100.00, 'total_price' => 100.00,
            'shipping_method' => 'SCHEDULED',
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/general/checkout', [
            'name' => 'E2E', 'user_phone' => '01000000001', 'user_email' => $user->email,
            'address' => ['street' => '1 E2E St'], 'governorate_id' => $governorate->id,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(200);
        $orderId = (int) ($response->json('data.order_id') ?? 0);
        $this->assertGreaterThan(0, $orderId, 'Checkout must return an order_id');

        // Entry point → service → transaction → event → listener → release:
        // exactly one fulfillment row bound to the order with the auto key.
        $rows = Fulfillment::where('order_id', $orderId)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('pending', $rows->first()->status);
        $this->assertEquals($warehouse->id, (int) $rows->first()->warehouse_id);
        $this->assertEquals(
            FulfillmentService::automaticReleaseKey($orderId),
            $rows->first()->idempotency_key
        );
    }
}
