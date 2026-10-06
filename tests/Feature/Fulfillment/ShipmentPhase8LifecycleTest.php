<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 8 (P8-1…P8-5) — shipment creation cardinality + scoped idempotency,
 * dispatch preconditions/atomicity, delivery preconditions, cancellation.
 * Real database, no mocks. Single-process harness: races are proven via
 * stale models + lock reasoning and the limitation is documented per test.
 */
class ShipmentPhase8LifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'WH-8', 'name' => 'Main 8', 'status' => 'active', 'is_default' => true,
        ]);
        $location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'A-81',
            'barcode' => 'LOC-A-81', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'P8', 'slug' => 'p8-' . uniqid(), 'sku' => 'SKU-SHIP-8',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
    }

    private function makeCompletedOrder(int $qty = 2): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'O8', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100 * $qty, 'total_price' => 100 * $qty,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P8',
            'product_sku' => 'SKU-SHIP-8', 'product_quantity' => $qty,
            'product_price' => 100, 'product_total_price' => 100 * $qty,
        ]);

        return $order->refresh();
    }

    private function makeReadyFulfillment(Order $order, string $key): Fulfillment
    {
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order, $this->warehouse->id, $key);
        $owner = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship'] as $state) {
            $owner->transition($fulfillment, $state);
        }

        return $fulfillment->refresh();
    }

    // ------------------------------------------------------------------
    // P8-1 / F8-10: cardinality + scoped idempotency
    // ------------------------------------------------------------------

    public function test_unkeyed_duplicate_creation_is_refused(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p81-a');
        $service = app(ShipmentService::class);

        $service->createForFulfillment($fulfillment, [], null);

        try {
            $service->createForFulfillment($fulfillment, [], null);
            $this->fail('second unkeyed shipment must be refused');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('already has an active shipment', $e->getMessage());
        }

        $this->assertEquals(1, Shipment::where('fulfillment_id', $fulfillment->id)->count());
    }

    public function test_same_key_same_fulfillment_is_idempotent(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p81-b');
        $service = app(ShipmentService::class);

        $first = $service->createForFulfillment($fulfillment, [], 'key-8181');
        $again = $service->createForFulfillment($fulfillment, [], 'key-8181');

        $this->assertEquals($first->id, $again->id);
    }

    public function test_same_key_different_fulfillment_is_refused(): void
    {
        $order = $this->makeCompletedOrder();
        $f1 = $this->makeReadyFulfillment($order, 'p81-c1');
        $f2 = $this->makeReadyFulfillment($order, 'p81-c2');
        $service = app(ShipmentService::class);

        $first = $service->createForFulfillment($f1, [], 'shared-key-8');

        try {
            $service->createForFulfillment($f2, [], 'shared-key-8');
            $this->fail('cross-fulfillment key reuse must be refused');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('already bound', $e->getMessage());
        }

        // No row was created for the second fulfillment.
        $this->assertEquals(0, Shipment::where('fulfillment_id', $f2->id)->count());
        $this->assertEquals($f1->id, (int) $first->fulfillment_id);
    }

    public function test_creation_for_cancelled_fulfillment_is_refused(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order, $this->warehouse->id, 'p81-d');
        app(FulfillmentTransition::class)->transition($fulfillment, 'cancelled', ['reason' => 'test']);

        try {
            app(ShipmentService::class)->createForFulfillment($fulfillment->refresh(), [], null);
            $this->fail('cancelled fulfillment must not take a label');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }
    }

    public function test_stale_fulfillment_model_is_rechecked_under_lock(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p81-e');

        // Simulate a concurrent worker: the passed model is stale.
        $stale = Fulfillment::find($fulfillment->id);
        app(FulfillmentTransition::class)->transition($fulfillment, 'shipped', ['reason' => 'other']);

        try {
            app(ShipmentService::class)->createForFulfillment($stale, [], null);
            $this->fail('stale ready_to_ship model must be rechecked');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('expected ready_to_ship', $e->getMessage());
        }
        $this->assertEquals(0, Shipment::where('fulfillment_id', $fulfillment->id)->count());
    }

    public function test_database_backstop_rejects_second_active_row(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p81-f');

        DB::table('shipments')->insert([
            'uuid' => (string) \Illuminate\Support\Str::orderedUuid(),
            'order_id' => $order->id, 'fulfillment_id' => $fulfillment->id,
            'status' => 'label_created', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::table('shipments')->insert([
                'uuid' => (string) \Illuminate\Support\Str::orderedUuid(),
                'order_id' => $order->id, 'fulfillment_id' => $fulfillment->id,
                'status' => 'label_created', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('UNIQUE(active_fulfillment_id) must reject the race');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('shipments_active_fulfillment_unique', $e->getMessage());
        }

        // Terminal history rows never conflict.
        DB::table('shipments')->where('fulfillment_id', $fulfillment->id)->update(['status' => 'cancelled']);
        DB::table('shipments')->insert([
            'uuid' => (string) \Illuminate\Support\Str::orderedUuid(),
            'order_id' => $order->id, 'fulfillment_id' => $fulfillment->id,
            'status' => 'label_created', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertEquals(2, Shipment::where('fulfillment_id', $fulfillment->id)->count());
    }

    public function test_new_label_allowed_after_cancellation_history_retained(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p81-g');
        $service = app(ShipmentService::class);

        $first = $service->createForFulfillment($fulfillment, [], null);
        $service->cancelShipment($first->id, 'wrong label');

        // History retained; a fresh label is allowed.
        $second = $service->createForFulfillment($fulfillment, [], null);

        $this->assertNotEquals($first->id, $second->id);
        $this->assertEquals('cancelled', $first->refresh()->status);
        $this->assertEquals(2, Shipment::where('fulfillment_id', $fulfillment->id)->count());
    }

    // ------------------------------------------------------------------
    // P8-3: dispatch
    // ------------------------------------------------------------------

    public function test_valid_dispatch(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p83-a');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, ['courier' => 'Local'], null);
        $dispatched = $service->dispatch($shipment->id);

        $this->assertEquals('picked_up', $dispatched->status);
        $this->assertNotNull($dispatched->shipped_at);
        $this->assertEquals('shipped', $fulfillment->refresh()->status);
        $this->assertEquals('picked_up', $order->refresh()->shipment_status);
    }

    public function test_duplicate_dispatch_is_refused(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p83-b');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, [], null);
        $service->dispatch($shipment->id);

        try {
            $service->dispatch($shipment->id);
            $this->fail('duplicate dispatch must be refused');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            // Refused loudly; the fulfillment-first guard fires before the
            // shipment-state check on a repeat dispatch.
            $this->assertStringContainsString('Cannot dispatch shipment', $e->getMessage());
        }
        $this->assertEquals('picked_up', $shipment->refresh()->status);
    }

    public function test_dispatch_refuses_before_shipment_mutation_on_bad_fulfillment(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p83-c');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, [], null);

        // Concurrent path moved the fulfillment out from under the label.
        app(FulfillmentTransition::class)->transition($fulfillment, 'shipped', ['reason' => 'other']);

        try {
            $service->dispatch($shipment->id);
            $this->fail('dispatch must validate fulfillment first');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('expected ready_to_ship', $e->getMessage());
        }

        // F8-3: the shipment was NOT advanced by the failed attempt.
        $this->assertEquals('label_created', $shipment->refresh()->status);
        $this->assertNull($shipment->refresh()->shipped_at);
    }

    public function test_dispatch_refuses_incomplete_packing(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p83-d');

        // Packing work exists but picked goods are not covered by packages.
        $item = FulfillmentItem::where('fulfillment_id', $fulfillment->id)->first();
        $this->assertNotNull($item);
        $item->update(['quantity_picked' => 5]);
        PackingTask::create([
            'fulfillment_id' => $fulfillment->id,
            'status' => 'packing',
        ]);

        $shipment = app(ShipmentService::class)->createForFulfillment($fulfillment->refresh(), [], null);

        try {
            app(ShipmentService::class)->dispatch($shipment->id);
            $this->fail('dispatch must re-verify packing coverage');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('picked 5, packed 0', $e->getMessage());
        }

        $this->assertEquals('label_created', $shipment->refresh()->status);
        $this->assertEquals('ready_to_ship', $fulfillment->refresh()->status);
    }

    // ------------------------------------------------------------------
    // P8-4: delivery
    // ------------------------------------------------------------------

    public function test_valid_delivery_chain_sets_timestamps_and_mirror(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p84-a');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, ['courier' => 'Local'], null);
        $service->dispatch($shipment->id);
        $delivered = $service->markDelivered($shipment->id);

        $this->assertEquals('delivered', $delivered->status);
        $this->assertNotNull($delivered->delivered_at);
        $this->assertEquals('delivered', $fulfillment->refresh()->status);
        $this->assertEquals('delivered', $order->refresh()->status);

        $mirror = $order->refresh();
        $this->assertEquals('delivered', $mirror->shipment_status);
        $this->assertEquals('Local', $mirror->courier_name);
        $this->assertNotNull($mirror->actual_delivery_at);
    }

    /**
     * @dataProvider invalidDeliveryStartProvider
     */
    public function test_delivery_from_invalid_start_is_refused(string $setup, string $expectedStatus): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p84-' . $setup);
        $service = app(ShipmentService::class);

        if ($setup === 'pending') {
            $shipment = $service->create(['order_id' => $order->id, 'courier' => 'Local']);
        } else {
            $shipment = $service->createForFulfillment($fulfillment, [], null);
            if ($setup === 'failed' || $setup === 'returned' || $setup === 'delayed') {
                $service->dispatch($shipment->id);
                $service->updateStatus($shipment->id, 'in_transit');
                if ($setup === 'delayed') {
                    $service->updateStatus($shipment->id, 'delayed');
                } else {
                    $service->updateStatus($shipment->id, 'out_for_delivery');
                    $service->updateStatus($shipment->id, 'failed_delivery');
                    if ($setup === 'returned') {
                        $service->updateStatus($shipment->id, 'returned');
                    }
                }
            } elseif ($setup === 'cancelled') {
                $service->cancelShipment($shipment->id, 'void');
            }
            // 'label_created' needs no further setup.
        }

        $shipment = $shipment->refresh();
        $this->assertEquals($expectedStatus, $shipment->status);

        try {
            $service->markDelivered($shipment->id);
            $this->fail("delivery from {$expectedStatus} must be refused");
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('cannot be delivered', $e->getMessage());
        }

        // F8-2: neither the shipment nor the fulfillment moved.
        $this->assertEquals($expectedStatus, $shipment->refresh()->status);
        if ($setup !== 'pending') {
            $this->assertNotEquals('delivered', $fulfillment->refresh()->status);
        }
        $this->assertNotEquals('delivered', $order->refresh()->status);
    }

    public static function invalidDeliveryStartProvider(): array
    {
        return [
            'pending row' => ['pending', 'pending'],
            'label never dispatched' => ['label_created', 'label_created'],
            'failed delivery' => ['failed', 'failed_delivery'],
            'cancelled label' => ['cancelled', 'cancelled'],
            'returned goods' => ['returned', 'returned'],
            'delayed in transit' => ['delayed', 'delayed'],
        ];
    }

    public function test_delivery_refuses_before_shipment_mutation_on_bad_fulfillment(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p84-b');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, [], null);
        $service->dispatch($shipment->id);

        // Concurrent path delivered the fulfillment through another channel.
        app(FulfillmentTransition::class)->transition($fulfillment->refresh(), 'delivered', ['reason' => 'other']);

        try {
            $service->markDelivered($shipment->id);
            $this->fail('delivery must validate fulfillment first');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('expected shipped', $e->getMessage());
        }

        $this->assertEquals('picked_up', $shipment->refresh()->status);
    }

    public function test_duplicate_delivery_is_safe_idempotent_noop(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p84-c');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, [], null);
        $service->dispatch($shipment->id);
        $service->markDelivered($shipment->id);

        $again = $service->markDelivered($shipment->id);

        $this->assertEquals('delivered', $again->status);
        $this->assertEquals('delivered', $order->refresh()->status);
        $this->assertEquals(1, Shipment::where('fulfillment_id', $fulfillment->id)->count());
    }

    // ------------------------------------------------------------------
    // P8-5: cancellation
    // ------------------------------------------------------------------

    public function test_valid_shipment_cancellation_records_audit_and_mirror(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p85-a');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, ['courier' => 'Local'], null);
        $cancelled = $service->cancelShipment($shipment->id, '  customer asked  ', [
            'cancel_source' => 'staff_console',
        ]);

        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertEquals('customer asked', $cancelled->cancel_reason);
        $this->assertEquals('staff_console', $cancelled->cancel_source);
        $this->assertNull($cancelled->cancelled_by);

        // Fulfillment untouched (still cancellable through its own authority).
        $this->assertEquals('ready_to_ship', $fulfillment->refresh()->status);

        // Mirror follows the source of truth.
        $this->assertEquals('cancelled', $order->refresh()->shipment_status);
    }

    public function test_cancellation_requires_reason(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p85-b');
        $shipment = app(ShipmentService::class)->createForFulfillment($fulfillment, [], null);

        try {
            app(ShipmentService::class)->cancelShipment($shipment->id, '   ');
            $this->fail('empty reason must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('reason is required', $e->getMessage());
        }
        $this->assertEquals('label_created', $shipment->refresh()->status);
    }

    public function test_duplicate_cancellation_fails_loudly(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p85-c');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, [], null);
        $service->cancelShipment($shipment->id, 'first');

        try {
            $service->cancelShipment($shipment->id, 'second');
            $this->fail('duplicate cancellation must fail loudly');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('cannot be cancelled', $e->getMessage());
        }
    }

    public function test_cancellation_of_picked_up_label_is_allowed(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p85-d');
        $service = app(ShipmentService::class);

        // Dispatch moves the fulfillment to shipped — cancellation must then
        // refuse. Cancel the LABEL first while still DAG-legal instead.
        $shipment = $service->createForFulfillment($fulfillment, [], null);
        $service->updateStatus($shipment->id, 'picked_up');
        $this->assertEquals('ready_to_ship', $fulfillment->refresh()->status);

        $cancelled = $service->cancelShipment($shipment->id, 'courier refused pickup');
        $this->assertEquals('cancelled', $cancelled->status);
    }

    public function test_cancellation_refuses_shipped_and_delivered_fulfillment(): void
    {
        $order = $this->makeCompletedOrder();
        $fulfillment = $this->makeReadyFulfillment($order, 'p85-e');
        $service = app(ShipmentService::class);

        $shipment = $service->createForFulfillment($fulfillment, [], null);
        $service->dispatch($shipment->id);
        $this->assertEquals('shipped', $fulfillment->refresh()->status);

        try {
            $service->cancelShipment($shipment->id, 'too late');
            $this->fail('shipped fulfillment must refuse shipment cancellation');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('never moves fulfillment backward', $e->getMessage());
        }

        $this->assertEquals('picked_up', $shipment->refresh()->status);

        $service->markDelivered($shipment->id);

        try {
            // Terminal shipment states refuse on their own DAG arm as well.
            $service->cancelShipment($shipment->id, 'too late');
            $this->fail('delivered shipment must refuse cancellation');
        } catch (\RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\Exception) {
                throw $e;
            }
            $this->assertStringContainsString('cannot be cancelled', $e->getMessage());
        }
    }

    public function test_order_cancel_surfaces_live_shipment_without_orphaning_silently(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'O8C', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P8',
            'product_sku' => 'SKU-SHIP-8', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);
        $order = $order->refresh();

        // Production-shaped arrange: flow assigned + inventory reserved,
        // exactly like CancellationPhase7Test.
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order, 'local');
        app(\App\Services\Inventory\OrderReservationService::class)->reserveForOrder($order);
        $order = $order->refresh();

        $fulfillment = $this->makeReadyFulfillment($order, 'p85-f');
        $shipment = app(ShipmentService::class)->createForFulfillment($fulfillment, [], null);
        app(ShipmentService::class)->dispatch($shipment->id);

        // Order cancels: the shipped fulfillment is skipped (Phase-7 rule),
        // the live shipment is surfaced — never silently dropped, never
        // force-moved.
        app(\App\Services\General\OrderService::class)
            ->changeOrderStatus(null, 'cancelled', $order->id, true, 'buyer request');

        $this->assertEquals('cancelled', $order->refresh()->status);
        $this->assertEquals('shipped', $fulfillment->refresh()->status);
        $this->assertEquals('picked_up', $shipment->refresh()->status);
    }
}
