<?php

namespace Tests\Feature\Wms;

use App\Events\Fulfillment\FulfillmentProgressed;
use App\Events\PaymentSucceeded;
use App\Listeners\SyncOrderWithFulfillmentProgress;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Fulfillment\BatchPickingService;
use App\Services\Fulfillment\FulfillmentOrderSyncService;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\OrderPickingService;
use App\Services\Fulfillment\PackingService;
use App\Services\Fulfillment\PickingExecutionService;
use App\Services\General\OrderService;
use App\Services\Inventory\StockOperationService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fulfillment → Order Flow synchronization + F-03 pre-task transfer guard.
 *
 * DANGER: uses RefreshDatabase. Run ONLY against an isolated test database
 * (DB_DATABASE=catch_wms_verify). NEVER against the shared dev `catch`.
 *
 * The sync itself is invoked directly (after-commit queue events never fire
 * inside the test transaction); producer dispatch wiring is verified with
 * Event::fake separately. QUEUE_CONNECTION=sync in phpunit.
 */
class WmsFulfillmentOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    private Location $binA;

    private Location $binB;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        foreach (['update-order-status', 'payments.mark_paid'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->product = Product::create([
            'name' => 'SYNC', 'slug' => 'sync-' . uniqid(), 'sku' => 'SKU-SYNC-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 100, 'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);
        $this->warehouse = Warehouse::create([
            'code' => 'WH-SYNC', 'name' => 'Sync', 'status' => 'active', 'is_default' => true,
        ]);
        $this->binA = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'SYNC-A', 'barcode' => 'SYNC-LOC-A',
            'name' => 'Bin A', 'type' => 'picking', 'status' => 'active', 'priority' => 10,
        ]);
        $this->binB = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'SYNC-B', 'barcode' => 'SYNC-LOC-B',
            'name' => 'Bin B', 'type' => 'picking', 'status' => 'active', 'priority' => 1,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->binA->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $this->binB->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);

        $this->customer = User::factory()->create(['type' => 'customer']);
    }

    private function sync(): FulfillmentOrderSyncService
    {
        return app(FulfillmentOrderSyncService::class);
    }

    /**
     * Unpaid COD order: inventory committed at creation (deferred rule),
     * payment pending. Mirrors the manual-payment placement path.
     */
    private function codOrder(): Order
    {
        $order = Order::create([
            'user_id' => $this->customer->id, 'name' => 'COD', 'user_email' => $this->customer->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'payment_method' => 'cod',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'SYNC',
            'product_sku' => 'SKU-SYNC-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        return $order->refresh();
    }

    private function paidOrder(): Order
    {
        $order = $this->codOrder();
        $order->update([
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
        ]);
        app(OrderService::class)->changeOrderStatus(null, 'completed', $order->id);

        return $order->fresh();
    }

    private function release(Order $order, ?string $key = null): Fulfillment
    {
        return app(FulfillmentService::class)->releaseForOrder($order->refresh(), null, $key);
    }

    private function pickAll(Fulfillment $fulfillment): void
    {
        $picker = User::factory()->create(['type' => 'staff']);
        $tasks = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment);
        $this->assertNotEmpty($tasks);
        $seq = 1;
        foreach ($tasks as $task) {
            app(PickingExecutionService::class)->confirm($task, [
                'location' => 'SYNC-LOC-A', 'product' => 'SKU-SYNC-1',
                'quantity' => (float) $task->quantity_to_pick, 'op_seq' => $seq++,
            ], (int) $picker->id, true);
        }
        app(OrderPickingService::class)->completePicking($fulfillment);
    }

    private function packAll(Fulfillment $fulfillment): void
    {
        $station = PackingStation::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'SYNC-ST-' . uniqid(),
            'name' => 'Station', 'status' => 'active',
        ]);
        $operator = User::factory()->create(['type' => 'staff']);
        $packing = app(PackingService::class);
        $task = $packing->createPackingTaskFromFulfillment($fulfillment);
        $packing->assignToStation($task, $station->id, (int) $operator->id);
        $packing->startPacking($task);
        $package = $packing->createPackage($fulfillment);
        foreach ($fulfillment->items()->get() as $item) {
            $packing->addItemToPackage($package, (int) $item->id, (float) $item->quantity_picked);
        }
        $packing->sealPackage($package);
        $packing->completePacking($task, 2.5, ['length' => 30, 'width' => 20, 'height' => 10]);
        $packing->verifyPacking($task);
    }

    // ---------- A. unpaid COD walks logistics via fulfillment ----------

    public function test_cod_order_advances_through_fulfillment_milestones(): void
    {
        // Fake the progress fan-out: producers still record milestones (see
        // test H), but convergence is driven manually below step by step.
        Event::fake([FulfillmentProgressed::class]);

        $order = $this->codOrder();

        $fulfillment = $this->release($order);
        $r = $this->sync()->sync($order->id, 'released');
        $this->assertSame(['processing'], $r['advanced']);
        $this->assertSame('processing', $order->fresh()->status);

        $this->pickAll($fulfillment);
        $this->assertSame('picked', $fulfillment->fresh()->status);
        $r = $this->sync()->sync($order->id, 'picked');
        $this->assertSame(['packed'], $r['advanced']);
        $this->assertSame('packed', $order->fresh()->status);

        $this->packAll($fulfillment);
        $this->assertSame('ready_to_ship', $fulfillment->fresh()->status);
        $r = $this->sync()->sync($order->id, 'ready_to_ship');
        $this->assertSame([], $r['advanced']);
        $this->assertSame('packed', $order->fresh()->status);

        $shipments = app(ShipmentService::class);
        $shipment = $shipments->createForFulfillment($fulfillment, ['courier' => 'SyncPost']);
        $shipments->dispatch($shipment->id);
        $this->assertSame('shipped', $fulfillment->fresh()->status);
        $r = $this->sync()->sync($order->id, 'shipped');
        $this->assertSame(['shipped'], $r['advanced']);
        $this->assertSame('shipped', $order->fresh()->status);

        $shipments->updateStatus($shipment->id, 'in_transit');
        $r = $this->sync()->sync($order->id, 'shipment_in_transit');
        $this->assertSame('shipped', $order->fresh()->status);

        $shipments->updateStatus($shipment->id, 'out_for_delivery');
        $r = $this->sync()->sync($order->id, 'shipment_out_for_delivery');
        $this->assertSame(['out_for_delivery'], $r['advanced']);
        $this->assertSame('out_for_delivery', $order->fresh()->status);

        // Payment still pending: no bypass of the payment milestone.
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->fresh()->payment_status);
    }

    // ---------- B. paid order skips intermediates, still delivers ----------

    public function test_paid_order_skips_intermediates_and_delivers(): void
    {
        Event::fake([FulfillmentProgressed::class]);

        $order = $this->paidOrder();
        $this->assertSame('completed', $order->fresh()->status);

        $fulfillment = $this->release($order);
        $r = $this->sync()->sync($order->id, 'released');
        // Nothing legal from completed, but the blockage is reported visibly.
        $this->assertSame([], $r['advanced']);
        $this->assertSame('completed->processing', $r['skipped']);
        $this->assertSame('completed', $order->fresh()->status);

        $this->pickAll($fulfillment);
        $r = $this->sync()->sync($order->id, 'picked');
        $this->assertSame([], $r['advanced']);
        $this->assertSame('completed', $order->fresh()->status);

        $this->packAll($fulfillment);
        $shipments = app(ShipmentService::class);
        $shipment = $shipments->createForFulfillment($fulfillment, ['courier' => 'SyncPost']);
        $shipments->dispatch($shipment->id);
        $r = $this->sync()->sync($order->id, 'shipped');
        $this->assertSame('completed', $order->fresh()->status);

        $shipments->markDelivered($shipment->id);
        $this->assertSame('delivered', $fulfillment->fresh()->status);
        // maybeCompleteOrder runs synchronously inside markDelivered.
        $this->assertSame('delivered', $order->fresh()->status);
    }

    // ---------- C. partial multi-fulfillment never advances the order ----------

    public function test_partial_multi_fulfillment_does_not_pack_order(): void
    {
        Event::fake([FulfillmentProgressed::class]);

        $order = $this->codOrder();

        $first = $this->release($order, 'split-a');
        $second = $this->release($order, 'split-b');
        $this->assertNotSame($first->id, $second->id);
        $this->sync()->sync($order->id, 'released');
        $this->assertSame('processing', $order->fresh()->status);

        $this->pickAll($first);
        $r = $this->sync()->sync($order->id, 'picked');
        $this->assertSame([], $r['advanced']);
        $this->assertSame('processing', $order->fresh()->status);

        $this->pickAll($second);
        $r = $this->sync()->sync($order->id, 'picked');
        $this->assertSame(['packed'], $r['advanced']);
        $this->assertSame('packed', $order->fresh()->status);
    }

    // ---------- D. repeats converge without duplicate history ----------

    public function test_repeated_sync_is_idempotent(): void
    {
        Event::fake([FulfillmentProgressed::class]);

        $order = $this->codOrder();
        $fulfillment = $this->release($order);
        $this->pickAll($fulfillment);

        $first = $this->sync()->sync($order->id, 'picked');
        // Convergent walk: pending → processing → packed in one run.
        $this->assertSame(['processing', 'packed'], $first['advanced']);
        $history = $order->statusHistory()->count();

        $second = $this->sync()->sync($order->id, 'picked');
        $this->assertSame([], $second['advanced']);
        $this->assertSame($history, $order->statusHistory()->count());
        $this->assertSame('packed', $order->fresh()->status);
    }

    // ---------- E. illegal transitions skip loudly, never throw ----------

    public function test_illegal_transition_skips_without_exception(): void
    {
        Event::fake([FulfillmentProgressed::class]);

        $order = $this->paidOrder();
        $fulfillment = $this->release($order);
        $this->pickAll($fulfillment);
        $this->assertSame('picked', $fulfillment->fresh()->status);

        // Aggregate justifies packed, but completed → packed is illegal: a
        // visible skip, never forced, order untouched.
        $r = $this->sync()->sync($order->id, 'picked');
        $this->assertSame([], $r['advanced']);
        $this->assertSame('completed->packed', $r['skipped']);
        $this->assertSame('completed', $order->fresh()->status);
    }

    // ---------- F. COD paid after delivery recovers via payment event ----------

    public function test_cod_paid_after_delivery_completes_via_payment_listener(): void
    {
        // Fake both fan-outs: the test drives convergence manually to prove
        // each link (stuck-without-sync, then listener recovery).
        Event::fake([FulfillmentProgressed::class, PaymentSucceeded::class]);

        $order = $this->codOrder();
        $fulfillment = $this->release($order);
        $this->pickAll($fulfillment);
        $this->packAll($fulfillment);
        $shipments = app(ShipmentService::class);
        $shipment = $shipments->createForFulfillment($fulfillment, ['courier' => 'SyncPost']);
        $shipments->dispatch($shipment->id);
        $shipments->markDelivered($shipment->id);
        $this->assertSame('delivered', $fulfillment->fresh()->status);

        // Unpaid: the order converges only to out_for_delivery, never delivered.
        $this->sync()->sync($order->id, 'delivered');
        $this->assertSame('out_for_delivery', $order->fresh()->status);

        Transaction::create([
            'order_id' => $order->id, 'user_id' => $this->customer->id,
            'payment_method' => 'cod', 'status' => 'pending',
            'amount' => 200, 'currency' => 'EGP',
        ]);
        app(OrderService::class)->markCodAsPaid($order->refresh(), 'paid at door');
        $this->assertSame('completed', $order->fresh()->status);

        // The payment fan-out converges the now-eligible delivered step.
        app(SyncOrderWithFulfillmentProgress::class)->handle(new PaymentSucceeded($order->fresh()));
        $this->assertSame('delivered', $order->fresh()->status);
    }

    // ---------- G. F-03 pre-task commitment guard ----------

    public function test_transfer_from_item_pinned_placement_is_refused(): void
    {
        $order = $this->codOrder();
        $this->release($order);
        $stockBefore = (float) $this->product->fresh()->stock_quantity;

        // No picking task exists yet, but the fulfillment item already pins bin A.
        $this->assertSame(0, \App\Models\Fulfillment\PickingTask::count());

        try {
            app(StockOperationService::class)->transferStock(
                $this->product->id, $this->binA->id, $this->binB->id, 10,
            );
            $this->fail('Transfer from a commitment-pinned placement must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('commitment', $e->getMessage());
        }

        $this->assertSame($stockBefore, (float) $this->product->fresh()->stock_quantity);
    }

    public function test_transfer_allowed_after_picking_completes_and_preserves_stock(): void
    {
        $order = $this->codOrder();
        $fulfillment = $this->release($order);
        $this->pickAll($fulfillment);
        $stockBefore = (float) $this->product->fresh()->stock_quantity;

        // Goods are in hand: the bin hint is free again.
        $result = app(StockOperationService::class)->transferStock(
            $this->product->id, $this->binA->id, $this->binB->id, 10,
            null, 'sync-test-transfer-1',
        );

        $this->assertFalse($result['replayed']);
        $this->assertSame(10, $result['result']['transferred_quantity']);
        $this->assertSame($stockBefore, (float) $this->product->fresh()->stock_quantity);

        // Idempotent replay converges without moving hints again.
        $replay = app(StockOperationService::class)->transferStock(
            $this->product->id, $this->binA->id, $this->binB->id, 10,
            null, 'sync-test-transfer-1',
        );
        $this->assertTrue($replay['replayed']);
        $this->assertSame($stockBefore, (float) $this->product->fresh()->stock_quantity);
    }

    // ---------- I. digital-only orders never enter the sync ----------

    public function test_digital_only_order_sync_is_noop(): void
    {
        Event::fake([FulfillmentProgressed::class, PaymentSucceeded::class]);

        $digital = Product::create([
            'name' => 'DIG', 'slug' => 'dig-' . uniqid(), 'sku' => 'SKU-DIG-1',
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 0, 'reserved_quantity' => 0,
            'sold_quantity' => 0, 'item_type' => 'DIGITAL',
        ]);
        $order = Order::create([
            'user_id' => $this->customer->id, 'name' => 'DIG', 'user_email' => $this->customer->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 50, 'total_price' => 50,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $digital->id, 'product_name' => 'DIG',
            'product_sku' => 'SKU-DIG-1', 'product_quantity' => 1,
            'product_price' => 50, 'product_total_price' => 50,
        ]);
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        // Physical release refuses digital-only orders (entitlements own them).
        try {
            $this->release($order->refresh());
            $this->fail('Digital-only release must be refused.');
        } catch (\RuntimeException) {
        }

        // And the sync converges to nothing without fulfillments.
        $r = $this->sync()->sync($order->id, 'released');
        $this->assertSame([], $r['advanced']);
        $this->assertNull($r['skipped']);
        $this->assertSame('pending', $order->fresh()->status);
    }

    // ---------- J. cancelled/delivered orders are terminal no-ops ----------

    public function test_sync_on_cancelled_order_is_noop_and_never_resurrects(): void
    {
        Event::fake([FulfillmentProgressed::class, PaymentSucceeded::class]);

        $order = $this->codOrder();
        $this->release($order);
        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id, true, 'customer changed mind');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', Fulfillment::where('order_id', $order->id)->firstOrFail()->status);
        $history = $order->statusHistory()->count();

        $r = $this->sync()->sync($order->id, 'picked');
        $this->assertSame([], $r['advanced']);
        $this->assertNull($r['skipped']);
        $this->assertFalse($r['delivered']);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame($history, $order->statusHistory()->count());
    }

    // ---------- K. duplicate events converge to a single transition ----------

    public function test_duplicate_progress_events_converge_without_duplicate_history(): void
    {
        Event::fake([FulfillmentProgressed::class, PaymentSucceeded::class]);

        $order = $this->codOrder();
        $this->release($order);
        $listener = app(SyncOrderWithFulfillmentProgress::class);

        $listener->handle(new FulfillmentProgressed($order->id, 'released'));
        $this->assertSame('processing', $order->fresh()->status);
        $history = $order->statusHistory()->count();

        // Duplicate delivery of the same milestone: harmless convergence.
        $listener->handle(new FulfillmentProgressed($order->id, 'released'));
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame($history, $order->statusHistory()->count());
    }

    // ---------- L. transfer guard allows dead commitments ----------

    public function test_transfer_allowed_after_fulfillment_cancelled(): void
    {
        Event::fake([FulfillmentProgressed::class, PaymentSucceeded::class]);

        $order = $this->codOrder();
        $this->release($order);
        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id, true, 'customer changed mind');
        $stockBefore = (float) $this->product->fresh()->stock_quantity;

        // Cancelled commitments pin nothing: the hint is free again.
        $result = app(StockOperationService::class)->transferStock(
            $this->product->id, $this->binA->id, $this->binB->id, 10,
            null, 'sync-test-transfer-cancelled',
        );
        $this->assertFalse($result['replayed']);
        $this->assertSame($stockBefore, (float) $this->product->fresh()->stock_quantity);
    }

    public function test_transfer_allowed_when_only_null_placement_items_exist(): void
    {
        Event::fake([FulfillmentProgressed::class, PaymentSucceeded::class]);

        // A product with no placement anywhere: its fulfillment item pins
        // NULL and must never block another product's hint movement (the
        // guard matches concrete placement ids only).
        $ghost = Product::create([
            'name' => 'GHOST', 'slug' => 'ghost-' . uniqid(), 'sku' => 'SKU-GHOST-1',
            'price' => 10, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 5, 'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);
        $bystander = Product::create([
            'name' => 'BYSTAND', 'slug' => 'bystand-' . uniqid(), 'sku' => 'SKU-BYSTAND-1',
            'price' => 10, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $bystander->id, 'location_id' => $this->binA->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
        $order = $this->codOrder();
        $order->orderItems()->create([
            'product_id' => $ghost->id, 'product_name' => 'GHOST',
            'product_sku' => 'SKU-GHOST-1', 'product_quantity' => 1,
            'product_price' => 10, 'product_total_price' => 10,
        ]);
        $fulfillment = $this->release($order->refresh());
        $this->assertTrue(
            $fulfillment->items()->whereNull('product_location_id')->exists(),
            'Expected a NULL-placement item for the unplaced product.'
        );
        $stockBefore = (float) $bystander->fresh()->stock_quantity;

        // The bystander's placement is pinned by nothing: transfer succeeds
        // even though the same fulfillment carries a NULL-pinned item.
        $result = app(StockOperationService::class)->transferStock(
            $bystander->id, $this->binA->id, $this->binB->id, 10,
            null, 'sync-test-transfer-null-pin',
        );
        $this->assertFalse($result['replayed']);
        $this->assertSame($stockBefore, (float) $bystander->fresh()->stock_quantity);
    }

    // ---------- M. batch milestones dispatch the convergence event ----------

    public function test_batch_milestones_dispatch_progress_event(): void
    {
        Event::fake([FulfillmentProgressed::class]);

        $order = $this->codOrder();
        $fulfillment = $this->release($order);
        $batches = app(BatchPickingService::class);
        $picker = User::factory()->create(['type' => 'staff']);

        $batch = $batches->createBatchFromFulfillments(collect([$fulfillment]));
        Event::assertDispatched(
            FulfillmentProgressed::class,
            fn ($e) => $e->orderId === $order->id && $e->milestone === 'picking_started'
        );

        $batches->assignBatch($batch, (int) $picker->id);
        $batches->startPicking($batch);
        $seq = 1;
        foreach (\App\Models\Fulfillment\PickingTask::where('batch_id', $batch->id)->orderBy('id')->get() as $task) {
            app(PickingExecutionService::class)->confirm($task, [
                'location' => 'SYNC-LOC-A', 'product' => 'SKU-SYNC-1',
                'quantity' => (float) $task->quantity_to_pick, 'op_seq' => $seq++,
            ], (int) $picker->id, true);
        }
        $batches->completeBatch($batch);
        $this->assertSame('picked', $fulfillment->fresh()->status);
        Event::assertDispatched(
            FulfillmentProgressed::class,
            fn ($e) => $e->orderId === $order->id && $e->milestone === 'picked'
        );
    }

    // ---------- H. producers dispatch the convergence event ----------

    public function test_milestone_producers_dispatch_progress_event(): void
    {
        Event::fake([FulfillmentProgressed::class]);

        $order = $this->codOrder();
        $fulfillment = $this->release($order);
        Event::assertDispatched(FulfillmentProgressed::class, fn ($e) => $e->orderId === $order->id && $e->milestone === 'released');

        $this->pickAll($fulfillment);
        Event::assertDispatched(FulfillmentProgressed::class, fn ($e) => $e->orderId === $order->id && $e->milestone === 'picked');

        $this->packAll($fulfillment);
        Event::assertDispatched(FulfillmentProgressed::class, fn ($e) => $e->orderId === $order->id && $e->milestone === 'ready_to_ship');

        $shipments = app(ShipmentService::class);
        $shipment = $shipments->createForFulfillment($fulfillment, ['courier' => 'SyncPost']);
        $shipments->dispatch($shipment->id);
        Event::assertDispatched(FulfillmentProgressed::class, fn ($e) => $e->orderId === $order->id && $e->milestone === 'shipped');

        $shipments->markDelivered($shipment->id);
        Event::assertDispatched(FulfillmentProgressed::class, fn ($e) => $e->orderId === $order->id && $e->milestone === 'delivered');
    }
}
