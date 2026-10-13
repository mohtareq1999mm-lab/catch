<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\Location;
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

/**
 * Grouped picking workflow hardening: idempotent batch creation,
 * privileged reassignment, strict completion, batch-membership filter,
 * batch-number collision retry, single-aggregate advancement.
 */
class WmsGroupedPickingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'view-location', 'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'batch.manage', 'batch.operate', 'manage-warehouse',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-GP', 'name' => 'Grouped', 'status' => 'active', 'is_default' => true,
        ]);

        $location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'GP-01',
            'barcode' => 'GP-LOC-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'GP', 'slug' => 'gp-'.uniqid(), 'sku' => 'SKU-GP-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 500, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 500, 'allocated_hint' => 0,
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

    private function operator(): User
    {
        return $this->user($this->warehouse->id, [
            'view-fulfillment', 'batch.manage', 'batch.operate', 'picking-execute', 'manage-fulfillment',
        ]);
    }

    private function makeOrder(): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'GO', 'user_email' => $customer->email,
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
            'product_id' => $this->product->id, 'product_name' => 'GP',
            'product_sku' => 'SKU-GP-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        return $order->refresh();
    }

    private function release(string $suffix): \App\Models\Fulfillment\Fulfillment
    {
        return app(FulfillmentService::class)->releaseForOrder(
            $this->makeOrder(), $this->warehouse->id, 'gp-'.$suffix.'-'.uniqid()
        );
    }

    public function test_idempotent_batch_creation_returns_same_batch(): void
    {
        $op = $this->operator();
        $f1 = $this->release('a');
        $f2 = $this->release('b');

        $payload = [
            'fulfillment_ids' => [$f1->id, $f2->id],
            'warehouse_id' => $this->warehouse->id,
            'idempotency_key' => 'wave-key-1',
        ];

        $first = $this->actingAs($op, 'sanctum')->postJson('/api/v1/admin/batches', $payload);
        $first->assertStatus(201)->assertJson(['success' => true]);
        $batchId = $first->json('data.id');
        $this->assertNotEmpty($batchId);

        $second = $this->actingAs($op, 'sanctum')->postJson('/api/v1/admin/batches', $payload);
        $second->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame($batchId, $second->json('data.id'));
        $this->assertSame(1, FulfillmentBatch::where('idempotency_key', 'wave-key-1')->count());
    }

    public function test_batch_completion_rejects_while_incomplete(): void
    {
        $op = $this->operator();
        $f1 = $this->release('c');
        $f2 = $this->release('d');

        $created = $this->actingAs($op, 'sanctum')->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$f1->id, $f2->id],
            'warehouse_id' => $this->warehouse->id,
        ]);
        $created->assertStatus(201);
        $batchId = $created->json('data.id');

        // Strict complete must refuse while tasks are still pending.
        $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/complete")
            ->assertStatus(422);

        // Lenient refresh-progress stays a no-op probe (does not complete).
        $probe = $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/refresh-progress");
        $probe->assertStatus(200);
        $this->assertNotSame('completed', $probe->json('data.status'));
    }

    public function test_batch_reassignment_requires_explicit_flag_and_authority(): void
    {
        $op = $this->operator();
        $f1 = $this->release('e');
        $f2 = $this->release('f');

        $created = $this->actingAs($op, 'sanctum')->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$f1->id, $f2->id],
            'warehouse_id' => $this->warehouse->id,
        ]);
        $batchId = $created->json('data.id');

        $workerA = $this->user($this->warehouse->id, ['view-fulfillment', 'picking-execute']);
        $workerB = $this->user($this->warehouse->id, ['view-fulfillment', 'picking-execute']);

        // First assignment wins.
        $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $workerA->id])
            ->assertStatus(200);

        // Takeover without flag → 409.
        $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $workerB->id])
            ->assertStatus(409);

        // Operator without batch.manage cannot reassign even with flag.
        $narrow = $this->user($this->warehouse->id, ['view-fulfillment', 'batch.operate']);
        $this->actingAs($narrow, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $workerB->id, 'reassign' => true])
            ->assertStatus(403);

        // Privileged reassignment succeeds.
        $reassigned = $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $workerB->id, 'reassign' => true]);
        $reassigned->assertStatus(200)->assertJsonFragment(['assigned_to' => $workerB->id]);
    }

    public function test_fulfillment_index_supports_batch_membership_filter(): void
    {
        $op = $this->operator();
        $f1 = $this->release('g');
        $f2 = $this->release('h');
        $outsider = $this->release('i');

        $created = $this->actingAs($op, 'sanctum')->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$f1->id, $f2->id],
            'warehouse_id' => $this->warehouse->id,
        ]);
        $batchId = $created->json('data.id');

        $response = $this->actingAs($op, 'sanctum')
            ->getJson("/api/v1/admin/fulfillments?batch_id={$batchId}");
        $response->assertStatus(200);
        $ids = collect($response->json('data.data') ?? $response->json('data'))->pluck('id')->all();
        $this->assertContains($f1->id, $ids);
        $this->assertContains($f2->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    public function test_service_idempotency_without_http(): void
    {
        $f1 = $this->release('j');
        $svc = app(BatchPickingService::class);
        $first = $svc->createBatchFromFulfillments(collect([$f1]), $this->warehouse->id, 'wave', 'svc-key-1');
        // Fulfillment is no longer pending, so a fresh creation would refuse;
        // the replay must return the original batch instead.
        $second = $svc->createBatchFromFulfillments(collect([$f1]), $this->warehouse->id, 'wave', 'svc-key-1');
        $this->assertSame($first->id, $second->id);
    }

    public function test_cross_warehouse_idempotency_key_is_rejected(): void
    {
        $f1 = $this->release('k');
        $svc = app(BatchPickingService::class);
        $other = \App\Models\Fulfillment\Warehouse::create([
            'code' => 'WH-GP2', 'name' => 'Grouped 2', 'status' => 'active', 'is_default' => false,
        ]);
        $svc->createBatchFromFulfillments(collect([$f1]), $this->warehouse->id, 'wave', 'x-wh-key');

        $this->expectException(\RuntimeException::class);
        $svc->createBatchFromFulfillments(collect([$f1]), $other->id, 'wave', 'x-wh-key');
    }

    public function test_cancelled_batch_never_auto_completes_on_refresh(): void
    {
        $op = $this->operator();
        $f1 = $this->release('l');
        $created = $this->actingAs($op, 'sanctum')->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$f1->id],
            'warehouse_id' => $this->warehouse->id,
        ]);
        $batchId = $created->json('data.id');

        $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/cancel", ['reason' => 'no longer needed'])
            ->assertStatus(200);

        $probe = $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/refresh-progress");
        $probe->assertStatus(200);
        $this->assertSame('cancelled', $probe->json('data.status'));

        $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/complete")
            ->assertStatus(422);
    }

    public function test_strict_complete_finishes_batch_and_is_idempotent(): void
    {
        $op = $this->operator();
        $f1 = $this->release('n');
        $created = $this->actingAs($op, 'sanctum')->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$f1->id],
            'warehouse_id' => $this->warehouse->id,
        ]);
        $batchId = $created->json('data.id');

        $worker = $this->user($this->warehouse->id, ['view-fulfillment', 'picking-execute']);
        $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $worker->id])
            ->assertStatus(200);
        $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/start", [])
            ->assertStatus(200);

        $exec = app(\App\Services\Fulfillment\PickingExecutionService::class);
        foreach (\App\Models\Fulfillment\FulfillmentBatch::find($batchId)->pickingTasks as $task) {
            $exec->claim($task, $worker->id);
            $exec->confirm($task->fresh(), [
                'location' => 'GP-LOC-01', 'product' => 'SKU-GP-1',
                'quantity' => (float) $task->quantity_to_pick, 'op_seq' => 1,
            ], $worker->id);
        }
        app(BatchPickingService::class)->refreshBatchProgress(
            \App\Models\Fulfillment\FulfillmentBatch::find($batchId)
        );

        $done = $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/complete");
        $done->assertStatus(200)->assertJsonFragment(['status' => 'completed']);

        // Idempotent replay stays completed.
        $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/complete")
            ->assertStatus(200)->assertJsonFragment(['status' => 'completed']);
    }

    public function test_cross_warehouse_assignee_is_rejected(): void
    {
        $op = $this->operator();
        $f1 = $this->release('m');
        $created = $this->actingAs($op, 'sanctum')->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$f1->id],
            'warehouse_id' => $this->warehouse->id,
        ]);
        $batchId = $created->json('data.id');

        $foreignWarehouse = \App\Models\Fulfillment\Warehouse::create([
            'code' => 'WH-GP3', 'name' => 'Grouped foreign', 'status' => 'active', 'is_default' => false,
        ]);
        $foreignWorker = $this->user($foreignWarehouse->id, ['view-fulfillment', 'picking-execute']);
        $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $foreignWorker->id])
            ->assertStatus(422);
    }

    public function test_batch_numbers_are_unique_and_match_format(): void
    {
        $svc = app(BatchPickingService::class);
        $numbers = [];
        for ($i = 0; $i < 10; $i++) {
            $f = $this->release('uniq-'.$i);
            $batch = $svc->createBatchFromFulfillments(collect([$f]), $this->warehouse->id);
            $numbers[] = $batch->batch_number;
            $this->assertMatchesRegularExpression('/^BATCH-\d{8}-[A-Z0-9]{6}$/', $batch->batch_number);
        }
        $this->assertCount(10, array_unique($numbers), 'rapid batch creation must mint distinct numbers');
    }

    public function test_batch_number_collision_retries_without_partial_state(): void
    {
        $taken = 'BATCH-20260101-TAKEN1';
        FulfillmentBatch::create([
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => $taken,
            'status' => 'cancelled',
            'type' => 'wave',
            'total_items' => 0,
            'picked_items' => 0,
        ]);

        $svc = new class(
            new \App\Services\Fulfillment\FulfillmentTransition(),
            app(\App\Services\Warehouse\WarehouseService::class)
        ) extends BatchPickingService {
            /** @var string[] */
            public static array $forced = [];

            protected function generateBatchNumber(): string
            {
                $next = array_shift(self::$forced);

                return $next ?? parent::generateBatchNumber();
            }
        };
        $svc::$forced = [$taken, 'BATCH-20260101-FREE22'];

        $f = $this->release('collide');
        $batch = $svc->createBatchFromFulfillments(collect([$f]), $this->warehouse->id);

        // First candidate collided and was discarded; the retry won.
        $this->assertSame('BATCH-20260101-FREE22', $batch->batch_number);
        $this->assertSame(1, FulfillmentBatch::where('batch_number', $taken)->count());
        $this->assertSame(1, FulfillmentBatch::where('batch_number', 'BATCH-20260101-FREE22')->count());
        // No partial state: exactly one task set, fulfillment advanced once.
        $this->assertGreaterThan(0, $batch->pickingTasks()->count());
        $this->assertSame('picking', $f->fresh()->status);
    }

    public function test_batch_number_collision_with_new_key_still_creates(): void
    {
        $taken = 'BATCH-20260101-TAKEN2';
        FulfillmentBatch::create([
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => $taken,
            'status' => 'cancelled',
            'type' => 'wave',
            'total_items' => 0,
            'picked_items' => 0,
        ]);

        $svc = new class(
            new \App\Services\Fulfillment\FulfillmentTransition(),
            app(\App\Services\Warehouse\WarehouseService::class)
        ) extends BatchPickingService {
            /** @var string[] */
            public static array $forced = [];

            protected function generateBatchNumber(): string
            {
                $next = array_shift(self::$forced);

                return $next ?? parent::generateBatchNumber();
            }
        };
        $svc::$forced = [$taken, 'BATCH-20260101-FREE33'];

        // Same number collision as the keyless test, but with a FRESH
        // idempotency key: must create (never replay — the key is unbound).
        $f = $this->release('collide-keyed');
        $batch = $svc->createBatchFromFulfillments(collect([$f]), $this->warehouse->id, 'wave', 'brand-new-key-7');

        $this->assertSame('BATCH-20260101-FREE33', $batch->batch_number);
        $this->assertSame('brand-new-key-7', $batch->idempotency_key);
        $this->assertSame(1, FulfillmentBatch::where('idempotency_key', 'brand-new-key-7')->count());
        $this->assertGreaterThan(0, $batch->pickingTasks()->count());
    }

    public function test_insert_batch_row_signals_idempotency_race_for_replay(): void
    {
        $f = $this->release('race');
        $svc = app(BatchPickingService::class);
        $first = $svc->createBatchFromFulfillments(collect([$f]), $this->warehouse->id, 'wave', 'race-key-9');

        // Direct row insert with a bound key simulates the loser of a
        // concurrent same-key race: it must signal replay (null), never a
        // second batch row.
        $method = new \ReflectionMethod(BatchPickingService::class, 'insertBatchRow');
        $method->setAccessible(true);
        $this->assertNull($method->invoke($svc, $this->warehouse->id, 'wave', 'race-key-9'));
        $this->assertSame(1, FulfillmentBatch::where('idempotency_key', 'race-key-9')->count());
        $this->assertSame($first->id, FulfillmentBatch::where('idempotency_key', 'race-key-9')->first()->id);
    }

    public function test_advancement_uses_single_aggregate_query(): void
    {
        $op = $this->operator();
        $f1 = $this->release('adv1');
        $f2 = $this->release('adv2');
        $f3 = $this->release('adv3');

        $created = $this->actingAs($op, 'sanctum')->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$f1->id, $f2->id, $f3->id],
            'warehouse_id' => $this->warehouse->id,
        ]);
        $batchId = $created->json('data.id');

        $worker = $this->user($this->warehouse->id, ['view-fulfillment', 'picking-execute']);
        $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $worker->id])
            ->assertStatus(200);
        $this->actingAs($op, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/start", [])
            ->assertStatus(200);

        $exec = app(\App\Services\Fulfillment\PickingExecutionService::class);
        foreach (FulfillmentBatch::find($batchId)->pickingTasks as $task) {
            $exec->claim($task, $worker->id);
            $exec->confirm($task->fresh(), [
                'location' => 'GP-LOC-01', 'product' => 'SKU-GP-1',
                'quantity' => (float) $task->quantity_to_pick, 'op_seq' => 1,
            ], $worker->id);
        }

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $completed = app(BatchPickingService::class)->completeBatch(
            FulfillmentBatch::find($batchId)
        );
        $log = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Correctness: batch completed and every fulfillment advanced.
        $this->assertSame('completed', $completed->status);
        foreach ([$f1, $f2, $f3] as $f) {
            $this->assertSame('picked', $f->fresh()->status);
        }

        // One grouped incompleteness aggregate, zero per-fulfillment EXISTS.
        $aggregate = 0;
        $existsChecks = 0;
        foreach ($log as $entry) {
            $sql = strtolower($entry['query']);
            if (str_contains($sql, 'fulfillment_items') && str_contains($sql, 'group by')) {
                $aggregate++;
            }
            if (str_contains($sql, 'exists') && str_contains($sql, 'fulfillment_items')) {
                $existsChecks++;
            }
        }
        $this->assertSame(1, $aggregate, 'advancement must issue exactly one grouped aggregate query');
        $this->assertSame(0, $existsChecks, 'advancement must not issue per-fulfillment EXISTS queries');
    }

    public function test_complete_route_authorization_boundaries(): void
    {
        $op = $this->operator();
        $f1 = $this->release('authz');
        $created = $this->actingAs($op, 'sanctum')->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$f1->id],
            'warehouse_id' => $this->warehouse->id,
        ]);
        $batchId = $created->json('data.id');

        // Unauthenticated → 401 (actingAs persists per-test, so clear it).
        auth()->forgetGuards();
        $this->postJson("/api/v1/admin/batches/{$batchId}/complete")->assertStatus(401);

        // Permission-less staff → 403 (gate fires before any state read).
        $bare = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $this->actingAs($bare, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/complete")
            ->assertStatus(403);

        // Cross-warehouse operator → 403, state untouched.
        $foreign = \App\Models\Fulfillment\Warehouse::create([
            'code' => 'WH-GP4', 'name' => 'Grouped authz', 'status' => 'active', 'is_default' => false,
        ]);
        $outsider = $this->user($foreign->id, ['view-fulfillment', 'batch.manage', 'batch.operate']);
        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/admin/batches/{$batchId}/complete")
            ->assertStatus(403);
        $this->assertSame('pending', FulfillmentBatch::find($batchId)->status);
    }
}
