<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Fulfillment\PackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 6 — Packing & Package hardening (P6-1 → P6-7, D6-1/D6-2/D6-3).
 * Real database, no mocks. Concurrency tests prove lock-based decisions via
 * stale models; they document transaction/lock reasoning, not multi-process
 * execution (the harness is single-process).
 */
class PackingPhase6Test extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Warehouse $otherWarehouse;

    private PackingStation $station;

    private PackingStation $foreignStation;

    private Product $product;

    private Fulfillment $fulfillment;

    private $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'WH-1', 'name' => 'Main', 'status' => 'active', 'is_default' => true,
        ]);
        $this->otherWarehouse = Warehouse::create([
            'code' => 'WH-2', 'name' => 'Other', 'status' => 'active', 'is_default' => false,
        ]);
        $location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'A-01',
            'barcode' => 'LOC-A-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-P6-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);

        $this->operator = \App\Models\User::create([
            'name' => 'Packer', 'email' => 'packer@example.com',
            'password' => bcrypt('password'), 'type' => 'user',
            'is_active' => true, 'email_verified_at' => now(),
        ]);

        $this->station = PackingStation::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'ST-1',
            'name' => 'Station 1', 'status' => 'active',
        ]);
        $this->foreignStation = PackingStation::create([
            'warehouse_id' => $this->otherWarehouse->id, 'code' => 'ST-X',
            'name' => 'Foreign Station', 'status' => 'active',
        ]);

        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'O', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 1000, 'total_price' => 1000,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P',
            'product_sku' => 'SKU-P6-1', 'product_quantity' => 10,
            'product_price' => 100, 'product_total_price' => 1000,
        ]);
        $this->fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $this->warehouse->id, 'pack-key');

        $item = $this->fulfillment->items()->firstOrFail();
        $item->update(['quantity_picked' => 10, 'status' => 'picked']);
        app(FulfillmentTransition::class)->transition($this->fulfillment, 'picking');
        app(FulfillmentTransition::class)->transition($this->fulfillment, 'picked');
        app(FulfillmentTransition::class)->transition($this->fulfillment, 'packing');
        $this->fulfillment->refresh();
    }

    private function packing(): PackingService
    {
        return app(PackingService::class);
    }

    private function fullTaskFlow(): PackingTask
    {
        $task = $this->packing()->createPackingTaskFromFulfillment($this->fulfillment);
        $task = $this->packing()->assignToStation($task, $this->station->id, $this->operator->id);
        $task = $this->packing()->startPacking($task);

        return $this->packing()->completePacking($task, 2.5, ['length' => 30, 'width' => 20, 'height' => 10]);
    }

    private function packAll(int $quantity = 10): Package
    {
        $package = $this->packing()->createPackage($this->fulfillment->refresh());
        $this->packing()->addItemToPackage(
            $package, $this->fulfillment->items()->firstOrFail()->id, $quantity
        );

        return $package->refresh();
    }

    // ---------------- P6-1: duplicate open tasks ----------------

    public function test_second_open_packing_task_is_refused(): void
    {
        $this->packing()->createPackingTaskFromFulfillment($this->fulfillment);

        try {
            $this->packing()->createPackingTaskFromFulfillment($this->fulfillment->refresh());
            $this->fail('duplicate open task must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already has an open packing task', $e->getMessage());
        }
        $this->assertEquals(1, PackingTask::where('fulfillment_id', $this->fulfillment->id)->count());
    }

    public function test_stale_model_cannot_bypass_duplicate_guard(): void
    {
        $this->packing()->createPackingTaskFromFulfillment($this->fulfillment);

        // Caller holds a stale `picked` model; the decision must come from the
        // locked fresh row, so this is still an open-task refusal — never a
        // status-gate pass. Lock reasoning, not multi-process proof.
        $stale = Fulfillment::find($this->fulfillment->id);
        $stale->status = 'picked';

        try {
            $this->packing()->createPackingTaskFromFulfillment($stale);
            $this->fail('stale duplicate must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already has an open packing task', $e->getMessage());
        }
    }

    public function test_cancelled_single_task_allows_recreation(): void
    {
        $task = $this->packing()->createPackingTaskFromFulfillment($this->fulfillment);
        $this->packing()->cancelTask($task, 'wrong station');

        $retry = $this->packing()->createPackingTaskFromFulfillment($this->fulfillment->refresh());
        $this->assertEquals('pending', $retry->status);
        $this->assertNotEquals($task->id, $retry->id);
    }

    public function test_verified_terminal_recreation_follows_lifecycle_gate(): void
    {
        $task = $this->fullTaskFlow();
        $this->packAll();
        $this->packing()->verifyPacking($task);
        $this->assertEquals('ready_to_ship', $this->fulfillment->refresh()->status);

        // Terminal tasks do not trip the open-task guard; the lifecycle gate
        // itself refuses (ready_to_ship is not a packing-entry stage).
        try {
            $this->packing()->createPackingTaskFromFulfillment($this->fulfillment->refresh());
            $this->fail('recreation after verify must follow the lifecycle gate');
        } catch (\Exception $e) {
            $this->assertStringContainsString('status: ready_to_ship', $e->getMessage());
            $this->assertStringNotContainsString('open packing task', $e->getMessage());
        }
    }

    // ---------------- P6-2: locked complete / verify / cancel ----------------

    public function test_second_complete_is_refused(): void
    {
        $task = $this->fullTaskFlow();

        try {
            $this->packing()->completePacking($task->refresh(), 9.9, ['length' => 1]);
            $this->fail('second completion must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('status: packed', $e->getMessage());
        }
        // No last-writer-wins: original weight survives.
        $this->assertEquals(2.5, (float) $task->refresh()->weight);
    }

    public function test_stale_complete_is_refused(): void
    {
        $task = $this->fullTaskFlow();
        $stalePacking = PackingTask::find($task->id);
        $stalePacking->status = 'packing'; // caller state is stale; DB is packed.

        try {
            $this->packing()->completePacking($stalePacking, 9.9, ['length' => 1]);
            $this->fail('stale completion must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('status: packed', $e->getMessage());
        }
    }

    public function test_second_verify_is_refused(): void
    {
        $task = $this->fullTaskFlow();
        $this->packAll();
        $this->packing()->verifyPacking($task);

        try {
            $this->packing()->verifyPacking($task->refresh());
            $this->fail('second verification must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('status: verified', $e->getMessage());
        }
    }

    public function test_stale_verify_is_refused(): void
    {
        $task = $this->fullTaskFlow();
        $this->packAll();
        $stalePacked = PackingTask::find($task->id);
        $stalePacked->status = 'packed';

        $this->packing()->verifyPacking($task->refresh());

        try {
            $this->packing()->verifyPacking($stalePacked);
            $this->fail('stale verification must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('status: verified', $e->getMessage());
        }
    }

    public function test_cancel_requires_non_empty_reason(): void
    {
        $task = $this->packing()->createPackingTaskFromFulfillment($this->fulfillment);

        foreach ([null, '', '   '] as $bad) {
            try {
                $this->packing()->cancelTask($task->refresh(), $bad);
                $this->fail('empty reason must be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('reason', strtolower($e->getMessage()));
            }
        }
        $this->assertEquals('pending', $task->refresh()->status);
    }

    public function test_valid_pending_cancel_allowed(): void
    {
        $task = $this->packing()->createPackingTaskFromFulfillment($this->fulfillment);
        $cancelled = $this->packing()->cancelTask($task, '  no stock  ');
        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertEquals('no stock', $cancelled->notes);
    }

    public function test_cancel_vs_complete_is_serialized(): void
    {
        // Complete-then-cancel: packed work cannot downgrade.
        $task = $this->fullTaskFlow();
        try {
            $this->packing()->cancelTask($task->refresh(), 'too late');
            $this->fail('packed cancel must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('status: packed', $e->getMessage());
        }

        // Cancel-then-complete: cancelled work cannot complete.
        $task2 = $this->packing()->createPackingTaskFromFulfillment(
            Fulfillment::create([
                'order_id' => $this->fulfillment->order_id, 'warehouse_id' => $this->warehouse->id,
                'fulfillment_number' => 'FUL-P6-CVC', 'status' => 'packing',
            ])
        );
        $this->packing()->cancelTask($task2, 'abandoned');
        try {
            $this->packing()->completePacking($task2->refresh(), 1.0, ['length' => 1]);
            $this->fail('complete after cancel must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('status: cancelled', $e->getMessage());
        }
    }

    // ---------------- P6-3: completeness ----------------

    public function test_verify_refused_when_partially_packaged(): void
    {
        $task = $this->fullTaskFlow();
        $this->packAll(6);

        try {
            $this->packing()->verifyPacking($task);
            $this->fail('partial packaging must refuse verification');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('picked 10, packed 6', $e->getMessage());
        }
        $this->assertEquals('packed', $task->refresh()->status);
        $this->assertEquals('packing', $this->fulfillment->refresh()->status);
    }

    public function test_verify_allowed_when_fully_packaged(): void
    {
        $task = $this->fullTaskFlow();
        $this->packAll(10);

        $verified = $this->packing()->verifyPacking($task);
        $this->assertEquals('verified', $verified->status);
        $this->assertEquals('ready_to_ship', $this->fulfillment->refresh()->status);
    }

    public function test_verify_refused_with_sibling_open_task(): void
    {
        $task = $this->fullTaskFlow();
        $this->packAll(10);

        // Legacy/duplicate row planted directly (P6-1 prevents this via API).
        PackingTask::create(['fulfillment_id' => $this->fulfillment->id, 'status' => 'pending']);

        try {
            $this->packing()->verifyPacking($task);
            $this->fail('sibling open task must refuse verification');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('another open packing task', $e->getMessage());
        }
        $this->assertEquals('packing', $this->fulfillment->refresh()->status);
    }

    // ---------------- P6-4: packed cancel ----------------

    public function test_packed_task_cancel_refused(): void
    {
        $task = $this->fullTaskFlow();

        try {
            $this->packing()->cancelTask($task, 'changed mind');
            $this->fail('packed cancel must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('status: packed', $e->getMessage());
        }
        $this->assertEquals('packed', $task->refresh()->status);
    }

    public function test_verified_and_cancelled_tasks_cancel_refused(): void
    {
        $task = $this->fullTaskFlow();
        $this->packAll(10);
        $this->packing()->verifyPacking($task);

        try {
            $this->packing()->cancelTask($task->refresh(), 'too late');
            $this->fail('verified cancel must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('status: verified', $e->getMessage());
        }

        $task2 = $this->packing()->createPackingTaskFromFulfillment(
            Fulfillment::create([
                'order_id' => $this->fulfillment->order_id, 'warehouse_id' => $this->warehouse->id,
                'fulfillment_number' => 'FUL-P6-VC', 'status' => 'packing',
            ])
        );
        $this->packing()->cancelTask($task2, 'first');
        try {
            $this->packing()->cancelTask($task2->refresh(), 'second');
            $this->fail('double cancel must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('status: cancelled', $e->getMessage());
        }
    }

    // ---------------- P6-5: warehouse + station liveness ----------------

    public function test_cross_warehouse_assignment_refused(): void
    {
        $task = $this->packing()->createPackingTaskFromFulfillment($this->fulfillment);

        try {
            $this->packing()->assignToStation($task, $this->foreignStation->id, $this->operator->id);
            $this->fail('cross-warehouse assignment must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different warehouse', $e->getMessage());
        }
        $this->assertEquals('pending', $task->refresh()->status);
    }

    public function test_start_refused_when_station_deactivated_after_assign(): void
    {
        $task = $this->packing()->createPackingTaskFromFulfillment($this->fulfillment);
        $task = $this->packing()->assignToStation($task, $this->station->id, $this->operator->id);
        $this->station->update(['status' => 'inactive']);

        try {
            $this->packing()->startPacking($task);
            $this->fail('start on inactive station must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no longer active', $e->getMessage());
        }
        $this->assertEquals('assigned', $task->refresh()->status);
    }

    // ---------------- P6-6: package context ----------------

    public function test_mismatched_packing_task_linkage_refused(): void
    {
        $other = Fulfillment::create([
            'order_id' => $this->fulfillment->order_id, 'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-P6-LINK', 'status' => 'packing',
        ]);
        $foreignTask = $this->packing()->createPackingTaskFromFulfillment($other);

        try {
            $this->packing()->createPackage($this->fulfillment, $foreignTask->id);
            $this->fail('mismatched task linkage must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different fulfillment', $e->getMessage());
        }
        $this->assertEquals(0, Package::where('fulfillment_id', $this->fulfillment->id)->count());
    }

    public function test_cancelled_fulfillment_cannot_create_package(): void
    {
        app(FulfillmentService::class)->cancelFulfillment($this->fulfillment->refresh(), 'customer request');

        try {
            $this->packing()->createPackage($this->fulfillment->refresh());
            $this->fail('cancelled fulfillment must not create packages');
        } catch (\Exception $e) {
            $this->assertStringContainsString('status: cancelled', $e->getMessage());
        }
    }

    public function test_cancelled_fulfillment_cannot_add_package_item(): void
    {
        $package = $this->packing()->createPackage($this->fulfillment);
        app(FulfillmentService::class)->cancelFulfillment($this->fulfillment->refresh(), 'customer request');

        try {
            // The cancel flow voids the open package first, so this is refused
            // either way; the cancelled-fulfillment guard is the second wall.
            $this->packing()->addItemToPackage(
                $package->refresh(), $this->fulfillment->items()->firstOrFail()->id, 1
            );
            $this->fail('post-cancel add must be refused');
        } catch (\Exception $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'cancelled') || str_contains($e->getMessage(), 'voided')
            );
        }
    }

    // ---------------- P6-7 / D6-1: void + one package ----------------

    public function test_voided_package_allows_single_replacement(): void
    {
        $item = $this->fulfillment->items()->firstOrFail();
        $package = $this->packing()->createPackage($this->fulfillment);
        $this->packing()->addItemToPackage($package, $item->id, 4);

        $voided = $this->packing()->voidPackage($package, 'damaged box');
        $this->assertEquals(Package::STATUS_VOIDED, $voided->status);
        // History retained.
        $this->assertEquals(1, $voided->items()->count());

        // Voided history never blocks the single replacement.
        $replacement = $this->packing()->createPackage($this->fulfillment->refresh());
        $this->packing()->addItemToPackage($replacement, $item->id, 10);

        $this->assertEquals(1, Package::where('fulfillment_id', $this->fulfillment->id)
            ->where('status', '!=', Package::STATUS_VOIDED)->count());
    }

    public function test_void_requires_reason(): void
    {
        $package = $this->packing()->createPackage($this->fulfillment);

        foreach ([null, '', '   '] as $bad) {
            try {
                $this->packing()->voidPackage($package->refresh(), $bad);
                $this->fail('empty void reason must be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('reason', strtolower($e->getMessage()));
            }
        }
        $this->assertEquals(Package::STATUS_OPEN, $package->refresh()->status);
    }

    public function test_sealed_package_void_and_post_void_add_refused(): void
    {
        $package = $this->packAll(10);
        $sealed = $this->packing()->sealPackage($package, 2.5, ['length' => 30]);
        $this->assertEquals(Package::STATUS_SEALED, $sealed->status);

        // Sealed is physical custody — never silently voided.
        try {
            $this->packing()->voidPackage($sealed, 'oops');
            $this->fail('sealed void must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('only open packages', $e->getMessage());
        }

        $open = $this->packing()->createPackage(
            Fulfillment::create([
                'order_id' => $this->fulfillment->order_id, 'warehouse_id' => $this->warehouse->id,
                'fulfillment_number' => 'FUL-P6-VOID', 'status' => 'packing',
            ])
        );
        $this->packing()->voidPackage($open, 'abandoned');
        try {
            $this->packing()->addItemToPackage(
                $open->refresh(), $this->fulfillment->items()->firstOrFail()->id, 1
            );
            $this->fail('add after void must be refused');
        } catch (\Exception $e) {
            $this->assertStringContainsString('voided', $e->getMessage());
        }
    }

    public function test_fulfillment_cancel_voids_open_package(): void
    {
        $package = $this->packAll(4);

        app(FulfillmentService::class)->cancelFulfillment($this->fulfillment->refresh(), 'customer request');

        $this->assertEquals(Package::STATUS_VOIDED, $package->refresh()->status);
        $this->assertEquals('cancelled', $this->fulfillment->refresh()->status);
    }

    public function test_fulfillment_cancel_retains_sealed_package(): void
    {
        $package = $this->packAll(10);
        $this->packing()->sealPackage($package, 2.5, ['length' => 30]);

        // Phase 7 (D7-5): sealed custody refuses cancellation loudly
        // instead of silently cancelling around it — the package is
        // retained AND the fulfillment stays open for a supervisor decision.
        try {
            app(FulfillmentService::class)->cancelFulfillment($this->fulfillment->refresh(), 'customer request');
            $this->fail('sealed custody must refuse fulfillment cancellation');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sealed', $e->getMessage());
        }

        // Sealed custody needs a supervisor decision — retained, never voided.
        $this->assertEquals(Package::STATUS_SEALED, $package->refresh()->status);
        $this->assertEquals('packing', $this->fulfillment->refresh()->status);
    }

    // ---------------- Boundaries ----------------

    public function test_full_packing_flow_leaves_order_and_inventory_untouched(): void
    {
        $orderStatusBefore = $this->fulfillment->order()->first()->status;
        $stockBefore = (float) $this->product->refresh()->stock_quantity;
        $reservedBefore = (float) $this->product->refresh()->reserved_quantity;

        $task = $this->fullTaskFlow();
        $this->packAll(10);
        $this->packing()->verifyPacking($task);

        $this->assertEquals('ready_to_ship', $this->fulfillment->refresh()->status);
        $this->assertEquals($orderStatusBefore, $this->fulfillment->order()->first()->refresh()->status);
        $this->assertEquals($stockBefore, (float) $this->product->refresh()->stock_quantity);
        $this->assertEquals($reservedBefore, (float) $this->product->refresh()->reserved_quantity);
    }
}
