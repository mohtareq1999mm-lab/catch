<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * P9-5: picking/batch completion + operational hardening.
 *
 * Exposes the batch completion authority (BatchPickingService::
 * refreshBatchProgress) for the scan flow: confirm × N, then refresh.
 * Claim-expiry sweeping is already covered (scheduled command +
 * PickingFoundationTest P4.5 sweep tests); no new surface needed there.
 * Sequential only (shared MySQL).
 */
class WmsBatchCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'view-location', 'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'batch.manage', 'batch.operate',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouseA = Warehouse::create([
            'code' => 'WH-CA', 'name' => 'Completion A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-CB', 'name' => 'Completion B', 'status' => 'active', 'is_default' => false,
        ]);

        $location = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'CA-01',
            'barcode' => 'CA-LOC-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        Location::create([
            'warehouse_id' => $this->warehouseB->id, 'code' => 'CB-01',
            'barcode' => 'CB-LOC-01', 'name' => 'Bin B', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'PC', 'slug' => 'pc-' . uniqid(), 'sku' => 'SKU-COMP-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouseA->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
    }

    private function user(?int $home, array $permissions = []): User
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $home]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function batchOperator(?int $home = null): User
    {
        return $this->user($home ?? $this->warehouseA->id, [
            'view-fulfillment', 'batch.manage', 'batch.operate', 'picking-execute',
        ]);
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'CO', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'PC',
            'product_sku' => 'SKU-COMP-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        return $order->refresh();
    }

    private function releasePair(string $suffix): array
    {
        $svc = app(FulfillmentService::class);
        $f1 = $svc->releaseForOrder($this->makeOrder(), $this->warehouseA->id, "cp{$suffix}a");
        $f2 = $svc->releaseForOrder($this->makeOrder(), $this->warehouseA->id, "cp{$suffix}b");

        return [$f1->fresh(), $f2->fresh()];
    }

    private function createBatchHttp(User $actor, array $payload)
    {
        return $this->actingAs($actor, 'sanctum')->postJson('/api/v1/admin/batches', $payload);
    }

    private function refreshHttp(User $actor, int $batchId)
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/refresh-progress", []);
    }

    private function startBatch(int $batchId, User $operator): FulfillmentBatch
    {
        $batch = FulfillmentBatch::findOrFail($batchId);
        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $operator->id])
            ->assertStatus(200);
        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/start", [])
            ->assertStatus(200);

        return $batch->fresh();
    }

    private function confirmAllTasksHttp(User $actor, FulfillmentBatch $batch): void
    {
        foreach ($batch->pickingTasks()->orderBy('id')->get() as $task) {
            $this->actingAs($actor, 'sanctum')
                ->postJson("/api/v1/admin/picking-tasks/{$task->id}/claim", [])
                ->assertStatus(200);
            $this->actingAs($actor, 'sanctum')->postJson(
                "/api/v1/admin/picking-tasks/{$task->id}/confirm",
                [
                    'location' => 'CA-LOC-01', 'product' => 'SKU-COMP-1',
                    'quantity' => 2, 'op_seq' => 1,
                ]
            )->assertStatus(200);
            $this->assertSame('picked', $task->fresh()->status);
        }
    }

    // ---------- auth / permission / scope ----------

    public function test_refresh_progress_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/v1/admin/batches/1/refresh-progress', [])->assertStatus(401);
    }

    public function test_refresh_progress_requires_batch_operate(): void
    {
        [$f1, $f2] = $this->releasePair('p');
        $operator = $this->batchOperator();
        $batchId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]])
            ->assertStatus(201)->json('data.id');

        // Picker holds picking-execute only: denied before any state is read.
        $picker = $this->user($this->warehouseA->id, ['view-fulfillment', 'picking-execute']);
        $this->refreshHttp($picker, $batchId)->assertStatus(403);
        $this->assertSame('pending', FulfillmentBatch::find($batchId)->status);
    }

    public function test_refresh_progress_missing_returns_404_and_cross_warehouse_denied(): void
    {
        $operator = $this->batchOperator();
        $this->refreshHttp($operator, 999999)->assertStatus(404);

        [$f1, $f2] = $this->releasePair('x');
        $batchId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]])
            ->assertStatus(201)->json('data.id');

        // Operator homed in B probing an A batch → 403, state untouched.
        $foreign = $this->batchOperator($this->warehouseB->id);
        $this->refreshHttp($foreign, $batchId)->assertStatus(403);
        $this->assertSame('pending', FulfillmentBatch::find($batchId)->status);
    }

    // ---------- completion semantics ----------

    public function test_refresh_progress_not_ready_recounts_and_stays_open(): void
    {
        [$f1, $f2] = $this->releasePair('n');
        $operator = $this->batchOperator();
        $batchId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]])
            ->assertStatus(201)->json('data.id');
        $this->startBatch($batchId, $operator);

        $response = $this->refreshHttp($operator, $batchId);
        $response->assertStatus(200);
        $this->assertSame('Batch progress refreshed successfully.', $response->json('message'));

        $fresh = FulfillmentBatch::find($batchId);
        $this->assertSame('picking', $fresh->status);
        $this->assertSame(0, (int) $fresh->picked_items);
        $this->assertNull($fresh->completed_at);
    }

    public function test_scan_flow_confirm_then_refresh_completes_batch_and_advances_fulfillments(): void
    {
        [$f1, $f2] = $this->releasePair('s');
        $operator = $this->batchOperator();
        $batchId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]])
            ->assertStatus(201)->json('data.id');
        $batch = $this->startBatch($batchId, $operator);

        // Refresh BEFORE picks finish: still open (partial completion guard).
        $this->refreshHttp($operator, $batchId)->assertStatus(200);
        $this->assertSame('picking', FulfillmentBatch::find($batchId)->status);

        $this->confirmAllTasksHttp($operator, $batch->fresh());

        $response = $this->refreshHttp($operator, $batchId);
        $response->assertStatus(200);
        $this->assertSame('Batch completed successfully.', $response->json('message'));

        $fresh = FulfillmentBatch::find($batchId);
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertSame(2, (int) $fresh->picked_items);

        // Fully-picked fulfillments advance via the transition owner.
        $this->assertSame('picked', $f1->fresh()->status);
        $this->assertSame('picked', $f2->fresh()->status);
    }

    public function test_refresh_progress_completed_is_stable_replay(): void
    {
        [$f1, $f2] = $this->releasePair('r');
        $operator = $this->batchOperator();
        $batchId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]])
            ->assertStatus(201)->json('data.id');
        $batch = $this->startBatch($batchId, $operator);
        $this->confirmAllTasksHttp($operator, $batch->fresh());

        $this->refreshHttp($operator, $batchId)->assertStatus(200);
        $completedAt = (string) FulfillmentBatch::find($batchId)->completed_at;

        // Repeat refresh: 200 no-op, timestamp stable, no extra tasks.
        $this->refreshHttp($operator, $batchId)->assertStatus(200);
        $this->assertSame($completedAt, (string) FulfillmentBatch::find($batchId)->completed_at);
        $this->assertSame(2, FulfillmentBatch::find($batchId)->pickingTasks()->count());
    }

    public function test_refresh_progress_leaves_orders_inventory_shipments_untouched(): void
    {
        [$f1, $f2] = $this->releasePair('u');
        $operator = $this->batchOperator();
        $batchId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]])
            ->assertStatus(201)->json('data.id');
        $batch = $this->startBatch($batchId, $operator);
        $this->confirmAllTasksHttp($operator, $batch->fresh());

        $orderBefore = $f1->order()->first()->only(['status', 'fulfillment_status', 'inventory_state']);
        $stockBefore = [(float) $this->product->fresh()->stock_quantity, (float) $this->product->fresh()->reserved_quantity];
        $shipmentsBefore = \App\Models\Shipment::count();

        $this->refreshHttp($operator, $batchId)->assertStatus(200);

        $this->assertSame($orderBefore, $f1->order()->first()->only(['status', 'fulfillment_status', 'inventory_state']));
        $this->assertSame($stockBefore, [(float) $this->product->fresh()->stock_quantity, (float) $this->product->fresh()->reserved_quantity]);
        $this->assertSame($shipmentsBefore, \App\Models\Shipment::count());
    }
}
