<?php

namespace Tests\Feature\Fulfillment;

use App\Events\OrderCancelled;
use App\Listeners\RestoreProductInventory;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Fulfillment\BatchPickingService;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Fulfillment\OrderPickingService;
use App\Services\Fulfillment\PackingService;
use App\Services\Fulfillment\PickingExecutionService;
use App\Services\General\OrderService;
use App\Services\Inventory\InventoryRestoreService;
use App\Services\Inventory\OrderReservationService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Refund;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 7 — Cancellation & Exceptions (P7-1 → P7-8, D7-1…D7-8).
 * Real database, no mocks. Single-process harness: races are proven via
 * stale models + lock reasoning and the limitation is documented per test.
 */
class CancellationPhase7Test extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Location $location;

    private Product $product;

    private User $picker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'WH-7', 'name' => 'Main 7', 'status' => 'active', 'is_default' => true,
        ]);
        $this->location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'A-71',
            'barcode' => 'LOC-A-71', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'P7', 'slug' => 'p7-' . uniqid(), 'sku' => 'SKU-P7-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
        $this->picker = User::factory()->create(['type' => 'customer']);
    }

    private function makeOrder(array $overrides = []): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create(array_merge([
            'user_id' => $user->id, 'name' => 'O7', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 1000, 'total_price' => 1000,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ], $overrides));
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)
            ->update(['order_number' => 'ORD7-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT)]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P7',
            'product_sku' => 'SKU-P7-1', 'product_quantity' => 10,
            'product_price' => 100, 'product_total_price' => 1000,
        ]);
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        return $order->refresh();
    }

    private function reserve(Order $order): void
    {
        $this->assertTrue(app(OrderReservationService::class)->reserveForOrder($order) ?? true);
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->refresh()->inventory_state);
    }

    private function release(string $key, ?Order $order = null): Fulfillment
    {
        $order = ($order ?? $this->makeOrder())->refresh();
        if ($order->inventory_state === Order::INVENTORY_STATE_NONE) {
            // Arrange releasable inventory the way production does:
            // deferred methods release on ACTIVE, capture methods on COMMITTED.
            $this->reserve($order);
            $order = $order->refresh();
            $deferred = in_array($order->payment_method, ['cod', 'pay_at_cashier'], true);
            if (!$deferred && $order->payment_status === Order::PAYMENT_STATUS_SUCCESS) {
                app(OrderReservationService::class)->commit($order);
                $order = $order->refresh();
            }
        }

        return app(FulfillmentService::class)->releaseForOrder($order, $this->warehouse->id, $key);
    }

    private function toReadyToShip(Fulfillment $fulfillment): Fulfillment
    {
        $t = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship'] as $state) {
            $t->transition($fulfillment, $state);
            $fulfillment = $fulfillment->refresh();
        }

        return $fulfillment;
    }

    private function cancelOrder(Order $order, ?string $reason = null): Order
    {
        $result = app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id, true, $reason);

        $this->assertNotFalse($result);

        return $order->refresh();
    }

    // ---------------- P7-1: order → fulfillment cascade ----------------

    public function test_p71_order_cancel_cascades_to_pending_fulfillment(): void
    {
        $order = $this->makeOrder();
        $this->reserve($order);
        $fulfillment = $this->release('p71-1', $order);

        $this->cancelOrder($order->refresh(), 'customer changed mind');

        $this->assertEquals('cancelled', $order->refresh()->status);
        $cancelled = $fulfillment->refresh();
        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertEquals('order_cancel', $cancelled->cancel_source);
        // No authenticated actor in tests → nullable, never fabricated.
        $this->assertNull($cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertEquals(Order::FULFILLMENT_STATUS_CANCELLED, $order->refresh()->fulfillment_status);
    }

    public function test_p71_cascade_paid_committed_restores_exactly_once(): void
    {
        $order = $this->makeOrder([
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'paid_at' => now(),
        ]);
        $this->reserve($order);
        $this->assertTrue(app(OrderReservationService::class)->commit($order->refresh()));
        $this->assertEquals(40, $this->product->refresh()->stock_quantity);
        $fulfillment = $this->release('p71-2', $order->refresh());

        $this->cancelOrder($order->refresh());

        // Sync restore credited once: 40 → 50.
        $this->assertEquals(50, $this->product->refresh()->stock_quantity);
        $this->assertEquals(Order::INVENTORY_STATE_RESTORED, $order->refresh()->inventory_state);
        $this->assertEquals('cancelled', $fulfillment->refresh()->status);

        // The queued listener (runs inline in production; invoked directly
        // here because ShouldDispatchAfterCommit never fires inside test
        // transactions) must be a safe no-op — still exactly 50.
        app(RestoreProductInventory::class)->handle(new OrderCancelled($order->refresh()));
        app(RestoreProductInventory::class)->handle(new OrderCancelled($order->refresh()));
        $this->assertEquals(50, $this->product->refresh()->stock_quantity);
        $this->assertNotNull($order->refresh()->inventory_restored_at);
    }

    public function test_p71_multiple_fulfillments_all_cancelled(): void
    {
        $order = $this->makeOrder();
        $this->reserve($order);
        // Explicit split keys: two fulfillments for one order.
        $f1 = app(FulfillmentService::class)->releaseForOrder($order->refresh(), $this->warehouse->id, 'p71-m1');
        $f2 = app(FulfillmentService::class)->releaseForOrder($order->refresh(), $this->warehouse->id, 'p71-m2');

        $this->cancelOrder($order->refresh());

        $this->assertEquals('cancelled', $f1->refresh()->status);
        $this->assertEquals('cancelled', $f2->refresh()->status);
        $this->assertEquals('order_cancel', $f2->refresh()->cancel_source);
    }

    public function test_p71_shipped_fulfillment_is_not_force_cancelled(): void
    {
        $order = $this->makeOrder();
        $this->reserve($order);
        $fulfillment = $this->toReadyToShip($this->release('p71-4', $order));
        app(FulfillmentTransition::class)->transition($fulfillment, 'shipped');

        // D7-1: the order cancel succeeds (order-flow rules govern), the
        // shipped fulfillment is left open and surfaced — never forced.
        $this->cancelOrder($order->refresh());

        $this->assertEquals('cancelled', $order->refresh()->status);
        $this->assertEquals('shipped', $fulfillment->refresh()->status);
    }

    public function test_p71_ready_to_ship_without_shipment_cancels(): void
    {
        $order = $this->makeOrder();
        $this->reserve($order);
        $fulfillment = $this->toReadyToShip($this->release('p71-5', $order));

        $this->cancelOrder($order->refresh());

        $this->assertEquals('cancelled', $fulfillment->refresh()->status);
    }

    public function test_p71_reaper_cancel_cascades_to_fulfillment(): void
    {
        $order = $this->makeOrder(['payment_method' => 'cod']);
        $this->reserve($order);
        $order->forceFill(['reservation_expires_at' => now()->subHour()])->save();
        $fulfillment = $this->release('p71-6', $order->refresh());

        $this->artisan('orders:cancel-unpaid')->assertExitCode(0);

        $this->assertEquals('cancelled', $order->refresh()->status);
        $this->assertEquals('cancelled', $fulfillment->refresh()->status);
        $this->assertEquals('order_cancel', $fulfillment->refresh()->cancel_source);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->refresh()->inventory_state);
    }

    public function test_p71_cod_unpaid_cancel_cascades_and_releases(): void
    {
        $order = $this->makeOrder(['payment_method' => 'cod']);
        $this->reserve($order);
        $this->assertEquals(10, $this->product->refresh()->reserved_quantity);
        $fulfillment = $this->release('p71-7', $order->refresh());

        $this->cancelOrder($order->refresh());

        $this->assertEquals('cancelled', $fulfillment->refresh()->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->refresh()->inventory_state);
        $this->assertEquals(0, $this->product->refresh()->reserved_quantity);
        $this->assertEquals(50, $this->product->refresh()->stock_quantity);
    }

    // ---------------- P7-2: exactly-once restore ----------------

    public function test_p72_listener_retry_is_safe(): void
    {
        $order = $this->makeOrder([
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'paid_at' => now(),
        ]);
        $this->reserve($order);
        app(OrderReservationService::class)->commit($order->refresh());

        $listener = app(RestoreProductInventory::class);
        $listener->handle(new OrderCancelled($order->refresh()));
        $this->assertEquals(50, $this->product->refresh()->stock_quantity);

        $first = $order->refresh()->inventory_restored_at;
        $this->assertNotNull($first);

        $listener->handle(new OrderCancelled($order->refresh()));
        $this->assertEquals(50, $this->product->refresh()->stock_quantity);
        $this->assertEquals(
            $first instanceof \Carbon\Carbon ? $first->format('Y-m-d H:i:s') : $first,
            $order->refresh()->inventory_restored_at instanceof \Carbon\Carbon
                ? $order->refresh()->inventory_restored_at->format('Y-m-d H:i:s')
                : $order->refresh()->inventory_restored_at
        );
    }

    public function test_p72_restore_service_is_idempotent(): void
    {
        $order = $this->makeOrder([
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'paid_at' => now(),
        ]);
        $this->reserve($order);
        app(OrderReservationService::class)->commit($order->refresh());

        $service = app(InventoryRestoreService::class);
        $this->assertTrue($service->restore($order->refresh()));
        $this->assertFalse($service->restore($order->refresh()));
        $this->assertEquals(50, $this->product->refresh()->stock_quantity);
        // P7-2 enum support: RESTORED is a real persisted state.
        $this->assertEquals(Order::INVENTORY_STATE_RESTORED, $order->refresh()->inventory_state);
    }

    // ---------------- P7-3: picking claim clearing ----------------

    public function test_p73_cancel_clears_picking_claims_and_keeps_picked_qty(): void
    {
        $order = $this->makeOrder();
        $this->reserve($order);
        $fulfillment = $this->release('p73-1', $order);
        [$task] = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment->refresh());
        app(PickingExecutionService::class)->claim($task, $this->picker->id);
        app(PickingExecutionService::class)->confirm($task->refresh(), [
            'location' => 'LOC-A-71', 'product' => 'SKU-P7-1', 'quantity' => 2, 'op_seq' => 1,
        ], $this->picker->id);

        app(FulfillmentService::class)->cancelFulfillment($fulfillment->refresh(), 'no longer needed');

        $task = $task->refresh();
        $this->assertEquals('skipped', $task->status);
        $this->assertNull($task->claimed_by);
        $this->assertNull($task->claimed_at);
        $this->assertNull($task->claim_expires_at);
        // Physical truth survives: picked quantities stay on the item.
        $this->assertEquals(2, $fulfillment->items()->firstOrFail()->refresh()->quantity_picked);
    }

    // ---------------- P7-4: live-shipment boundary ----------------

    public function test_p74_live_shipment_blocks_fulfillment_cancel(): void
    {
        $order = $this->makeOrder();
        $this->reserve($order);
        $fulfillment = $this->toReadyToShip($this->release('p74-1', $order));
        app(ShipmentService::class)->createForFulfillment($fulfillment->refresh(), [], 'p74-ship');

        try {
            app(FulfillmentService::class)->cancelFulfillment($fulfillment->refresh(), 'changed mind');
            $this->fail('live shipment must refuse fulfillment cancellation');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('live shipment', $e->getMessage());
        }

        // Refusal happens BEFORE the transition: nothing mutated.
        $this->assertEquals('ready_to_ship', $fulfillment->refresh()->status);
        $this->assertEquals('label_created', Shipment::where('fulfillment_id', $fulfillment->id)->firstOrFail()->status);
    }

    public function test_p74_cancelled_shipment_does_not_block(): void
    {
        $order = $this->makeOrder();
        $this->reserve($order);
        $fulfillment = $this->toReadyToShip($this->release('p74-2', $order));
        $shipment = app(ShipmentService::class)->createForFulfillment($fulfillment->refresh(), [], 'p74-ship2');
        // Fixture arrangement (not a production path): the shipment died in Phase 8 terms.
        $shipment->update(['status' => 'cancelled']);

        $cancelled = app(FulfillmentService::class)->cancelFulfillment($fulfillment->refresh(), 'changed mind');

        $this->assertEquals('cancelled', $cancelled->status);
    }

    public function test_p74_order_cancel_is_atomic_when_shipment_blocks(): void
    {
        $order = $this->makeOrder();
        $this->reserve($order);
        $fulfillment = $this->toReadyToShip($this->release('p74-3', $order));
        app(ShipmentService::class)->createForFulfillment($fulfillment->refresh(), [], 'p74-ship3');

        try {
            app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);
            $this->fail('guard refusal must roll the order cancel back atomically');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('live shipment', $e->getMessage());
        }

        $this->assertEquals('pending', $order->refresh()->status);
        $this->assertEquals('ready_to_ship', $fulfillment->refresh()->status);
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->refresh()->inventory_state);
    }

    // ---------------- P7-5: batch reason ----------------

    public function test_p75_cancel_batch_requires_reason(): void
    {
        $f1 = $this->release('p75-1');
        $batch = app(BatchPickingService::class)->createBatchFromFulfillments(collect([$f1->refresh()]));

        foreach (['', '   '] as $bad) {
            try {
                app(BatchPickingService::class)->cancelBatch($batch->refresh(), $bad);
                $this->fail('blank batch reason must be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('reason', strtolower($e->getMessage()));
            }
        }
        $this->assertNotEquals('cancelled', $batch->refresh()->status);

        app(BatchPickingService::class)->cancelBatch($batch->refresh(), 'shift end');
        $this->assertEquals('cancelled', $batch->refresh()->status);
    }

    // ---------------- P7-6: packed / sealed alignment ----------------

    public function test_p76_packed_task_blocks_fulfillment_cancel(): void
    {
        $order = $this->makeOrder([
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
        ]);
        $this->reserve($order);
        app(OrderReservationService::class)->commit($order->refresh());
        $fulfillment = $this->release('p76-1', $order->refresh());
        $item = $fulfillment->items()->firstOrFail();
        $item->update(['quantity_picked' => 10, 'status' => 'picked']);
        $t = app(FulfillmentTransition::class);
        $t->transition($fulfillment, 'picking');
        $t->transition($fulfillment, 'picked');
        $t->transition($fulfillment, 'packing');
        $task = app(PackingService::class)->createPackingTaskFromFulfillment($fulfillment->refresh());
        $station = PackingStation::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'ST-7',
            'name' => 'Station 7', 'status' => 'active',
        ]);
        $operator = \App\Models\User::create([
            'name' => 'Packer7', 'email' => 'packer7@example.com',
            'password' => bcrypt('password'), 'type' => 'user',
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $task = app(PackingService::class)->assignToStation($task, $station->id, $operator->id);
        $task = app(PackingService::class)->startPacking($task);
        app(PackingService::class)->completePacking($task->refresh(), 2.5, ['length' => 30, 'width' => 20, 'height' => 10]);
        $this->assertEquals('packed', $task->refresh()->status);

        try {
            app(FulfillmentService::class)->cancelFulfillment($fulfillment->refresh(), 'changed mind');
            $this->fail('packed task must refuse fulfillment cancellation (cancelTask parity)');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('packed', $e->getMessage());
        }

        $this->assertEquals('packing', $fulfillment->refresh()->status);
    }

    public function test_p76_sealed_package_blocks_fulfillment_cancel(): void
    {
        $order = $this->makeOrder();
        $this->reserve($order);
        $fulfillment = $this->release('p76-2', $order);
        $item = $fulfillment->items()->firstOrFail();
        $item->update(['quantity_picked' => 10, 'status' => 'picked']);
        $t = app(FulfillmentTransition::class);
        $t->transition($fulfillment, 'picking');
        $t->transition($fulfillment, 'picked');
        $t->transition($fulfillment, 'packing');
        $package = app(PackingService::class)->createPackage($fulfillment->refresh());
        app(PackingService::class)->addItemToPackage($package, $item->id, 10);
        app(PackingService::class)->sealPackage($package->refresh());
        $this->assertEquals(Package::STATUS_SEALED, $package->refresh()->status);

        try {
            app(FulfillmentService::class)->cancelFulfillment($fulfillment->refresh(), 'changed mind');
            $this->fail('sealed custody must refuse fulfillment cancellation');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sealed', $e->getMessage());
        }

        $this->assertEquals('packing', $fulfillment->refresh()->status);
        $this->assertEquals(Package::STATUS_SEALED, $package->refresh()->status);
    }

    public function test_p76_open_package_voided_on_cancel(): void
    {
        $order = $this->makeOrder();
        $this->reserve($order);
        $fulfillment = $this->release('p76-3', $order);
        $item = $fulfillment->items()->firstOrFail();
        $item->update(['quantity_picked' => 10, 'status' => 'picked']);
        $t = app(FulfillmentTransition::class);
        $t->transition($fulfillment, 'picking');
        $t->transition($fulfillment, 'picked');
        $t->transition($fulfillment, 'packing');
        $package = app(PackingService::class)->createPackage($fulfillment->refresh());
        app(PackingService::class)->addItemToPackage($package, $item->id, 10);

        app(FulfillmentService::class)->cancelFulfillment($fulfillment->refresh(), 'changed mind');

        // Row retained for audit, voided — never silently destroyed.
        $this->assertEquals(Package::STATUS_VOIDED, $package->refresh()->status);
        $this->assertEquals('cancelled', $fulfillment->refresh()->status);
    }

    // ---------------- P7-7: refund restoration ----------------

    public function test_p77_cancel_then_refund_approval_restores_once(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'O77', 'user_email' => $user->email,
            'status' => 'pending', 'paid_at' => now(),
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'price' => 1000, 'total_price' => 1000,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P7',
            'product_sku' => 'SKU-P7-1', 'product_quantity' => 3,
            'product_price' => 100, 'product_total_price' => 300,
        ]);
        app(OrderReservationService::class)->reserveForOrder($order->refresh());
        app(OrderReservationService::class)->commit($order->refresh());
        // Order holds 3 units: 50 − 3 committed.
        $this->assertEquals(47, $this->product->refresh()->stock_quantity);

        // Cancel first (sync restore claims exactly once)…
        app(InventoryRestoreService::class)->restore($order->refresh());
        $this->assertEquals(50, $this->product->refresh()->stock_quantity);

        // …then a refund approval must be a safe no-op (shared claim).
        // The App listener is invoked directly: a global event() dispatch
        // also fires the legacy Marvel RefundApproved listeners, whose
        // reviews-schema mismatch is pre-existing out-of-scope debt.
        $refund = Refund::withoutEvents(fn () => Refund::create([
            'order_id' => $order->id, 'user_id' => $user->id,
            'amount' => 300.00, 'title' => 'Approval after cancel',
            'status' => 'APPROVED',
        ]));
        app(\App\Listeners\RestoreInventoryOnRefund::class)->handle(new \App\Events\RefundApproved($refund));

        $this->assertEquals(50, $this->product->refresh()->stock_quantity);
        $this->assertEquals(Order::INVENTORY_STATE_RESTORED, $order->refresh()->inventory_state);

        // Reverse order (refund-then-cancel) is equally safe: with the
        // claim already won, the cancel path finds no COMMITTED inventory
        // (no restore) and no ACTIVE reservation (no release) — still once.
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');
        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);

        $this->assertEquals('cancelled', $order->refresh()->status);
        $this->assertEquals(50, $this->product->refresh()->stock_quantity);
        $this->assertEquals(Order::INVENTORY_STATE_RESTORED, $order->refresh()->inventory_state);
    }

    // ---------------- P7-8: actor / source audit ----------------

    public function test_p78_direct_cancel_records_source_and_null_actor(): void
    {
        $fulfillment = $this->release('p78-1');

        $cancelled = app(FulfillmentService::class)->cancelFulfillment($fulfillment, 'ops decision');

        $this->assertEquals('direct', $cancelled->cancel_source);
        $this->assertNull($cancelled->cancelled_by);
    }

    public function test_p78_context_actor_and_source_recorded(): void
    {
        $fulfillment = $this->release('p78-2');

        $cancelled = app(FulfillmentService::class)->cancelFulfillment($fulfillment, 'supervisor call', [
            'cancel_source' => 'support_ticket',
            'cancelled_by' => $this->picker->id,
        ]);

        $this->assertEquals('support_ticket', $cancelled->cancel_source);
        $this->assertEquals($this->picker->id, $cancelled->cancelled_by);
        $this->assertEquals($this->picker->id, $cancelled->cancelledBy->id);
    }

    // ---------------- Idempotency / staleness ----------------

    public function test_double_fulfillment_cancel_refuses_safely_without_duplication(): void
    {
        $order = $this->makeOrder([
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'paid_at' => now(),
        ]);
        $this->reserve($order);
        app(OrderReservationService::class)->commit($order->refresh());
        $fulfillment = $this->release('p78-3', $order->refresh());
        $package = app(PackingService::class)->createPackage(
            $this->toPicked($fulfillment)->refresh()
        );

        app(FulfillmentService::class)->cancelFulfillment($fulfillment->refresh(), 'first');
        // Fulfillment cancel never touches inventory (order-cancel owns it):
        // committed stock stays deducted at 40 through both attempts.
        $this->assertEquals(40, $this->product->refresh()->stock_quantity);

        // Second cancel: either a loud refusal (terminal children such as a
        // cancelled parent batch) or a silent idempotent no-op
        // (same-state transition + status-gated children). Both are safe —
        // what must never happen is duplicated side effects.
        try {
            app(FulfillmentService::class)->cancelFulfillment($fulfillment->refresh(), 'second');
        } catch (\Throwable $e) {
            $this->assertTrue(true, 'loud refusal is a safe outcome');
        }

        $this->assertEquals('cancelled', $fulfillment->refresh()->status);
        $this->assertEquals(40, $this->product->refresh()->stock_quantity);
        $this->assertEquals(Package::STATUS_VOIDED, $package->refresh()->status);
    }

    public function test_double_order_cancel_is_safe(): void
    {
        $order = $this->makeOrder();
        $this->reserve($order);
        $fulfillment = $this->release('p78-4', $order);

        $this->cancelOrder($order->refresh());
        // Second cancel: previousStatus === cancelled → cancel branch skipped.
        $again = app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);
        $this->assertNotFalse($again);

        $this->assertEquals('cancelled', $order->refresh()->status);
        $this->assertEquals('cancelled', $fulfillment->refresh()->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->refresh()->inventory_state);
        $this->assertEquals(0, $this->product->refresh()->reserved_quantity);
    }

    public function test_stale_fulfillment_model_cancels_on_locked_state(): void
    {
        $fulfillment = $this->release('p78-5');
        app(FulfillmentTransition::class)->transition($fulfillment, 'picking');

        // Caller holds a stale `pending` model; the decision must come from
        // the locked fresh row. Single-process lock reasoning, not
        // multi-process proof.
        $stale = Fulfillment::find($fulfillment->id);
        $stale->status = 'pending';

        $cancelled = app(FulfillmentService::class)->cancelFulfillment($stale, 'stale caller');
        $this->assertEquals('cancelled', $cancelled->status);
    }

    private function toPicked(Fulfillment $fulfillment): Fulfillment
    {
        $item = $fulfillment->items()->firstOrFail();
        $item->update(['quantity_picked' => 10, 'status' => 'picked']);
        $t = app(FulfillmentTransition::class);
        $t->transition($fulfillment, 'picking');
        $t->transition($fulfillment, 'picked');

        return $fulfillment->refresh();
    }
}
