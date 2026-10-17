<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\BatchPickingService;
use App\Services\Fulfillment\FulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WmsBatchConsolidatedPickingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;
    private Warehouse $warehouseB;
    private Location $locationA1;
    private Location $locationA2;
    private Location $locationB1;
    private Product $product1;
    private Product $product2;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'view-location', 'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'fulfillment-override', 'batch.manage', 'batch.operate',
            'manage-warehouse',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouseA = Warehouse::create([
            'code' => 'WH-TEST-A', 'name' => 'Warehouse A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-TEST-B', 'name' => 'Warehouse B', 'status' => 'active', 'is_default' => false,
        ]);

        $this->locationA1 = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'LOC-A-01',
            'barcode' => 'BAR-LOC-A01', 'name' => 'Bin A-01', 'type' => 'picking',
            'status' => 'active', 'priority' => 10,
        ]);
        $this->locationA2 = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'LOC-A-02',
            'barcode' => 'BAR-LOC-A02', 'name' => 'Bin A-02', 'type' => 'picking',
            'status' => 'active', 'priority' => 8,
        ]);
        $this->locationB1 = Location::create([
            'warehouse_id' => $this->warehouseB->id, 'code' => 'LOC-B-01',
            'barcode' => 'BAR-LOC-B01', 'name' => 'Bin B-01', 'type' => 'picking',
            'status' => 'active', 'priority' => 10,
        ]);

        $this->product1 = Product::create([
            'name' => 'Product 1 Keyboard', 'slug' => 'prod-keyboard-' . uniqid(), 'sku' => 'SKU-KEYBOARD-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 100, 'reserved_quantity' => 0,
        ]);
        $this->product2 = Product::create([
            'name' => 'Product 2 Mouse', 'slug' => 'prod-mouse-' . uniqid(), 'sku' => 'SKU-MOUSE-2',
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 100, 'reserved_quantity' => 0,
        ]);

        // Place product 1 at location A1
        ProductLocation::create([
            'product_id' => $this->product1->id, 'location_id' => $this->locationA1->id,
            'warehouse_id' => $this->warehouseA->id, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        // Also place product 2 at location A1 (to test multi-product bin disambiguation)
        ProductLocation::create([
            'product_id' => $this->product2->id, 'location_id' => $this->locationA1->id,
            'warehouse_id' => $this->warehouseA->id, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        // Place product 1 at location A2
        ProductLocation::create([
            'product_id' => $this->product1->id, 'location_id' => $this->locationA2->id,
            'warehouse_id' => $this->warehouseA->id, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        // Place product 1 at warehouse B
        ProductLocation::create([
            'product_id' => $this->product1->id, 'location_id' => $this->locationB1->id,
            'warehouse_id' => $this->warehouseB->id, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
    }

    private function makeUser(?int $homeWarehouseId, array $permissions): User
    {
        $user = User::factory()->create([
            'type' => 'staff',
            'warehouse_id' => $homeWarehouseId,
        ]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }
        return $user;
    }

    private function batchOperator(?int $homeWarehouseId = null): User
    {
        return $this->makeUser($homeWarehouseId ?? $this->warehouseA->id, [
            'view-fulfillment', 'batch.manage', 'batch.operate', 'picking-execute',
        ]);
    }

    private function makeOrderWithProduct(Product $product, int $quantity = 1): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'Cust ' . uniqid(), 'user_email' => $customer->email,
            'user_phone' => '01012345678', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => $product->price * $quantity, 'total_price' => $product->price * $quantity,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo', 'street' => 'Street 1'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'product_quantity' => $quantity,
            'product_price' => $product->price,
            'product_total_price' => $product->price * $quantity,
        ]);

        return $order->refresh();
    }

    private function releaseFulfillment(Product $product, int $warehouseId, int $quantity = 1): Fulfillment
    {
        $order = $this->makeOrderWithProduct($product, $quantity);
        return app(FulfillmentService::class)->releaseForOrder(
            $order,
            $warehouseId,
            'rel-' . uniqid()
        );
    }

    private function createAndStartBatch(array $fulfillments, User $operator): FulfillmentBatch
    {
        $batch = app(BatchPickingService::class)->createBatchFromFulfillments(
            collect($fulfillments),
            $this->warehouseA->id
        );

        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/assign", [
            'user_id' => $operator->id,
        ])->assertStatus(200);

        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/start")->assertStatus(200);

        return $batch->fresh();
    }

    // 1. Consolidated metadata in next-task
    public function test_next_task_returns_consolidated_metadata_when_multiple_orders_share_location(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 2);
        $f2 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 3);
        $f3 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 5);

        $batch = $this->createAndStartBatch([$f1, $f2, $f3], $operator);

        $response = $this->actingAs($operator, 'sanctum')->getJson("/api/v1/admin/batches/{$batch->id}/next-task");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertTrue($data['is_consolidated']);
        $this->assertEquals(10.0, (float) $data['consolidated_total_quantity']);
        $this->assertEquals(3, $data['orders_count']);
        $this->assertCount(3, $data['grouped_task_ids']);
        // Verify standard backward-compatible attributes are untouched
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('batch_id', $data);
        $this->assertArrayHasKey('product_location_id', $data);
        $this->assertArrayHasKey('status', $data);
    }

    // 2. Single order item is not marked consolidated
    public function test_next_task_returns_not_consolidated_when_single_task_at_location(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 1);
        $batch = $this->createAndStartBatch([$f1], $operator);

        $response = $this->actingAs($operator, 'sanctum')->getJson("/api/v1/admin/batches/{$batch->id}/next-task");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertFalse($data['is_consolidated']);
        $this->assertEquals(1.0, (float) $data['consolidated_total_quantity']);
        $this->assertEquals(1, $data['orders_count']);
    }

    // 3. Confirm entire location pick in single request (e.g. 10 items for 3 orders)
    public function test_confirm_location_picks_all_tasks_at_location_in_single_call(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 2);
        $f2 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 3);
        $f3 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 5);

        $batch = $this->createAndStartBatch([$f1, $f2, $f3], $operator);

        $response = $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 10,
        ]);

        $response->assertStatus(200);
        $payload = $response->json('data');
        $this->assertEquals(10, $payload['total_picked_now']);
        $this->assertEquals(0, $payload['remaining_at_location']);
        $this->assertEquals(3, $payload['tasks_completed_count']);
        $this->assertCount(3, $payload['updated_task_ids']);

        // Assert all 3 tasks in database are now 'picked'
        $tasks = PickingTask::whereIn('id', $payload['updated_task_ids'])->get();
        foreach ($tasks as $task) {
            $this->assertEquals('picked', $task->status);
            $this->assertEquals($task->quantity_to_pick, $task->quantity_picked);
            $this->assertNotNull($task->picked_at);
        }
    }

    // 4. Partial location pick allocates across tasks sequentially
    public function test_confirm_location_partial_quantity_advances_tasks_in_sequence(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 2);
        $f2 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 3);
        $f3 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 5);

        $batch = $this->createAndStartBatch([$f1, $f2, $f3], $operator);

        // Pick 4 out of 10 items
        $response = $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 4,
        ]);

        $response->assertStatus(200);
        $payload = $response->json('data');
        $this->assertEquals(4, $payload['total_picked_now']);
        $this->assertEquals(6, $payload['remaining_at_location']);

        $tasks = $batch->pickingTasks()->orderBy('sequence')->get();
        // Task 1 (qty 2): fully picked (2/2)
        $this->assertEquals('picked', $tasks[0]->status);
        $this->assertEquals(2.0, (float) $tasks[0]->quantity_picked);

        // Task 2 (qty 3): partially picked (2/3)
        $this->assertEquals('picking', $tasks[1]->status);
        $this->assertEquals(2.0, (float) $tasks[1]->quantity_picked);

        // Task 3 (qty 5): unpicked (0/5)
        $this->assertEquals('pending', $tasks[2]->status);
        $this->assertEquals(0.0, (float) $tasks[2]->quantity_picked);
    }

    // 5. Fulfillment items are updated accurately
    public function test_confirm_location_updates_fulfillment_items_quantity_picked_and_status(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 2);
        $batch = $this->createAndStartBatch([$f1], $operator);

        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 2,
        ])->assertStatus(200);

        $item = $f1->items()->first();
        $this->assertEquals(2.0, (float) $item->fresh()->quantity_picked);
        $this->assertEquals('picked', $item->fresh()->status);
        $this->assertNotNull($item->fresh()->picked_at);
    }

    // 6. Over-picking rejected with 422
    public function test_confirm_location_rejects_quantity_greater_than_total_remaining(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 3);
        $batch = $this->createAndStartBatch([$f1], $operator);

        $response = $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 10, // Only 3 required!
        ]);

        $response->assertStatus(422);
    }

    // 7. Zero or negative quantity rejected with 422
    public function test_confirm_location_rejects_zero_or_negative_quantity(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 2);
        $batch = $this->createAndStartBatch([$f1], $operator);

        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 0,
        ])->assertStatus(422);

        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => -2,
        ])->assertStatus(422);
    }

    // 8. Invalid or foreign location barcode rejected
    public function test_confirm_location_rejects_nonexistent_or_foreign_location_barcode(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 2);
        $batch = $this->createAndStartBatch([$f1], $operator);

        // Nonexistent location
        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => 'NON-EXISTENT-LOC',
            'product' => $this->product1->sku,
            'quantity' => 2,
        ])->assertStatus(422);

        // Foreign warehouse location (warehouse B)
        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationB1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 2,
        ])->assertStatus(422);
    }

    // 9. Non-matching product barcode rejected
    public function test_confirm_location_rejects_non_matching_product_barcode(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 2);
        $batch = $this->createAndStartBatch([$f1], $operator);

        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => 'UNKNOWN-PRODUCT-SKU',
            'quantity' => 2,
        ])->assertStatus(422);
    }

    // 10. Multi-product bin disambiguation
    public function test_confirm_location_only_picks_matching_product_when_multiple_products_share_location(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 3);
        $f2 = $this->releaseFulfillment($this->product2, $this->warehouseA->id, 2);

        $batch = $this->createAndStartBatch([$f1, $f2], $operator);

        // Pick product 1 only at location A1
        $response = $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 3,
        ]);
        $response->assertStatus(200);

        // Product 1 task is picked
        $task1 = $batch->pickingTasks()->whereHas('fulfillmentItem', fn ($q) => $q->where('product_id', $this->product1->id))->first();
        $this->assertEquals('picked', $task1->status);

        // Product 2 task remains pending
        $task2 = $batch->pickingTasks()->whereHas('fulfillmentItem', fn ($q) => $q->where('product_id', $this->product2->id))->first();
        $this->assertEquals('pending', $task2->status);
        $this->assertEquals(0, $task2->quantity_picked);
    }

    // 11. Permission check (batch.operate)
    public function test_confirm_location_requires_batch_operate_permission(): void
    {
        $userWithoutPermission = $this->makeUser($this->warehouseA->id, ['view-fulfillment']);
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 2);
        $batch = $this->createAndStartBatch([$f1], $operator);

        $this->actingAs($userWithoutPermission, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 2,
        ])->assertStatus(403);
    }

    // 12. Unauthenticated request returns 401
    public function test_confirm_location_rejects_unauthenticated_request(): void
    {
        $this->postJson('/api/v1/admin/batches/1/confirm-location', [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 1,
        ])->assertStatus(401);
    }

    // 13. Warehouse scope enforcement
    public function test_confirm_location_rejects_foreign_warehouse_operator(): void
    {
        $foreignOperator = $this->batchOperator($this->warehouseB->id);
        $operatorA = $this->batchOperator($this->warehouseA->id);
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 2);
        $batch = $this->createAndStartBatch([$f1], $operatorA);

        $this->actingAs($foreignOperator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 2,
        ])->assertStatus(403);
    }

    // 14. Batch status check
    public function test_confirm_location_rejects_unstarted_batch(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 2);

        // Created but NOT assigned or started (status: pending)
        $batch = app(BatchPickingService::class)->createBatchFromFulfillments(
            collect([$f1]),
            $this->warehouseA->id
        );

        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 2,
        ])->assertStatus(422);
    }

    // 15. Audit scan log recorded
    public function test_confirm_location_appends_structured_audit_scan_log_to_each_task(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 2);
        $batch = $this->createAndStartBatch([$f1], $operator);

        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 2,
            'op_seq' => 101,
        ])->assertStatus(200);

        $task = $batch->pickingTasks()->first();
        $this->assertIsArray($task->scan_log);
        $this->assertNotEmpty($task->scan_log);

        $entry = $task->scan_log[0];
        $this->assertEquals('confirm_location', $entry['action']);
        $this->assertEquals(101, $entry['op_seq']);
        $this->assertEquals($operator->id, $entry['actor_id']);
        $this->assertEquals($this->locationA1->barcode, $entry['location']);
        $this->assertEquals($this->product1->sku, $entry['product']);
        $this->assertEquals(2.0, (float) $entry['quantity']);
        $this->assertEquals('ok', $entry['result']);
    }

    // 16. Legacy single-task confirm endpoint is not regressed
    public function test_legacy_single_task_confirm_still_works_without_regression(): void
    {
        $operator = $this->batchOperator();
        $f1 = $this->releaseFulfillment($this->product1, $this->warehouseA->id, 1);
        $batch = $this->createAndStartBatch([$f1], $operator);

        $task = $batch->pickingTasks()->first();

        // Worker claims the task first
        $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/picking-tasks/{$task->id}/claim")
            ->assertStatus(200);

        // Standard single task confirm
        $response = $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/picking-tasks/{$task->id}/confirm", [
            'location' => $this->locationA1->barcode,
            'product' => $this->product1->sku,
            'quantity' => 1,
            'op_seq' => 1,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('picked', $task->fresh()->status);
    }

    // 17. Variant consolidated picking with variant SKU
    public function test_confirm_location_picks_variant_tasks_with_variant_sku(): void
    {
        $operator = $this->batchOperator();

        // Create variable product and variant
        $parentProd = Product::create([
            'name' => 'Hoodie Parent', 'slug' => 'hoodie-' . uniqid(), 'sku' => 'SKU-HOODIE-BASE',
            'price' => 120, 'product_type' => 'variable', 'status' => true, 'in_stock' => true,
        ]);
        $variantM = \Marvel\Database\Models\ProductVariant::create([
            'product_id' => $parentProd->id, 'sku' => 'VAR-HOODIE-MEDIUM',
            'price' => 120, 'stock_quantity' => 100, 'in_stock' => true,
        ]);
        ProductLocation::create([
            'product_id' => $parentProd->id, 'product_variant_id' => $variantM->id,
            'location_id' => $this->locationA1->id, 'warehouse_id' => $this->warehouseA->id,
            'quantity' => 100, 'allocated_hint' => 0,
        ]);

        // Create 2 orders for this variant
        $f1 = $this->releaseVariantFulfillment($parentProd, $variantM, $this->warehouseA->id, 2);
        $f2 = $this->releaseVariantFulfillment($parentProd, $variantM, $this->warehouseA->id, 3);

        $batch = $this->createAndStartBatch([$f1, $f2], $operator);

        // Confirm pick for variant M (total 5 pieces) using variant SKU
        $response = $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $variantM->sku,
            'quantity' => 5,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(5, $data['total_picked_now']);
        $this->assertEquals(0, $data['remaining_at_location']);
        $this->assertEquals(2, $data['tasks_completed_count']);

        // Assert tasks are marked picked
        $tasks = $batch->pickingTasks()->get();
        foreach ($tasks as $task) {
            $this->assertEquals('picked', $task->status);
            $this->assertEquals($task->quantity_to_pick, $task->quantity_picked);
            $this->assertEquals($variantM->id, (int) $task->fulfillmentItem->product_variant_id);
        }
    }

    // 18. Disambiguate different variants of the same product at the same location
    public function test_confirm_location_disambiguates_different_variants_of_same_product_at_same_location(): void
    {
        $operator = $this->batchOperator();

        $parentProd = Product::create([
            'name' => 'Sneakers Parent', 'slug' => 'sneakers-' . uniqid(), 'sku' => 'SKU-SNEAKER-BASE',
            'price' => 200, 'product_type' => 'variable', 'status' => true, 'in_stock' => true,
        ]);
        $variant42 = \Marvel\Database\Models\ProductVariant::create([
            'product_id' => $parentProd->id, 'sku' => 'VAR-SNEAKER-42',
            'price' => 200, 'stock_quantity' => 100, 'in_stock' => true,
        ]);
        $variant43 = \Marvel\Database\Models\ProductVariant::create([
            'product_id' => $parentProd->id, 'sku' => 'VAR-SNEAKER-43',
            'price' => 200, 'stock_quantity' => 100, 'in_stock' => true,
        ]);

        ProductLocation::create([
            'product_id' => $parentProd->id, 'product_variant_id' => $variant42->id,
            'location_id' => $this->locationA1->id, 'warehouse_id' => $this->warehouseA->id,
            'quantity' => 100, 'allocated_hint' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $parentProd->id, 'product_variant_id' => $variant43->id,
            'location_id' => $this->locationA1->id, 'warehouse_id' => $this->warehouseA->id,
            'quantity' => 100, 'allocated_hint' => 0,
        ]);

        $f42 = $this->releaseVariantFulfillment($parentProd, $variant42, $this->warehouseA->id, 4);
        $f43 = $this->releaseVariantFulfillment($parentProd, $variant43, $this->warehouseA->id, 3);

        $batch = $this->createAndStartBatch([$f42, $f43], $operator);

        // Pick variant 42 only (4 pieces)
        $response = $this->actingAs($operator, 'sanctum')->postJson("/api/v1/admin/batches/{$batch->id}/confirm-location", [
            'location' => $this->locationA1->barcode,
            'product' => $variant42->sku,
            'quantity' => 4,
        ]);
        $response->assertStatus(200);

        // Variant 42 is picked
        $task42 = $batch->pickingTasks()->whereHas('fulfillmentItem', fn ($q) => $q->where('product_variant_id', $variant42->id))->first();
        $this->assertEquals('picked', $task42->status);
        $this->assertEquals(4.0, (float) $task42->quantity_picked);

        // Variant 43 remains pending
        $task43 = $batch->pickingTasks()->whereHas('fulfillmentItem', fn ($q) => $q->where('product_variant_id', $variant43->id))->first();
        $this->assertEquals('pending', $task43->status);
        $this->assertEquals(0.0, (float) $task43->quantity_picked);
    }

    // 19. Next task consolidated quantity groups strictly by variant
    public function test_next_task_consolidated_quantity_groups_strictly_by_variant_at_same_location(): void
    {
        $operator = $this->batchOperator();

        $parentProd = Product::create([
            'name' => 'Jacket Parent', 'slug' => 'jacket-' . uniqid(), 'sku' => 'SKU-JACKET-BASE',
            'price' => 300, 'product_type' => 'variable', 'status' => true, 'in_stock' => true,
        ]);
        $variantS = \Marvel\Database\Models\ProductVariant::create([
            'product_id' => $parentProd->id, 'sku' => 'VAR-JACKET-SMALL',
            'price' => 300, 'stock_quantity' => 100, 'in_stock' => true,
        ]);
        $variantL = \Marvel\Database\Models\ProductVariant::create([
            'product_id' => $parentProd->id, 'sku' => 'VAR-JACKET-LARGE',
            'price' => 300, 'stock_quantity' => 100, 'in_stock' => true,
        ]);

        ProductLocation::create([
            'product_id' => $parentProd->id, 'product_variant_id' => $variantS->id,
            'location_id' => $this->locationA1->id, 'warehouse_id' => $this->warehouseA->id,
            'quantity' => 100, 'allocated_hint' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $parentProd->id, 'product_variant_id' => $variantL->id,
            'location_id' => $this->locationA1->id, 'warehouse_id' => $this->warehouseA->id,
            'quantity' => 100, 'allocated_hint' => 0,
        ]);

        $fS1 = $this->releaseVariantFulfillment($parentProd, $variantS, $this->warehouseA->id, 2);
        $fS2 = $this->releaseVariantFulfillment($parentProd, $variantS, $this->warehouseA->id, 3);
        $fL1 = $this->releaseVariantFulfillment($parentProd, $variantL, $this->warehouseA->id, 7);

        $batch = $this->createAndStartBatch([$fS1, $fS2, $fL1], $operator);

        $response = $this->actingAs($operator, 'sanctum')->getJson("/api/v1/admin/batches/{$batch->id}/next-task");
        $response->assertStatus(200);

        $data = $response->json('data');
        // First task is for Small: total should be 5 (2 + 3), NOT 12!
        $this->assertTrue($data['is_consolidated']);
        $this->assertEquals(5.0, (float) $data['consolidated_total_quantity']);
        $this->assertEquals(2, $data['orders_count']);
        $this->assertCount(2, $data['grouped_task_ids']);
    }

    private function releaseVariantFulfillment(Product $parent, \Marvel\Database\Models\ProductVariant $variant, int $warehouseId, int $quantity): Fulfillment
    {
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'Cust ' . uniqid(), 'user_email' => $customer->email,
            'user_phone' => '01012345678', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => $variant->price * $quantity, 'total_price' => $variant->price * $quantity,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo', 'street' => 'Street 1'],
        ]);
        $order->orderItems()->create([
            'product_id' => $parent->id,
            'product_variant_id' => $variant->id,
            'product_name' => $parent->name . ' (' . $variant->sku . ')',
            'product_sku' => $variant->sku,
            'product_quantity' => $quantity,
            'product_price' => $variant->price,
            'product_total_price' => $variant->price * $quantity,
        ]);

        return app(FulfillmentService::class)->releaseForOrder(
            $order->refresh(),
            $warehouseId,
            'rel-var-' . uniqid()
        );
    }
}
