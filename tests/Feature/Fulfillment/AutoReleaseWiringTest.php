<?php

namespace Tests\Feature\Fulfillment;

use App\Events\OrderCreated;
use App\Events\PaymentSucceeded;
use App\Listeners\ReleaseFulfillmentOnCodPlacement;
use App\Listeners\ReleaseFulfillmentOnPayment;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 3 (P2-4 → T3): automatic fulfillment wiring.
 *
 * - Online: verified PaymentSucceeded → exactly one pending fulfillment.
 * - COD: post-commit OrderCreated + ACTIVE reservation → exactly one.
 * - Replays reuse the deterministic automatic key (no duplicates).
 * - Digital-only orders stay on the entitlements path (no physical rows).
 * - Boundaries: inventory / order status / transition authority intact.
 */
class AutoReleaseWiringTest extends TestCase
{
    use RefreshDatabase;

    private function makeWarehouse(string $code = 'WH-A', bool $default = true): Warehouse
    {
        return Warehouse::create([
            'code' => $code, 'name' => $code, 'status' => 'active', 'is_default' => $default,
        ]);
    }

    private function makeProduct(int $stock = 10, string $itemType = 'PHYSICAL'): Product
    {
        return Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-' . strtoupper(uniqid()),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'item_type' => $itemType,
            'in_stock' => $stock > 0, 'stock_quantity' => $stock, 'reserved_quantity' => 0,
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

    private function makeCodOrder(User $user, Product $product, array $overrides = []): Order
    {
        return $this->makeOrder($user, $product, array_merge([
            'payment_method' => 'cod',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
        ], $overrides));
    }

    private function onPayment(Order $order): void
    {
        app(ReleaseFulfillmentOnPayment::class)->handle(new PaymentSucceeded($order));
    }

    private function onCreated(Order $order): void
    {
        app(ReleaseFulfillmentOnCodPlacement::class)->handle(new OrderCreated($order));
    }

    // ---- Online path ----

    public function test_verified_payment_creates_exactly_one_pending_fulfillment(): void
    {
        $warehouse = $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());

        $this->onPayment($order);

        $rows = Fulfillment::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('pending', $rows->first()->status);
        $this->assertEquals($warehouse->id, (int) $rows->first()->warehouse_id);
        $this->assertEquals(
            FulfillmentService::automaticReleaseKey($order->id),
            $rows->first()->idempotency_key
        );
    }

    public function test_payment_replay_reuses_existing_fulfillment(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());

        $this->onPayment($order);
        $this->onPayment($order->refresh());
        $this->onPayment($order->refresh());

        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_failed_payment_creates_nothing(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct(), [
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
        ]);

        try {
            $this->onPayment($order);
            $this->fail('uncaptured order must not auto-release');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not captured', $e->getMessage());
        }
        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_digital_only_payment_creates_no_physical_fulfillment(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct(100, 'DIGITAL'), [], 'DIGITAL');

        $this->onPayment($order);

        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_mixed_cart_releases_for_physical_lines(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct(), [], 'PHYSICAL');
        $digital = $this->makeProduct(100, 'DIGITAL');
        $order->orderItems()->create([
            'product_id' => $digital->id, 'product_name' => $digital->name,
            'product_sku' => $digital->sku, 'product_quantity' => 1,
            'product_price' => 100, 'product_total_price' => 100,
            'item_type' => 'DIGITAL',
        ]);

        $this->onPayment($order->refresh());

        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_terminal_order_skips_without_retry_noise(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct(), ['status' => 'cancelled']);

        $this->onPayment($order); // must not throw: permanent state, no retry

        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
    }

    // ---- COD path ----

    public function test_cod_placement_creates_exactly_one_without_paid_transaction(): void
    {
        $warehouse = $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct());

        $this->onCreated($order);

        $rows = Fulfillment::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('pending', $rows->first()->status);
        $this->assertEquals($warehouse->id, (int) $rows->first()->warehouse_id);
        $this->assertEquals(Order::PAYMENT_STATUS_PENDING, $order->refresh()->payment_status);
    }

    public function test_cod_trigger_replay_creates_no_duplicate(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct());

        $this->onCreated($order);
        $this->onCreated($order->refresh());

        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_cod_without_active_reservation_creates_nothing(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct(), [
            'inventory_state' => Order::INVENTORY_STATE_NONE,
        ]);

        $this->onCreated($order); // orphan guard: no release before reservation

        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_online_order_created_event_creates_nothing(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());

        $this->onCreated($order); // online waits for verified payment

        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_cod_digital_only_creates_nothing(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct(100, 'DIGITAL'), [], 'DIGITAL');

        $this->onCreated($order);

        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
    }

    // ---- Warehouse selection ----

    public function test_automatic_release_uses_active_default(): void
    {
        $default = $this->makeWarehouse('WH-D', true);
        $this->makeWarehouse('WH-X', false);
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct());

        $this->onCreated($order);

        $this->assertEquals($default->id, (int) Fulfillment::where('order_id', $order->id)->first()->warehouse_id);
    }

    public function test_no_active_default_fails_loudly_without_rows(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct());

        try {
            $this->onCreated($order);
            $this->fail('missing default must fail loudly');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('default', strtolower($e->getMessage()));
        }
        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
        // Prior ACTIVE reservation is untouched by the failed release.
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->refresh()->inventory_state);
    }

    public function test_deleted_default_is_not_selected(): void
    {
        $a = $this->makeWarehouse('WH-A', true);
        $this->makeWarehouse('WH-B', false);
        // Test-only probe: trash the default beneath the model guard to prove
        // resolution never selects a soft-deleted default.
        DB::table('warehouses')->where('id', $a->id)->update(['deleted_at' => now()]);
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct());

        try {
            $this->onCreated($order);
            $this->fail('deleted default must not resolve');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('default', strtolower($e->getMessage()));
        }
        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
    }

    // ---- Boundaries ----

    public function test_auto_release_leaves_inventory_and_order_status_untouched(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $product = $this->makeProduct(10);
        $order = $this->makeOrder($user, $product);

        $this->onPayment($order);

        $this->assertEquals(10, (int) $product->refresh()->stock_quantity);
        $this->assertEquals(0, (int) $product->refresh()->reserved_quantity);
        $this->assertEquals('pending', $order->refresh()->status);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->refresh()->inventory_state);
    }

    public function test_auto_created_fulfillment_lifecycle_stays_transition_owned(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct());

        $this->onCreated($order);
        $fulfillment = Fulfillment::where('order_id', $order->id)->firstOrFail();

        $moved = app(FulfillmentTransition::class)->transition($fulfillment, 'picking');
        $this->assertEquals('picking', $moved->status);

        try {
            app(FulfillmentTransition::class)->transition($moved, 'delivered');
            $this->fail('illegal jump must reject');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid fulfillment transition', $e->getMessage());
        }
    }

    // ---- Idempotency & splits ----

    public function test_automatic_key_is_stable_and_namespaced(): void
    {
        $this->assertSame('auto-release-order-42', FulfillmentService::automaticReleaseKey(42));
        $this->assertSame(
            FulfillmentService::automaticReleaseKey(42),
            FulfillmentService::automaticReleaseKey(42)
        );
    }

    public function test_automatic_key_does_not_break_explicit_keyed_splits(): void
    {
        $this->makeWarehouse('WH-A', true);
        $other = Warehouse::create(['code' => 'WH-B', 'name' => 'WH-B', 'status' => 'active', 'is_default' => false]);
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct());

        $this->onCreated($order); // auto key, default warehouse
        app(FulfillmentService::class)->releaseForOrder($order->refresh(), $other->id, 'op-split-1');
        $this->onCreated($order->refresh()); // auto replay: still 2 rows

        $this->assertEquals(2, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_failed_listener_contract_and_queue_placement(): void
    {
        $payment = new ReleaseFulfillmentOnPayment(
            app(FulfillmentService::class),
            app(\App\Services\Inventory\OrderReservationService::class)
        );
        $cod = new ReleaseFulfillmentOnCodPlacement(
            app(FulfillmentService::class),
            app(\App\Services\Inventory\OrderReservationService::class)
        );

        $this->assertSame(config('queue.queues.high'), $payment->viaQueue());
        $this->assertSame(config('queue.queues.high'), $cod->viaQueue());

        // failed() only logs — never throws (queue must settle).
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct());
        $payment->failed(new PaymentSucceeded($order), new \RuntimeException('boom'));
        $cod->failed(new OrderCreated($order), new \RuntimeException('boom'));
        $this->assertTrue(true);
    }

    public function test_events_queue_both_listeners_on_high(): void
    {
        Queue::fake();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct());

        event(new PaymentSucceeded($order));
        event(new OrderCreated($order));

        Queue::assertPushedOn(
            config('queue.queues.high'),
            \Illuminate\Events\CallQueuedListener::class,
            fn ($job) => str_contains($job->class, 'ReleaseFulfillmentOnPayment')
        );
        Queue::assertPushedOn(
            config('queue.queues.high'),
            \Illuminate\Events\CallQueuedListener::class,
            fn ($job) => str_contains($job->class, 'ReleaseFulfillmentOnCodPlacement')
        );
    }

    /**
     * P9-10: cross-trigger convergence. A COD order released at placement
     * that later reports verified payment (COD collected, gateway-confirmed
     * deposit, manual mark-paid) must NOT gain a second fulfillment: both
     * listeners use the same order-scoped automatic key.
     */
    public function test_cod_then_payment_converges_to_single_fulfillment(): void
    {
        $this->makeWarehouse();
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeCodOrder($user, $this->makeProduct());

        $this->onCreated($order);
        $firstId = Fulfillment::where('order_id', $order->id)->firstOrFail()->id;

        // Payment later succeeds for the same order (commit mirrors the
        // reservation lifecycle: ACTIVE → COMMITTED on capture).
        $order->update([
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
        ]);

        $this->onPayment($order->refresh());
        $this->onPayment($order->refresh());

        $rows = Fulfillment::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($firstId, $rows->first()->id);
    }
}
