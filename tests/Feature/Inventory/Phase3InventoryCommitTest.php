<?php

namespace Tests\Feature\Inventory;

use App\Events\OrderCancelled;
use App\Listeners\ReleaseFulfillmentOnCodPlacement;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\General\OrderService;
use App\Services\Inventory\InventoryRestoreService;
use App\Services\Inventory\OrderReservationService;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 3 addendum: COD/cashier commit inventory AT CREATION through the
 * canonical OrderReservationService::commit() (active→committed claim),
 * exactly as a paid online order does — never inside Fulfillment.
 *
 * Real MySQL via RefreshDatabase: row locks, UNIQUE backstops and state
 * claims are genuinely exercised. True multi-PROCESS parallelism is NOT
 * executed here (single PHP process); concurrency-adjacent claims are
 * proven through serialized lock acquisition and labelled as such.
 */
class Phase3InventoryCommitTest extends TestCase
{
    use RefreshDatabase;

    private function makeWarehouse(string $code = 'WH-IC'): Warehouse
    {
        return Warehouse::create([
            'code' => $code . '-' . uniqid(), 'name' => $code, 'status' => 'active', 'is_default' => true,
        ]);
    }

    private function makeProduct(int $stock = 10, string $itemType = 'PHYSICAL'): Product
    {
        return Product::create([
            'name' => 'IC', 'slug' => 'ic-' . uniqid(), 'sku' => 'SKU-IC-' . strtoupper(uniqid()),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'item_type' => $itemType,
            'in_stock' => $stock > 0, 'stock_quantity' => $stock, 'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);
    }

    private function stockProduct(Warehouse $warehouse, Product $product, int $qty = 10): void
    {
        $location = Location::create([
            'warehouse_id' => $warehouse->id, 'code' => 'A-IC-' . uniqid(), 'barcode' => 'LOC-IC-' . uniqid(),
            'name' => 'Bin', 'type' => 'picking', 'status' => 'active', 'priority' => 1,
        ]);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'warehouse_id' => $warehouse->id, 'quantity' => $qty, 'allocated_hint' => 0,
        ]);
    }

    /**
     * HTTP COD checkout fixture (mirrors the proven Phase 3 E2E). Returns
     * the created order id. Asserts 200 internally.
     */
    private function checkoutCod(User $user, Product $product, int $quantity, array $extra = []): int
    {
        $country = \Marvel\Database\Models\Country::create(['name' => 'IC Country', 'status' => true]);
        $governorate = \Marvel\Database\Models\Governorate::create([
            'country_id' => $country->id, 'name' => 'IC Gov ' . uniqid(), 'status' => true,
        ]);
        \Marvel\Database\Models\ShippingPrice::create([
            'governorate_id' => $governorate->id, 'price' => 20, 'status' => true,
        ]);

        $cart = \Marvel\Database\Models\Cart::firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'active', 'total_price' => 0]
        );
        \Marvel\Database\Models\CartItem::create([
            'cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity,
            'price' => 100.00, 'total_price' => 100.00 * $quantity,
            'shipping_method' => 'SCHEDULED',
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/general/checkout', array_merge([
            'name' => 'IC', 'user_phone' => '01000000001', 'user_email' => $user->email,
            'address' => ['street' => '1 IC St'], 'governorate_id' => $governorate->id,
            'payment_method' => 'cod',
        ], $extra));

        $response->assertStatus(200);
        $orderId = (int) ($response->json('data.order_id') ?? 0);
        $this->assertGreaterThan(0, $orderId, 'Checkout must return an order_id');

        return $orderId;
    }

    /**
     * Service-level manual order (no events fire — no listeners run).
     * Mirrors the creation path's end state explicitly via the canonical
     * authority when $commit is true.
     */
    private function makeManualOrder(User $user, Product $product, int $quantity, string $method, bool $commit = false): Order
    {
        $order = Order::create([
            'user_id' => $user->id, 'name' => $user->name, 'user_email' => $user->email,
            'user_phone' => '01000000001', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'inventory_state' => Order::INVENTORY_STATE_NONE,
            'price' => 100 * $quantity, 'total_price' => 100 * $quantity,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => $method,
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => $product->name,
            'product_sku' => $product->sku, 'product_quantity' => $quantity,
            'product_price' => 100, 'product_total_price' => 100 * $quantity,
            'item_type' => $product->item_type ?? 'PHYSICAL',
        ]);
        $order = $order->refresh();
        app(OrderFlowService::class)->assignFlowToOrder($order, 'local');

        if ($commit) {
            app(OrderReservationService::class)->reserveForOrder($order->refresh());
            app(OrderReservationService::class)->commit($order->refresh());
        }

        return $order->refresh();
    }

    private function cancel(Order $order): void
    {
        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);
    }

    // ---- COD: creation commits, fulfillment follows ----

    public function test_cod_checkout_commits_inventory_and_releases_fulfillment(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(10);
        $this->stockProduct(Warehouse::first(), $product);

        $orderId = $this->checkoutCod($user, $product, 2);

        $order = Order::find($orderId);
        // Canonical commit at creation: ACTIVE never observed afterwards.
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);
        // Exact column effects: stock down, reserved back to zero, sold up.
        $product->refresh();
        $this->assertEquals(8, (int) $product->stock_quantity);
        $this->assertEquals(0, (int) $product->reserved_quantity);
        $this->assertEquals(2, (int) $product->sold_quantity);

        // Fulfillment FOLLOWS the commit (same deterministic auto key).
        $rows = Fulfillment::where('order_id', $orderId)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(
            FulfillmentService::automaticReleaseKey($orderId),
            $rows->first()->idempotency_key
        );
    }

    public function test_cashier_checkout_commits_inventory(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(10);
        $this->stockProduct(Warehouse::first(), $product);

        $orderId = $this->checkoutCod($user, $product, 3, ['payment_method' => 'pay_at_cashier']);

        $order = Order::find($orderId);
        $this->assertEquals('pay_at_cashier', $order->payment_method);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);
        $product->refresh();
        $this->assertEquals(7, (int) $product->stock_quantity);
        $this->assertEquals(0, (int) $product->reserved_quantity);
        $this->assertEquals(3, (int) $product->sold_quantity);
        $this->assertCount(1, Fulfillment::where('order_id', $orderId)->get());
    }

    public function test_insufficient_stock_cod_checkout_fails_with_no_order_and_no_fulfillment(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(1);
        $this->stockProduct(Warehouse::first(), $product);

        // Reuse the fixture but expect failure: build cart then checkout qty 2 > stock 1.
        $country = \Marvel\Database\Models\Country::create(['name' => 'ICF Country', 'status' => true]);
        $governorate = \Marvel\Database\Models\Governorate::create([
            'country_id' => $country->id, 'name' => 'ICF Gov ' . uniqid(), 'status' => true,
        ]);
        \Marvel\Database\Models\ShippingPrice::create([
            'governorate_id' => $governorate->id, 'price' => 20, 'status' => true,
        ]);
        $cart = \Marvel\Database\Models\Cart::create([
            'user_id' => $user->id, 'status' => 'active', 'total_price' => 200.00,
        ]);
        \Marvel\Database\Models\CartItem::create([
            'cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2,
            'price' => 100.00, 'total_price' => 200.00, 'shipping_method' => 'SCHEDULED',
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/general/checkout', [
            'name' => 'ICF', 'user_phone' => '01000000001', 'user_email' => $user->email,
            'address' => ['street' => '1 ICF St'], 'governorate_id' => $governorate->id,
            'payment_method' => 'cod',
        ]);

        $this->assertContains($response->status(), [400, 422]);
        // Fail-safe: NO order, NO fulfillment, counters untouched.
        $this->assertEquals(0, Order::where('user_id', $user->id)->count());
        $this->assertEquals(0, Fulfillment::count());
        $product->refresh();
        $this->assertEquals(1, (int) $product->stock_quantity);
        $this->assertEquals(0, (int) $product->reserved_quantity);
        $this->assertEquals(0, (int) $product->sold_quantity);
    }

    // ---- Online parity: creation reserves only; completion commits once ----

    public function test_online_completion_commits_exactly_once(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(10);
        $this->stockProduct(Warehouse::first(), $product);

        // Online-style: reserve at creation (ACTIVE, held but not deducted).
        $order = $this->makeManualOrder($user, $product, 2, 'online');
        app(OrderReservationService::class)->reserveForOrder($order);
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->refresh()->inventory_state);
        $this->assertEquals(2, (int) $product->refresh()->reserved_quantity);
        $this->assertEquals(10, (int) $product->stock_quantity);

        // Canonical completion commits; a second commit is a safe no-op.
        app(OrderService::class)->changeOrderStatus(null, 'completed', $order->id);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->refresh()->inventory_state);
        $this->assertFalse(app(OrderReservationService::class)->commit($order->refresh()));
        $product->refresh();
        $this->assertEquals(8, (int) $product->stock_quantity);
        $this->assertEquals(0, (int) $product->reserved_quantity);
        $this->assertEquals(2, (int) $product->sold_quantity);
    }

    public function test_mark_cod_paid_after_creation_does_not_double_commit(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(10);
        $this->stockProduct(Warehouse::first(), $product);

        $orderId = $this->checkoutCod($user, $product, 2);

        app(OrderService::class)->markCodAsPaid(Order::find($orderId));

        // Still exactly one deduction; still exactly one fulfillment.
        $product->refresh();
        $this->assertEquals(8, (int) $product->stock_quantity);
        $this->assertEquals(0, (int) $product->reserved_quantity);
        $this->assertEquals(2, (int) $product->sold_quantity);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, Order::find($orderId)->inventory_state);
        $this->assertCount(1, Fulfillment::where('order_id', $orderId)->get());
    }

    // ---- Cancellation: exactly-once restore ----

    public function test_admin_cancel_of_committed_unpaid_cod_restores_exactly_once(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(10);
        $this->stockProduct(Warehouse::first(), $product);

        $orderId = $this->checkoutCod($user, $product, 2);

        // Cancel #1 (canonical writer): committed → restored.
        $this->cancel(Order::find($orderId));
        $order = Order::find($orderId);
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_RESTORED, $order->inventory_state);
        $product->refresh();
        $this->assertEquals(10, (int) $product->stock_quantity);
        $this->assertEquals(0, (int) $product->sold_quantity);

        // Cancel retry (second writer): safe no-op, counters untouched.
        $this->assertFalse(app(InventoryRestoreService::class)->restore($order->refresh()));
        $product->refresh();
        $this->assertEquals(10, (int) $product->stock_quantity);
        $this->assertEquals(0, (int) $product->sold_quantity);

        // Cancellation worker racing in (OrderCancelled listener): unpaid
        // order has no paid_at, so it skips — still exactly one restore.
        event(new OrderCancelled($order->refresh()));
        $product->refresh();
        $this->assertEquals(10, (int) $product->stock_quantity);
        $this->assertEquals(Order::INVENTORY_STATE_RESTORED, $order->refresh()->inventory_state);
    }

    public function test_cancel_after_release_leaves_no_active_fulfillment_and_blocks_retry(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(10);
        $this->stockProduct(Warehouse::first(), $product);

        $orderId = $this->checkoutCod($user, $product, 2);
        $this->assertCount(1, Fulfillment::where('order_id', $orderId)->get());

        $this->cancel(Order::find($orderId));

        // Fulfillment cascade: no valid ACTIVE fulfillment survives a cancel.
        $this->assertEquals(0, Fulfillment::where('order_id', $orderId)->where('status', '!=', 'cancelled')->count());

        // Release retry after cancel is refused — never a second fulfillment.
        try {
            app(ReleaseFulfillmentOnCodPlacement::class)->handle(
                new \App\Events\OrderCreated(Order::find($orderId))
            );
            $this->fail('Release retry on a cancelled order must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cancelled', strtolower($e->getMessage()));
        }
        $this->assertCount(1, Fulfillment::where('order_id', $orderId)->get());
        $this->assertEquals(10, (int) $product->refresh()->stock_quantity);
    }

    // ---- Stock concurrency: committed quantity never exceeds available ----

    public function test_cod_vs_cod_second_order_cannot_commit_same_stock(): void
    {
        // Separate customers racing for the same 5 units (the pending-order
        // unique index allows one pending order per user — as in production).
        $userA = User::factory()->create(['type' => 'customer']);
        $userB = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(5);

        $orderA = $this->makeManualOrder($userA, $product, 5, 'cod');
        app(OrderReservationService::class)->reserveForOrder($orderA);
        app(OrderReservationService::class)->commit($orderA->refresh());

        $orderB = $this->makeManualOrder($userB, $product, 1, 'cod');
        try {
            app(OrderReservationService::class)->reserveForOrder($orderB);
            $this->fail('Second COD order must not reserve already-committed stock.');
        } catch (\App\Exceptions\InsufficientStockException $e) {
            // Expected: available (0) < requested (1).
        }

        // Invariant: committed quantity (5) <= available inventory (5).
        $product->refresh();
        $this->assertEquals(0, (int) $product->stock_quantity);
        $this->assertEquals(5, (int) $product->sold_quantity);
        $this->assertEquals(Order::INVENTORY_STATE_NONE, $orderB->refresh()->inventory_state);
    }

    public function test_online_hold_blocks_cod_and_cod_commit_blocks_online(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $userB = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(5);

        // Online holds 5 (ACTIVE reservation), COD for 1 must fail.
        $online = $this->makeManualOrder($user, $product, 5, 'online');
        app(OrderReservationService::class)->reserveForOrder($online);

        $cod = $this->makeManualOrder($userB, $product, 1, 'cod');
        try {
            app(OrderReservationService::class)->reserveForOrder($cod);
            $this->fail('COD must not reserve stock held by an online order.');
        } catch (\App\Exceptions\InsufficientStockException $e) {
        }

        // Inverse: COD commits 5, a second online hold for 1 must fail.
        $user2 = User::factory()->create(['type' => 'customer']);
        $product2 = $this->makeProduct(5);
        $cod2 = $this->makeManualOrder($user2, $product2, 5, 'cod');
        app(OrderReservationService::class)->reserveForOrder($cod2);
        app(OrderReservationService::class)->commit($cod2->refresh());

        $online2 = $this->makeManualOrder(User::factory()->create(['type' => 'customer']), $product2, 1, 'online');
        try {
            app(OrderReservationService::class)->reserveForOrder($online2);
            $this->fail('Online must not reserve stock committed to COD.');
        } catch (\App\Exceptions\InsufficientStockException $e) {
        }

        $this->assertEquals(0, (int) $product2->refresh()->stock_quantity);
    }

    public function test_duplicate_checkout_consumes_cart_once(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(10);
        $this->stockProduct(Warehouse::first(), $product);

        $orderId = $this->checkoutCod($user, $product, 2);

        // Same cart, second checkout: the slice is gone → clean 400, no order.
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $governorateId = Order::find($orderId)->governorate_id;
        $response = $this->postJson('/api/v1/general/checkout', [
            'name' => 'IC', 'user_phone' => '01000000001', 'user_email' => $user->email,
            'address' => ['street' => '1 IC St'], 'governorate_id' => $governorateId,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(400);
        $this->assertEquals(1, Order::where('user_id', $user->id)->count());
        $this->assertEquals(8, (int) $product->refresh()->stock_quantity);
    }

    // ---- Retry supersede: committed pending + re-checkout ----

    public function test_committed_pending_retry_is_superseded_by_fresh_order(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(10);
        $this->stockProduct(Warehouse::first(), $product);

        $firstId = $this->checkoutCod($user, $product, 2);
        $this->assertEquals(8, (int) $product->refresh()->stock_quantity);

        // Re-checkout with a new line: the committed pending is superseded
        // (canonical cancel → restore) and a fresh order commits the new line.
        $secondId = $this->checkoutCod($user, $product, 1);

        $this->assertNotEquals($firstId, $secondId);
        $first = Order::find($firstId);
        $this->assertEquals('cancelled', $first->status);
        $this->assertEquals(Order::INVENTORY_STATE_RESTORED, $first->inventory_state);
        $second = Order::find($secondId);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $second->inventory_state);

        // Counters reflect exactly the live order (qty 1): stock 9, sold 1.
        $product->refresh();
        $this->assertEquals(9, (int) $product->stock_quantity);
        $this->assertEquals(0, (int) $product->reserved_quantity);
        $this->assertEquals(1, (int) $product->sold_quantity);

        // Old fulfillment cancelled via cascade; new one pending.
        $this->assertEquals('cancelled', Fulfillment::where('order_id', $firstId)->first()->status);
        $this->assertEquals('pending', Fulfillment::where('order_id', $secondId)->first()->status);
    }

    // ---- Mixed / digital-only ----

    public function test_mixed_cod_commits_physical_lines_only(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $physical = $this->makeProduct(10, 'PHYSICAL');
        $digital = $this->makeProduct(10, 'DIGITAL');
        $this->stockProduct(Warehouse::first(), $physical);

        $country = \Marvel\Database\Models\Country::create(['name' => 'ICM Country', 'status' => true]);
        $governorate = \Marvel\Database\Models\Governorate::create([
            'country_id' => $country->id, 'name' => 'ICM Gov ' . uniqid(), 'status' => true,
        ]);
        \Marvel\Database\Models\ShippingPrice::create([
            'governorate_id' => $governorate->id, 'price' => 20, 'status' => true,
        ]);
        $cart = \Marvel\Database\Models\Cart::create([
            'user_id' => $user->id, 'status' => 'active', 'total_price' => 500.00,
        ]);
        foreach ([[$physical, 2], [$digital, 3]] as [$p, $q]) {
            \Marvel\Database\Models\CartItem::create([
                'cart_id' => $cart->id, 'product_id' => $p->id, 'quantity' => $q,
                'price' => 100.00, 'total_price' => 100.00 * $q, 'shipping_method' => 'SCHEDULED',
            ]);
        }

        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/general/checkout', [
            'name' => 'ICM', 'user_phone' => '01000000001', 'user_email' => $user->email,
            'address' => ['street' => '1 ICM St'], 'governorate_id' => $governorate->id,
            'payment_method' => 'cod',
        ]);
        $response->assertStatus(200);
        $orderId = (int) $response->json('data.order_id');

        // Only the 2 physical units moved counters; digital untouched.
        $this->assertEquals(8, (int) $physical->refresh()->stock_quantity);
        $this->assertEquals(2, (int) $physical->refresh()->sold_quantity);
        $this->assertEquals(10, (int) $digital->refresh()->stock_quantity);
        $this->assertEquals(0, (int) $digital->refresh()->sold_quantity);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, Order::find($orderId)->inventory_state);

        // Fulfillment carries physical lines only.
        $fulfillment = Fulfillment::where('order_id', $orderId)->first();
        $this->assertNotNull($fulfillment);
        $this->assertEquals(2, (int) $fulfillment->items()->sum('quantity'));
    }

    public function test_digital_only_cod_has_no_commit_and_no_fulfillment(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $digital = $this->makeProduct(10, 'DIGITAL');

        $country = \Marvel\Database\Models\Country::create(['name' => 'ICD Country', 'status' => true]);
        $governorate = \Marvel\Database\Models\Governorate::create([
            'country_id' => $country->id, 'name' => 'ICD Gov ' . uniqid(), 'status' => true,
        ]);
        \Marvel\Database\Models\ShippingPrice::create([
            'governorate_id' => $governorate->id, 'price' => 20, 'status' => true,
        ]);
        $cart = \Marvel\Database\Models\Cart::create([
            'user_id' => $user->id, 'status' => 'active', 'total_price' => 100.00,
        ]);
        \Marvel\Database\Models\CartItem::create([
            'cart_id' => $cart->id, 'product_id' => $digital->id, 'quantity' => 1,
            'price' => 100.00, 'total_price' => 100.00, 'shipping_method' => 'SCHEDULED',
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/general/checkout', [
            'name' => 'ICD', 'user_phone' => '01000000001', 'user_email' => $user->email,
            'address' => ['street' => '1 ICD St'], 'governorate_id' => $governorate->id,
            'payment_method' => 'cod',
        ]);
        $response->assertStatus(200);
        $orderId = (int) $response->json('data.order_id');

        // Entitlements path: ACTIVE (as before), counters untouched, no row.
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, Order::find($orderId)->inventory_state);
        $this->assertEquals(10, (int) $digital->refresh()->stock_quantity);
        $this->assertEquals(0, Fulfillment::where('order_id', $orderId)->count());
    }

    // ---- Sweeper preserves the invariants ----

    public function test_sweeper_releases_committed_cod_exactly_once(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(10);
        $this->stockProduct(Warehouse::first(), $product);

        // Committed COD with no fulfillment (event path missed it).
        $order = $this->makeManualOrder($user, $product, 2, 'cod');
        app(OrderReservationService::class)->reserveForOrder($order);
        app(OrderReservationService::class)->commit($order->refresh());

        $this->artisan('fulfillment:release-ready')->assertSuccessful();
        $rows = Fulfillment::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(
            FulfillmentService::automaticReleaseKey((int) $order->id),
            $rows->first()->idempotency_key
        );

        // Second sweep converges — never a duplicate.
        $this->artisan('fulfillment:release-ready')->assertSuccessful();
        $this->assertCount(1, Fulfillment::where('order_id', $order->id)->get());

        // The sweep performed no inventory math: counters still exact.
        $product->refresh();
        $this->assertEquals(8, (int) $product->stock_quantity);
        $this->assertEquals(2, (int) $product->sold_quantity);
    }
}
