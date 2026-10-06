<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\BatchPickingService;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\OrderPickingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * P9-4: Picking + Batch HTTP adapter.
 *
 * P9-4.1 covers reads (task/batch list+show, next-task, pending
 * fulfillments) with auth/permission/scope/contract proof. Commands append
 * in P9-4.2 (tasks) and P9-4.3 (batches). Sequential only (shared MySQL).
 */
class WmsPickingBatchTest extends TestCase
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
            'picking-execute', 'fulfillment-override', 'batch.manage', 'batch.operate',
            'manage-warehouse',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouseA = Warehouse::create([
            'code' => 'WH-PA', 'name' => 'Pick A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-PB', 'name' => 'Pick B', 'status' => 'active', 'is_default' => false,
        ]);

        $location = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'PA-01',
            'barcode' => 'PA-LOC-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $locationB = Location::create([
            'warehouse_id' => $this->warehouseB->id, 'code' => 'PB-01',
            'barcode' => 'PB-LOC-01', 'name' => 'Bin B', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'PP', 'slug' => 'pp-' . uniqid(), 'sku' => 'SKU-PICK-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouseA->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $locationB->id,
            'warehouse_id' => $this->warehouseB->id, 'quantity' => 50, 'allocated_hint' => 0,
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

    private function picker(?int $home = null): User
    {
        return $this->user($home ?? $this->warehouseA->id, [
            'view-warehouse', 'view-location', 'view-fulfillment', 'picking-execute',
        ]);
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'PO', 'user_email' => $user->email,
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
            'product_id' => $this->product->id, 'product_name' => 'PP',
            'product_sku' => 'SKU-PICK-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        return $order->refresh();
    }

    private function releaseIn(int $warehouseId, ?string $key = null): Fulfillment
    {
        return app(FulfillmentService::class)->releaseForOrder(
            $this->makeOrder(), $warehouseId, $key ?? ('pb-' . uniqid())
        );
    }

    // ---------- P9-4.1 reads ----------

    public function test_unauthenticated_reads_return_401(): void
    {
        $this->getJson('/api/v1/admin/picking-tasks')->assertStatus(401);
        $this->getJson('/api/v1/admin/picking-tasks/1')->assertStatus(401);
        $this->getJson('/api/v1/admin/batches')->assertStatus(401);
        $this->getJson('/api/v1/admin/batches/1')->assertStatus(401);
        $this->getJson('/api/v1/admin/batches/1/next-task')->assertStatus(401);
        $this->getJson('/api/v1/admin/batches/pending-fulfillments')->assertStatus(401);
    }

    public function test_task_reads_require_picking_execute_while_batch_reads_ride_on_view(): void
    {
        // Viewer holds view-fulfillment only: batch reads allowed, task
        // reads denied. Locks the §R permission split.
        $viewer = $this->user($this->warehouseA->id, ['view-fulfillment']);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/admin/batches')->assertStatus(200);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/admin/picking-tasks')->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/admin/picking-tasks/1')->assertStatus(403);
    }

    public function test_task_index_scopes_to_home_and_supports_filters(): void
    {
        $fulfillmentA = $this->releaseIn($this->warehouseA->id, 'tia');
        app(OrderPickingService::class)->createTasksForFulfillment($fulfillmentA);
        $fulfillmentB = $this->releaseIn($this->warehouseB->id, 'tib');
        app(OrderPickingService::class)->createTasksForFulfillment($fulfillmentB);

        $picker = $this->picker();
        $response = $this->actingAs($picker, 'sanctum')->getJson('/api/v1/admin/picking-tasks');
        $response->assertStatus(200)->assertJson(['success' => true]);
        // NOTE: ApiResponse serializes the resource collection as a flat
        // array under `data` (no paginator envelope inside this contract).
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotEmpty($ids);
        // Control: Warehouse B tasks genuinely exist, so their absence proves scoping.
        $this->assertNotEmpty(
            PickingTask::whereHas('fulfillmentItem.fulfillment', fn ($q) => $q->where('warehouse_id', $this->warehouseB->id))->pluck('id')->all()
        );
        $this->assertSame(
            [],
            PickingTask::whereIn('id', $ids)
                ->whereHas('fulfillmentItem.fulfillment', fn ($q) => $q->where('warehouse_id', $this->warehouseB->id))
                ->pluck('id')->all(),
            'no Warehouse B task may leak into a Warehouse A listing'
        );

        // mine=true returns only self-claimed tasks.
        $task = PickingTask::whereHas(
            'fulfillmentItem.fulfillment',
            fn ($q) => $q->where('warehouse_id', $this->warehouseA->id)
        )->firstOrFail();
        app(\App\Services\Fulfillment\PickingExecutionService::class)
            ->claim($task, $picker->id);
        $mine = $this->actingAs($picker, 'sanctum')->getJson('/api/v1/admin/picking-tasks?mine=1');
        $mine->assertStatus(200);
        $this->assertSame([$task->id], collect($mine->json('data'))->pluck('id')->all());

        // status + fulfillment filters.
        $this->actingAs($picker, 'sanctum')
            ->getJson("/api/v1/admin/picking-tasks?fulfillment_id={$fulfillmentA->id}&status=assigned")
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $task->id]);
    }

    public function test_task_show_cross_warehouse_returns_404_with_detail_contract(): void
    {
        $fulfillmentB = $this->releaseIn($this->warehouseB->id, 'tsb');
        $taskB = app(OrderPickingService::class)->createTasksForFulfillment($fulfillmentB)[0];
        $picker = $this->picker();

        $this->actingAs($picker, 'sanctum')
            ->getJson("/api/v1/admin/picking-tasks/{$taskB->id}")
            ->assertStatus(404);

        $fulfillmentA = $this->releaseIn($this->warehouseA->id, 'tsa');
        $taskA = app(OrderPickingService::class)->createTasksForFulfillment($fulfillmentA)[0];
        $response = $this->actingAs($picker, 'sanctum')
            ->getJson("/api/v1/admin/picking-tasks/{$taskA->id}");
        $response->assertStatus(200);
        $data = $response->json('data');
        foreach (['remaining', 'claimed_by', 'claim_expires_at', 'scan_log', 'op_seq'] as $key) {
            $this->assertArrayHasKey($key, $data, "task detail missing {$key}");
        }
        $this->assertArrayNotHasKey('allowed_actions', $data);
        $this->assertSame($fulfillmentA->warehouse_id, (int) $data['fulfillment']['warehouse_id']);
    }

    public function test_batch_reads_scope_and_next_task_behaves(): void
    {
        $f1 = $this->releaseIn($this->warehouseA->id, 'tba');
        $f2 = $this->releaseIn($this->warehouseA->id, 'tbb');
        $batch = app(BatchPickingService::class)->createBatchFromFulfillments(
            collect([$f1->fresh(), $f2->fresh()]), $this->warehouseA->id
        );
        $picker = $this->picker();

        $this->actingAs($picker, 'sanctum')->getJson('/api/v1/admin/batches')
            ->assertStatus(200)->assertJsonFragment(['batch_number' => $batch->batch_number]);

        $show = $this->actingAs($picker, 'sanctum')->getJson("/api/v1/admin/batches/{$batch->id}");
        $show->assertStatus(200);
        $data = $show->json('data');
        $this->assertArrayHasKey('tasks', $data);
        $this->assertArrayHasKey('progress', $data);
        $this->assertArrayNotHasKey('allowed_actions', $data);
        $this->assertNotEmpty($data['tasks']);

        $next = $this->actingAs($picker, 'sanctum')
            ->getJson("/api/v1/admin/batches/{$batch->id}/next-task");
        $next->assertStatus(200);
        // Lowest sequence pending task first.
        $this->assertSame(
            $batch->pickingTasks()->orderBy('sequence')->pluck('id')->first(),
            (int) $next->json('data.id')
        );

        // Foreign batch is invisible.
        $fB = $this->releaseIn($this->warehouseB->id, 'tbc');
        $batchB = app(BatchPickingService::class)->createBatchFromFulfillments(
            collect([$fB->fresh()]), $this->warehouseB->id
        );
        $this->actingAs($picker, 'sanctum')
            ->getJson("/api/v1/admin/batches/{$batchB->id}")
            ->assertStatus(404);
        $this->actingAs($picker, 'sanctum')
            ->getJson("/api/v1/admin/batches/{$batchB->id}/next-task")
            ->assertStatus(404);
    }

    public function test_pending_fulfillments_returns_scoped_pending_only(): void
    {
        $this->releaseIn($this->warehouseA->id, 'tpa');
        $picker = $this->picker();

        $response = $this->actingAs($picker, 'sanctum')
            ->getJson('/api/v1/admin/batches/pending-fulfillments');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));

        // Scoped user probing another warehouse → 404, never its rows.
        $this->actingAs($picker, 'sanctum')
            ->getJson("/api/v1/admin/batches/pending-fulfillments?warehouse_id={$this->warehouseB->id}")
            ->assertStatus(404);
    }

    // ---------- P9-4.2 helpers ----------

    private function releaseTask(): \App\Models\Fulfillment\PickingTask
    {
        $fulfillment = $this->releaseIn($this->warehouseA->id);

        return app(OrderPickingService::class)->createTasksForFulfillment($fulfillment)[0];
    }

    private function claimHttp(User $actor, int $taskId, array $extra = [])
    {
        return $this->actingAs($actor, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$taskId}/claim", $extra
        );
    }

    // ---------- P9-4.2 claim / release ----------

    public function test_claim_assigns_caller_and_replay_refreshes(): void
    {
        $task = $this->releaseTask();
        $picker = $this->picker();

        $first = $this->claimHttp($picker, $task->id, ['claimed_by' => 999999, 'actor_id' => 999999]);
        $first->assertStatus(200);
        // Forged actor fields ignored: the authenticated caller wins.
        $this->assertSame($picker->id, (int) $task->fresh()->claimed_by);
        $this->assertSame('assigned', $task->fresh()->status);

        $this->claimHttp($picker, $task->id)->assertStatus(200);
        $this->assertSame($picker->id, (int) $task->fresh()->claimed_by);
    }

    public function test_claim_occupied_returns_409_and_keeps_first_winner(): void
    {
        $task = $this->releaseTask();
        $first = $this->picker();
        $second = $this->user($this->warehouseA->id, ['view-fulfillment', 'picking-execute']);

        $this->claimHttp($first, $task->id)->assertStatus(200);
        $this->claimHttp($second, $task->id)->assertStatus(409);
        $this->assertSame($first->id, (int) $task->fresh()->claimed_by);
    }

    public function test_claim_terminal_task_returns_422(): void
    {
        $task = $this->releaseTask();
        $picker = $this->picker();
        $this->claimHttp($picker, $task->id)->assertStatus(200);
        $this->confirmTaskHttp($picker, $task->id, 2, 1)->assertStatus(200);
        $this->assertSame('picked', $task->fresh()->status);

        $other = $this->user($this->warehouseA->id, ['view-fulfillment', 'picking-execute']);
        $this->claimHttp($other, $task->id)->assertStatus(422);
    }

    public function test_claim_override_requires_permission(): void
    {
        $task = $this->releaseTask();
        $holder = $this->picker();
        $intruder = $this->user($this->warehouseA->id, ['view-fulfillment', 'picking-execute']);
        $supervisor = $this->user($this->warehouseA->id, [
            'view-fulfillment', 'picking-execute', 'fulfillment-override',
        ]);

        $this->claimHttp($holder, $task->id)->assertStatus(200);

        // Intent without permission → 403, never silent elevation.
        $this->claimHttp($intruder, $task->id, ['override' => true])->assertStatus(403);
        $this->assertSame($holder->id, (int) $task->fresh()->claimed_by);

        // Intent with permission → allowed when service state permits.
        $this->claimHttp($supervisor, $task->id, ['override' => true])->assertStatus(200);
        $this->assertSame($supervisor->id, (int) $task->fresh()->claimed_by);
    }

    public function test_claim_cross_warehouse_returns_403_with_state_untouched(): void
    {
        $fulfillmentB = $this->releaseIn($this->warehouseB->id, 'cxb');
        $taskB = app(OrderPickingService::class)->createTasksForFulfillment($fulfillmentB)[0];
        $picker = $this->picker();

        $this->claimHttp($picker, $taskB->id)->assertStatus(403);
        $fresh = $taskB->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->claimed_by);
    }

    public function test_release_returns_task_to_pool_and_forbids_foreign_claims(): void
    {
        $task = $this->releaseTask();
        $picker = $this->picker();
        $other = $this->user($this->warehouseA->id, ['view-fulfillment', 'picking-execute']);

        $this->claimHttp($picker, $task->id)->assertStatus(200);

        // Another worker's claim → 403, claim survives.
        $this->actingAs($other, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/release", []
        )->assertStatus(403);
        $this->assertSame($picker->id, (int) $task->fresh()->claimed_by);

        // Owner releases → pending, claim cleared, progress kept (0 here).
        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/release", []
        )->assertStatus(200);
        $fresh = $task->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->claimed_by);
    }

    public function test_release_terminal_task_is_refused(): void
    {
        $task = $this->releaseTask();
        $picker = $this->picker();
        $this->claimHttp($picker, $task->id)->assertStatus(200);
        $this->confirmTaskHttp($picker, $task->id, 2, 1)->assertStatus(200);

        // Defensive read gate: the service would wipe claim state off
        // completed work; HTTP refuses terminal tasks outright.
        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/release", []
        )->assertStatus(422);
        $this->assertSame('picked', $task->fresh()->status);
    }

    // ---------- P9-4.2 confirm / record-pick ----------

    private function confirmTaskHttp(User $actor, int $taskId, float $qty, int $opSeq, array $extra = [])
    {
        return $this->actingAs($actor, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$taskId}/confirm",
            array_merge([
                'location' => 'PA-LOC-01', 'product' => 'SKU-PICK-1',
                'quantity' => $qty, 'op_seq' => $opSeq,
            ], $extra)
        );
    }

    public function test_confirm_validates_scan_and_advances_quantities(): void
    {
        $task = $this->releaseTask();
        $picker = $this->picker();
        $this->claimHttp($picker, $task->id)->assertStatus(200);

        // Partial pick keeps picking state.
        $this->confirmTaskHttp($picker, $task->id, 1, 1)->assertStatus(200);
        $fresh = $task->fresh();
        $this->assertSame('picking', $fresh->status);
        $this->assertEquals(1, (float) $fresh->quantity_picked);

        // op_seq replay → same result, no duplicate mutation.
        $this->confirmTaskHttp($picker, $task->id, 1, 1)->assertStatus(200);
        $this->assertEquals(1, (float) $task->fresh()->quantity_picked);

        // Completing pick → picked.
        $this->confirmTaskHttp($picker, $task->id, 1, 2)->assertStatus(200);
        $this->assertSame('picked', $task->fresh()->status);
        $this->assertEquals(2, (float) $task->fresh()->fulfillmentItem->quantity_picked);
    }

    public function test_confirm_rejects_bad_scans_and_unclaimed_tasks(): void
    {
        $task = $this->releaseTask();
        $picker = $this->picker();

        // Unclaimed without override → 422, nothing mutated.
        $this->confirmTaskHttp($picker, $task->id, 2, 1)->assertStatus(422);
        $this->assertEquals(0, (float) $task->fresh()->quantity_picked);

        $this->claimHttp($picker, $task->id)->assertStatus(200);

        // Wrong location / over-pick → 422.
        $this->confirmTaskHttp($picker, $task->id, 2, 1, ['location' => 'NOPE'])->assertStatus(422);
        $this->confirmTaskHttp($picker, $task->id, 99, 1)->assertStatus(422);
        $this->assertEquals(0, (float) $task->fresh()->quantity_picked);

        // Unknown product barcode → 422 via UnknownBarcodeException mapping.
        $this->confirmTaskHttp($picker, $task->id, 1, 1, ['product' => 'NOPE'])->assertStatus(422);
    }

    public function test_confirm_cross_warehouse_returns_403(): void
    {
        $fulfillmentB = $this->releaseIn($this->warehouseB->id, 'cfb');
        $taskB = app(OrderPickingService::class)->createTasksForFulfillment($fulfillmentB)[0];
        $picker = $this->picker();

        $this->confirmTaskHttp($picker, $taskB->id, 1, 1)->assertStatus(403);
        $this->assertEquals(0, (float) $taskB->fresh()->quantity_picked);
    }

    public function test_record_pick_guards_claimant_and_remaining(): void
    {
        $task = $this->releaseTask();
        $picker = $this->picker();
        $other = $this->user($this->warehouseA->id, ['view-fulfillment', 'picking-execute']);
        $this->claimHttp($picker, $task->id)->assertStatus(200);

        // Non-claimant without override → 422 (service claimant rule).
        $this->actingAs($other, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/record-pick", ['quantity' => 1]
        )->assertStatus(422);

        // Over-pick → 422.
        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/record-pick", ['quantity' => 99]
        )->assertStatus(422);

        // Valid partial → 200 picking; additive, never idempotent-by-flag.
        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/record-pick",
            ['quantity' => 1, 'notes' => 'torn carton']
        )->assertStatus(200);
        $this->assertSame('picking', $task->fresh()->status);

        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/record-pick", ['quantity' => 1]
        )->assertStatus(200);
        $this->assertSame('picked', $task->fresh()->status);
    }

    // ---------- P9-4.2 skip / reallocate / create-tasks / complete / placement ----------

    public function test_skip_requires_reason_and_refuses_terminal(): void
    {
        $task = $this->releaseTask();
        $picker = $this->picker();

        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/skip", ['reason' => ' ']
        )->assertStatus(422);
        $this->assertSame('pending', $task->fresh()->status);

        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/skip", ['reason' => 'damaged stock']
        )->assertStatus(200);
        $this->assertSame('skipped', $task->fresh()->status);

        // Terminal skip → 422.
        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/skip", ['reason' => 'again']
        )->assertStatus(422);
    }

    public function test_skip_cross_warehouse_returns_403(): void
    {
        $fulfillmentB = $this->releaseIn($this->warehouseB->id, 'skb');
        $taskB = app(OrderPickingService::class)->createTasksForFulfillment($fulfillmentB)[0];
        $picker = $this->picker();

        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$taskB->id}/skip", ['reason' => 'hijack']
        )->assertStatus(403);
        $this->assertSame('pending', $taskB->fresh()->status);
    }

    public function test_reallocate_moves_within_warehouse_and_refuses_foreign_target(): void
    {
        $fulfillment = $this->releaseIn($this->warehouseA->id, 'real');
        $task = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment)[0];
        $picker = $this->picker();

        $loc2 = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'PA-02',
            'barcode' => 'PA-LOC-02', 'name' => 'Bin 2', 'type' => 'picking', 'status' => 'active',
        ]);
        $target = ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $loc2->id,
            'warehouse_id' => $this->warehouseA->id, 'quantity' => 30, 'allocated_hint' => 0,
        ]);

        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/reallocate",
            ['product_location_id' => $target->id]
        )->assertStatus(200);
        $this->assertSame($target->id, (int) $task->fresh()->product_location_id);

        // Cross-warehouse target refused by domain guard → 422.
        $foreign = ProductLocation::where('warehouse_id', $this->warehouseB->id)->firstOrFail();
        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$task->id}/reallocate",
            ['product_location_id' => $foreign->id]
        )->assertStatus(422);
        $this->assertSame($target->id, (int) $task->fresh()->product_location_id);
    }

    public function test_create_tasks_reuses_and_scopes_by_fulfillment(): void
    {
        $picker = $this->picker();
        $fulfillment = $this->releaseIn($this->warehouseA->id, 'ct1');
        $response = $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        );
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));

        // Replay reuses the same open tasks (service idempotency).
        $again = $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        );
        $again->assertStatus(200);
        $this->assertSame(
            collect($response->json('data'))->pluck('id')->sort()->values()->all(),
            collect($again->json('data'))->pluck('id')->sort()->values()->all()
        );

        // Foreign fulfillment → 403, no tasks created there.
        $foreign = $this->releaseIn($this->warehouseB->id, 'ct2');
        $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$foreign->id}/create-tasks", []
        )->assertStatus(403);
        $this->assertSame(
            0,
            \App\Models\Fulfillment\PickingTask::whereHas(
                'fulfillmentItem',
                fn ($q) => $q->where('fulfillment_id', $foreign->id)
            )->count()
        );
    }

    public function test_complete_picking_advances_only_when_fully_picked(): void
    {
        $fulfillment = $this->releaseIn($this->warehouseA->id, 'cp1');
        $tasks = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment);
        $manager = $this->user($this->warehouseA->id, ['view-fulfillment', 'manage-fulfillment']);

        // Incomplete → 422, state untouched.
        $this->actingAs($manager, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/complete-picking", []
        )->assertStatus(422);
        $this->assertSame('picking', $fulfillment->fresh()->status);

        // Pick everything through the canonical confirm path, then complete.
        $picker = $this->picker();
        foreach ($tasks as $task) {
            $this->claimHttp($picker, $task->id)->assertStatus(200);
            $this->confirmTaskHttp($picker, $task->id, 2, 1)->assertStatus(200);
        }
        $this->actingAs($manager, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/complete-picking", []
        )->assertStatus(200);
        $this->assertSame('picked', $fulfillment->fresh()->status);

        // Cross-warehouse → 403.
        $foreign = $this->releaseIn($this->warehouseB->id, 'cp2');
        $this->actingAs($manager, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$foreign->id}/complete-picking", []
        )->assertStatus(403);
    }

    public function test_assign_placement_pins_item_and_scopes_by_item_warehouse(): void
    {
        // Product without any placement → NULL-allocation item.
        $bare = Product::create([
            'name' => 'PB2', 'slug' => 'pb2-' . uniqid(), 'sku' => 'SKU-PICK-2',
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 20, 'reserved_quantity' => 0,
        ]);
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'PO2', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 50, 'total_price' => 50, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $bare->id, 'product_name' => 'PB2', 'product_sku' => 'SKU-PICK-2',
            'product_quantity' => 1, 'product_price' => 50, 'product_total_price' => 50,
        ]);
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $this->warehouseA->id, 'ap1');
        $item = $fulfillment->items()->firstOrFail();
        $this->assertNull($item->product_location_id);

        $placement = ProductLocation::where('warehouse_id', $this->warehouseA->id)
            ->where('product_id', $this->product->id)->firstOrFail();
        $manager = $this->user($this->warehouseA->id, ['view-fulfillment', 'manage-fulfillment']);

        // Wrong-product target → 422.
        $this->actingAs($manager, 'sanctum')->postJson(
            "/api/v1/admin/fulfillment-items/{$item->id}/assign-placement",
            ['product_location_id' => $placement->id]
        )->assertStatus(422);

        // Correct same-product placement → 200 pinned.
        $own = ProductLocation::create([
            'product_id' => $bare->id,
            'location_id' => Location::where('warehouse_id', $this->warehouseA->id)->firstOrFail()->id,
            'warehouse_id' => $this->warehouseA->id, 'quantity' => 20, 'allocated_hint' => 0,
        ]);
        $this->actingAs($manager, 'sanctum')->postJson(
            "/api/v1/admin/fulfillment-items/{$item->id}/assign-placement",
            ['product_location_id' => $own->id]
        )->assertStatus(200);
        $this->assertSame($own->id, (int) $item->fresh()->product_location_id);
    }

    // ---------- P9-4.3 helpers ----------

    private function batchOperator(?int $home = null): User
    {
        return $this->user($home ?? $this->warehouseA->id, [
            'view-fulfillment', 'batch.manage', 'batch.operate', 'picking-execute',
        ]);
    }

    private function createBatchHttp(User $actor, array $payload)
    {
        return $this->actingAs($actor, 'sanctum')->postJson('/api/v1/admin/batches', $payload);
    }

    private function releasePair(string $suffix): array
    {
        $f1 = $this->releaseIn($this->warehouseA->id, "bp{$suffix}a");
        $f2 = $this->releaseIn($this->warehouseA->id, "bp{$suffix}b");

        return [$f1->fresh(), $f2->fresh()];
    }

    // ---------- P9-4.3 batch auth ----------

    public function test_unauthenticated_batch_commands_return_401(): void
    {
        $this->postJson('/api/v1/admin/batches', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/batches/1/assign', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/batches/1/start', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/batches/1/cancel', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/batches/1/retry', [])->assertStatus(401);
    }

    public function test_batch_commands_enforce_lifecycle_vs_execution_split(): void
    {
        [$f1, $f2] = $this->releasePair('x');
        // Picker holds picking-execute only: all batch commands denied.
        $picker = $this->picker();
        $payload = ['fulfillment_ids' => [$f1->id, $f2->id]];

        $this->createBatchHttp($picker, $payload)->assertStatus(403);
        $this->actingAs($picker, 'sanctum')->postJson('/api/v1/admin/batches/1/assign', ['user_id' => $picker->id])->assertStatus(403);
        $this->actingAs($picker, 'sanctum')->postJson('/api/v1/admin/batches/1/start', [])->assertStatus(403);
        $this->actingAs($picker, 'sanctum')->postJson('/api/v1/admin/batches/1/cancel', ['reason' => 'x'])->assertStatus(403);
        $this->actingAs($picker, 'sanctum')->postJson('/api/v1/admin/batches/1/retry', ['reason' => 'x'])->assertStatus(403);
        $this->assertSame(0, \App\Models\Fulfillment\FulfillmentBatch::count());
    }

    // ---------- P9-4.3 create ----------

    public function test_batch_create_builds_tasks_and_advances_fulfillments(): void
    {
        [$f1, $f2] = $this->releasePair('c');
        $operator = $this->batchOperator();

        $response = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]]);
        $response->assertStatus(201)->assertJson(['success' => true]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['tasks']);
        $this->assertSame('pending', $data['status']);
        $this->assertArrayNotHasKey('allowed_actions', $data);
        $this->assertSame('picking', $f1->fresh()->status);
        $this->assertSame('picking', $f2->fresh()->status);
    }

    public function test_batch_create_rejects_scope_mix_with_no_partial_state(): void
    {
        $fA = $this->releaseIn($this->warehouseA->id, 'mxa');
        $fB = $this->releaseIn($this->warehouseB->id, 'mxb');
        $operator = $this->batchOperator();

        $this->createBatchHttp($operator, ['fulfillment_ids' => [$fA->id, $fB->id]])
            ->assertStatus(422);

        $this->assertSame(0, \App\Models\Fulfillment\FulfillmentBatch::count());
        $this->assertSame('pending', $fA->fresh()->status);
        $this->assertSame('pending', $fB->fresh()->status);
    }

    public function test_batch_create_into_foreign_warehouse_returns_403(): void
    {
        [$f1, $f2] = $this->releasePair('f');
        $operator = $this->batchOperator($this->warehouseA->id);

        $this->createBatchHttp($operator, [
            'fulfillment_ids' => [$f1->id, $f2->id],
            'warehouse_id' => $this->warehouseB->id,
        ])->assertStatus(403);

        $this->assertSame(0, \App\Models\Fulfillment\FulfillmentBatch::count());
    }

    public function test_batch_create_validates_input_and_is_not_idempotent(): void
    {
        $operator = $this->batchOperator();

        // Empty / unknown / duplicate / non-wave type → 422.
        $this->createBatchHttp($operator, ['fulfillment_ids' => []])->assertStatus(422);
        $this->createBatchHttp($operator, ['fulfillment_ids' => [999999]])->assertStatus(422);
        [$f1, $f2] = $this->releasePair('v');
        $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f1->id]])->assertStatus(422);
        $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id], 'type' => 'bulk'])->assertStatus(422);

        // Documented: no idempotency key — double POST creates two batches.
        $r1 = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]]);
        $r1->assertStatus(201);
        // Second POST needs fresh fulfillments (first batch moved them on).
        [$g1, $g2] = $this->releasePair('v2');
        $r2 = $this->createBatchHttp($operator, ['fulfillment_ids' => [$g1->id, $g2->id]]);
        $r2->assertStatus(201);
        $this->assertNotSame($r1->json('data.id'), $r2->json('data.id'));
        $this->assertSame(2, \App\Models\Fulfillment\FulfillmentBatch::count());
    }

    // ---------- P9-4.3 assign / start ----------

    public function test_batch_assign_first_winner_and_start_guards_state(): void
    {
        [$f1, $f2] = $this->releasePair('a');
        $operator = $this->batchOperator();
        $other = $this->user($this->warehouseA->id, []);
        $batchId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]])
            ->assertStatus(201)->json('data.id');

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $operator->id]
        )->assertStatus(200);
        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $other->id]
        )->assertStatus(409);

        // Start pending (unassigned) batch → 422 tested on a second batch.
        [$h1, $h2] = $this->releasePair('a2');
        $pendingId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$h1->id, $h2->id]])
            ->assertStatus(201)->json('data.id');
        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$pendingId}/start", []
        )->assertStatus(422);

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchId}/start", []
        )->assertStatus(200);
        $this->assertSame('picking', \App\Models\Fulfillment\FulfillmentBatch::find($batchId)->status);
    }

    public function test_batch_assign_cross_warehouse_returns_403(): void
    {
        $fB = $this->releaseIn($this->warehouseB->id, 'axb');
        $batchB = app(BatchPickingService::class)->createBatchFromFulfillments(
            collect([$fB->fresh()]), $this->warehouseB->id
        );
        $operator = $this->batchOperator($this->warehouseA->id);

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchB->id}/assign", ['user_id' => $operator->id]
        )->assertStatus(403);
        $this->assertNull($batchB->fresh()->assigned_to);
    }

    // ---------- P9-4.3 cancel / retry ----------

    public function test_batch_cancel_skips_open_tasks_and_refuses_terminal(): void
    {
        [$f1, $f2] = $this->releasePair('k');
        $operator = $this->batchOperator();
        $batchId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]])
            ->assertStatus(201)->json('data.id');

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchId}/cancel", ['reason' => ' ']
        )->assertStatus(422);

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchId}/cancel", ['reason' => 'line down']
        )->assertStatus(200);

        $batch = \App\Models\Fulfillment\FulfillmentBatch::find($batchId);
        $this->assertSame('cancelled', $batch->status);
        $this->assertSame(
            0,
            $batch->pickingTasks()->whereIn('status', ['pending', 'assigned', 'picking'])->count()
        );
    }

    public function test_batch_cancel_completed_refused_and_cross_warehouse_denied(): void
    {
        [$f1, $f2] = $this->releasePair('kc');
        $operator = $this->batchOperator();
        $batchId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]])
            ->assertStatus(201)->json('data.id');

        // Drive every task to picked through the canonical record path.
        $picker = $this->picker();
        foreach (\App\Models\Fulfillment\FulfillmentBatch::find($batchId)->pickingTasks as $task) {
            app(\App\Services\Fulfillment\PickingExecutionService::class)->claim($task, $picker->id);
            app(BatchPickingService::class)->recordPick($task->fresh(), (float) $task->quantity_to_pick, null, $picker->id);
        }
        $this->assertSame('completed', \App\Models\Fulfillment\FulfillmentBatch::find($batchId)->status);

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchId}/cancel", ['reason' => 'too late']
        )->assertStatus(422);

        // Cross-warehouse cancel → 403, state untouched.
        $fB = $this->releaseIn($this->warehouseB->id, 'kcb');
        $batchB = app(BatchPickingService::class)->createBatchFromFulfillments(
            collect([$fB->fresh()]), $this->warehouseB->id
        );
        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchB->id}/cancel", ['reason' => 'hijack']
        )->assertStatus(403);
        $this->assertSame('pending', $batchB->fresh()->status);
    }

    public function test_batch_retry_replaces_skipped_and_conflicts_on_open_task(): void
    {
        [$f1, $f2] = $this->releasePair('r');
        $operator = $this->batchOperator();
        $batchId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]])
            ->assertStatus(201)->json('data.id');

        // No skipped rows at all → empty 200 (nothing to do, still success).
        $empty = $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchId}/retry", ['reason' => 'nothing skipped']
        );
        $empty->assertStatus(200);
        $this->assertSame([], $empty->json('data'));

        $batch = \App\Models\Fulfillment\FulfillmentBatch::find($batchId);
        $victim = $batch->pickingTasks()->orderBy('id')->firstOrFail();

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/picking-tasks/{$victim->id}/skip", ['reason' => 'damaged']
        )->assertStatus(200);

        $retry = $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchId}/retry", ['reason' => 'restocked']
        );
        $retry->assertStatus(200);
        $this->assertCount(1, $retry->json('data'));
        $this->assertSame('skipped', $victim->fresh()->status);

        // Repeat retry is NOT a safe replay: the skipped row persists and
        // its item now holds the open replacement → loud 409, no duplication.
        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchId}/retry", ['reason' => 'again']
        )->assertStatus(409);
        // Untouched sibling + the single replacement: exactly 2 pending,
        // i.e. the refused retry created nothing.
        $this->assertSame(2, \App\Models\Fulfillment\PickingTask::where('batch_id', $batchId)
            ->where('status', 'pending')->count());
    }

    public function test_batch_commands_leave_orders_and_inventory_untouched(): void
    {
        [$f1, $f2] = $this->releasePair('inv');
        $orderIds = [$f1->order_id, $f2->order_id];
        $before = Order::whereIn('id', $orderIds)->get()->mapWithKeys(
            fn ($o) => [$o->id => $o->only(['status', 'payment_status', 'inventory_state'])]
        )->all();
        $stockBefore = [(float) $this->product->fresh()->stock_quantity, (float) $this->product->fresh()->reserved_quantity];
        $operator = $this->batchOperator();

        $batchId = $this->createBatchHttp($operator, ['fulfillment_ids' => [$f1->id, $f2->id]])
            ->assertStatus(201)->json('data.id');
        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $operator->id]
        )->assertStatus(200);
        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/batches/{$batchId}/cancel", ['reason' => 'audit']
        )->assertStatus(200);

        foreach (Order::whereIn('id', $orderIds)->get() as $order) {
            $this->assertSame($before[$order->id]['status'], $order->status);
            $this->assertSame($before[$order->id]['payment_status'], $order->payment_status);
            $this->assertSame($before[$order->id]['inventory_state'], $order->inventory_state);
        }
        $product = $this->product->fresh();
        $this->assertSame($stockBefore, [(float) $product->stock_quantity, (float) $product->reserved_quantity]);
    }
}
