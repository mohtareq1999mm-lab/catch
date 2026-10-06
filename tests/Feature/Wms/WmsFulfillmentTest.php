<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Fulfillment\PackingService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * P9-3: Fulfillment HTTP adapter — auth, permission, warehouse scope, IDOR,
 * domain commands (release/cancel/assign), replay, invariants, conflicts.
 *
 * All state walks use the canonical owners (FulfillmentService,
 * FulfillmentTransition, PackingService, ShipmentService) — never HTTP
 * shortcuts. Sequential replay proofs only (shared MySQL test DB).
 */
class WmsFulfillmentTest extends TestCase
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
            'view-fulfillment', 'fulfillment.create', 'fulfillment.cancel',
            'manage-fulfillment', 'manage-warehouse',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouseA = Warehouse::create([
            'code' => 'WH-FA', 'name' => 'Ful A', 'status' => 'active', 'is_default' => true,
        ]);
        $this->warehouseB = Warehouse::create([
            'code' => 'WH-FB', 'name' => 'Ful B', 'status' => 'active', 'is_default' => false,
        ]);

        $location = Location::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'FA-01',
            'barcode' => 'FA-LOC-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'FP', 'slug' => 'fp-' . uniqid(), 'sku' => 'SKU-FUL-1',
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

    private function operator(?int $home = null): User
    {
        return $this->user($home ?? $this->warehouseA->id, [
            'view-fulfillment', 'fulfillment.create', 'fulfillment.cancel', 'manage-fulfillment',
        ]);
    }

    private function makeOrder(array $overrides = []): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create(array_merge([
            'user_id' => $user->id, 'name' => 'FO', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo'],
        ], $overrides));
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'FP',
            'product_sku' => 'SKU-FUL-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        return $order->refresh();
    }

    private function releaseFixture(Order $order, ?int $warehouseId = null, ?string $key = null): Fulfillment
    {
        return app(FulfillmentService::class)->releaseForOrder(
            $order, $warehouseId ?? $this->warehouseA->id, $key ?? ('fx-' . uniqid())
        );
    }

    private function walkTo(Fulfillment $fulfillment, string $state): Fulfillment
    {
        $ladder = ['pending', 'picking', 'picked', 'packing', 'ready_to_ship', 'shipped', 'delivered'];
        $owner = app(FulfillmentTransition::class);
        $from = array_search($fulfillment->status, $ladder, true);
        $to = array_search($state, $ladder, true);
        for ($i = $from + 1; $i <= $to; $i++) {
            $owner->transition($fulfillment, $ladder[$i]);
            $fulfillment = $fulfillment->refresh();
        }

        return $fulfillment;
    }

    // ---------- authentication ----------

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/v1/admin/fulfillments')->assertStatus(401);
        $this->getJson('/api/v1/admin/fulfillments/1')->assertStatus(401);
        $this->postJson('/api/v1/admin/fulfillments/release', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/fulfillments/1/cancel', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/fulfillments/1/assign', [])->assertStatus(401);
    }

    public function test_user_without_permission_is_forbidden_everywhere(): void
    {
        $user = $this->user($this->warehouseA->id);
        $order = $this->makeOrder();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/fulfillments')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/fulfillments/1')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/fulfillments/release', [
            'order_id' => $order->id,
        ])->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/fulfillments/1/cancel', [
            'reason' => 'x',
        ])->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/fulfillments/1/assign', [
            'user_id' => $user->id,
        ])->assertStatus(403);
    }

    public function test_viewer_can_read_but_not_command(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order);
        $user = $this->user($this->warehouseA->id, ['view-fulfillment']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/fulfillments')->assertStatus(200);
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/admin/fulfillments/{$fulfillment->id}")->assertStatus(200);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/fulfillments/release', [
            'order_id' => $order->id,
        ])->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/fulfillments/{$fulfillment->id}/cancel", [
            'reason' => 'x',
        ])->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/fulfillments/{$fulfillment->id}/assign", [
            'user_id' => $user->id,
        ])->assertStatus(403);
    }

    // ---------- reads / scope ----------

    public function test_list_scopes_to_home_warehouse(): void
    {
        $orderA = $this->makeOrder();
        $orderB = $this->makeOrder();
        $this->releaseFixture($orderA, $this->warehouseA->id, 'la');
        // Warehouse B has no placement; release via service needs allocation
        // fallback — items fall back to NULL placement, which is fine.
        app(FulfillmentService::class)->releaseForOrder($orderB, $this->warehouseB->id, 'lb');

        $user = $this->user($this->warehouseA->id, ['view-fulfillment']);
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/fulfillments');

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertStringNotContainsString('WH-FB', (string) $response->getContent());
    }

    public function test_show_cross_warehouse_returns_404(): void
    {
        $order = $this->makeOrder();
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order, $this->warehouseB->id, 'lc');
        $user = $this->user($this->warehouseA->id, ['view-fulfillment']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/admin/fulfillments/{$fulfillment->id}")
            ->assertStatus(404);
    }

    public function test_show_missing_returns_404(): void
    {
        $this->actingAs($this->operator(), 'sanctum')
            ->getJson('/api/v1/admin/fulfillments/999999')->assertStatus(404);
    }

    public function test_show_returns_bounded_detail_contract(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order);
        $response = $this->actingAs($this->operator(), 'sanctum')
            ->getJson("/api/v1/admin/fulfillments/{$fulfillment->id}");

        $response->assertStatus(200)->assertJson(['success' => true]);
        $data = $response->json('data');
        foreach ([
            'id', 'fulfillment_number', 'order_id', 'warehouse_id', 'status',
            'items', 'picking_tasks', 'packing_tasks', 'packages', 'shipments',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "detail missing {$key}");
        }
        $this->assertArrayNotHasKey('allowed_actions', $data, 'P9-9 owns allowed_actions');
        $this->assertArrayNotHasKey('metadata', $data);
        $this->assertCount(1, $data['items']);
    }

    public function test_index_with_null_home_fails_closed(): void
    {
        $user = $this->user(null, ['view-fulfillment']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/fulfillments')->assertStatus(403);
    }

    public function test_release_with_null_home_fails_closed_with_no_row(): void
    {
        $order = $this->makeOrder();
        $user = $this->user(null, ['fulfillment.create']);

        $this->actingAs($user, 'sanctum')->postJson(
            '/api/v1/admin/fulfillments/release',
            ['order_id' => $order->id, 'warehouse_id' => $this->warehouseA->id]
        )->assertStatus(403);

        $this->assertSame(0, Fulfillment::where('order_id', $order->id)->count());
    }

    // ---------- release ----------

    public function test_release_creates_pending_fulfillment_with_201(): void
    {
        $order = $this->makeOrder();
        $response = $this->actingAs($this->operator(), 'sanctum')->postJson(
            '/api/v1/admin/fulfillments/release',
            ['order_id' => $order->id, 'warehouse_id' => $this->warehouseA->id]
        );

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertDatabaseHas('fulfillments', [
            'order_id' => $order->id, 'warehouse_id' => $this->warehouseA->id, 'status' => 'pending',
        ]);
    }

    public function test_release_defaults_to_default_warehouse_and_ignores_status_tampering(): void
    {
        $order = $this->makeOrder();
        $response = $this->actingAs($this->operator(), 'sanctum')->postJson(
            '/api/v1/admin/fulfillments/release',
            ['order_id' => $order->id, 'status' => 'delivered']
        );

        $response->assertStatus(201);
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertSame($this->warehouseA->id, (int) $response->json('data.warehouse_id'));
    }

    public function test_release_into_foreign_warehouse_returns_403_with_no_row(): void
    {
        $order = $this->makeOrder();
        $user = $this->operator($this->warehouseA->id);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/fulfillments/release', [
            'order_id' => $order->id, 'warehouse_id' => $this->warehouseB->id,
        ])->assertStatus(403);

        $this->assertSame(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_release_keyed_replay_returns_200_same_row(): void
    {
        $order = $this->makeOrder();
        $operator = $this->operator();
        $payload = [
            'order_id' => $order->id, 'warehouse_id' => $this->warehouseA->id,
            'idempotency_key' => 'op-key-1',
        ];

        $first = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/admin/fulfillments/release', $payload);
        $first->assertStatus(201);

        $second = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/admin/fulfillments/release', $payload);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_release_keyless_replay_returns_200_same_row(): void
    {
        $order = $this->makeOrder();
        $operator = $this->operator();
        $payload = ['order_id' => $order->id, 'warehouse_id' => $this->warehouseA->id];

        $first = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/admin/fulfillments/release', $payload);
        $first->assertStatus(201);

        $second = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/admin/fulfillments/release', $payload);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_release_rejects_reserved_automatic_namespace(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->operator(), 'sanctum')->postJson(
            '/api/v1/admin/fulfillments/release',
            ['order_id' => $order->id, 'idempotency_key' => 'auto-release-order-999']
        )->assertStatus(422);

        $this->assertSame(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_release_unreleasable_order_returns_422(): void
    {
        $order = $this->makeOrder(['status' => 'cancelled']);

        $this->actingAs($this->operator(), 'sanctum')->postJson(
            '/api/v1/admin/fulfillments/release',
            ['order_id' => $order->id, 'warehouse_id' => $this->warehouseA->id]
        )->assertStatus(422);

        $this->assertSame(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_release_missing_order_returns_404_with_canonical_envelope(): void
    {
        $operator = $this->operator();

        $response = $this->actingAs($operator, 'sanctum')->postJson(
            '/api/v1/admin/fulfillments/release',
            ['order_id' => 999999, 'warehouse_id' => $this->warehouseA->id]
        );

        // Missing resource — never a business-rule refusal.
        $response->assertStatus(404)->assertJson(['status' => false]);
        $this->assertSame(0, Fulfillment::where('order_id', 999999)->count());
    }

    public function test_release_second_warehouse_refused_by_domain_guard(): void
    {
        // P2-3: keyless second-warehouse release refused by domain guard.
        $order = $this->makeOrder();
        $operatorB = $this->user($this->warehouseB->id, ['view-fulfillment', 'fulfillment.create']);
        $this->releaseFixture($order, $this->warehouseA->id, 'p23a');

        $this->actingAs($operatorB, 'sanctum')->postJson(
            '/api/v1/admin/fulfillments/release',
            ['order_id' => $order->id, 'warehouse_id' => $this->warehouseB->id]
        )->assertStatus(422);
    }

    private function queryException(array $errorInfo): QueryException
    {
        $pdo = new \PDOException('simulated database failure');
        $pdo->errorInfo = $errorInfo;

        return new QueryException('mysql', 'insert into `fulfillments` (...) values (...)', [], $pdo);
    }

    public function test_unrelated_query_exception_is_never_replayed_as_success(): void
    {
        $order = $this->makeOrder();
        $failure = $this->queryException(['HY000', 2006, 'MySQL server has gone away']);

        $mock = $this->mock(FulfillmentService::class);
        $mock->shouldReceive('releaseForOrder')->once()->andThrow($failure);

        $response = $this->actingAs($this->operator(), 'sanctum')->postJson(
            '/api/v1/admin/fulfillments/release',
            ['order_id' => $order->id, 'warehouse_id' => $this->warehouseA->id, 'idempotency_key' => 'qx-1']
        );

        // A non-duplicate database failure must surface as an error — never
        // as a false 200 replay — even when an idempotency key is present.
        // (Handler maps QueryException to 409 database-error; the assertion
        // that matters is: error envelope, no replay, zero rows.)
        $response->assertStatus(409)->assertJson(['status' => false]);
        $this->assertSame(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_duplicate_key_query_exception_replays_existing_row(): void
    {
        $order = $this->makeOrder();
        $existing = $this->releaseFixture($order, $this->warehouseA->id, 'qx-dup');
        $duplicate = $this->queryException(['23000', 1062, "Duplicate entry 'qx-dup' for key 'fulfillments_unique'"]);

        $mock = $this->mock(FulfillmentService::class);
        $mock->shouldReceive('releaseForOrder')->once()->andThrow($duplicate);

        $response = $this->actingAs($this->operator(), 'sanctum')->postJson(
            '/api/v1/admin/fulfillments/release',
            ['order_id' => $order->id, 'warehouse_id' => $this->warehouseA->id, 'idempotency_key' => 'qx-dup']
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame($existing->id, (int) $response->json('data.id'));
    }

    // ---------- cancel ----------

    public function test_cancel_pending_fulfillment_audits_actor_and_source(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order);
        $operator = $this->operator();

        $response = $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/cancel",
            ['reason' => 'customer request', 'cancelled_by' => 999999, 'actor_id' => 999999]
        );

        $response->assertStatus(200);
        $fresh = $fulfillment->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame($operator->id, (int) $fresh->cancelled_by);
        $this->assertSame('admin_api', $fresh->cancel_source);
    }

    public function test_cancel_requires_reason(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order);

        $this->actingAs($this->operator(), 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/cancel",
            ['reason' => '  ']
        )->assertStatus(422);

        $this->assertSame('pending', $fulfillment->fresh()->status);
    }

    public function test_cancel_replay_on_cancelled_returns_200(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order);
        $operator = $this->operator();

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/cancel", ['reason' => 'first']
        )->assertStatus(200);
        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/cancel", ['reason' => 'second']
        )->assertStatus(200);

        $this->assertSame('cancelled', $fulfillment->fresh()->status);
    }

    public function test_cancel_cross_warehouse_returns_403_with_state_untouched(): void
    {
        $order = $this->makeOrder();
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order, $this->warehouseB->id, 'cc');
        $user = $this->operator($this->warehouseA->id);

        $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/cancel", ['reason' => 'hijack']
        )->assertStatus(403);

        $this->assertSame('pending', $fulfillment->fresh()->status);
    }

    public function test_cancel_with_live_shipment_is_refused(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order, $this->warehouseA->id, 'ls');
        $this->walkTo($fulfillment, 'ready_to_ship');
        app(ShipmentService::class)->createForFulfillment($fulfillment->refresh(), [], 'ls-ship');

        $this->actingAs($this->operator(), 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/cancel", ['reason' => 'too late']
        )->assertStatus(422);

        $this->assertSame('ready_to_ship', $fulfillment->fresh()->status);
    }

    public function test_cancel_with_sealed_package_is_refused(): void
    {
        $fulfillment = $this->packToPacked($this->makeOrder());
        $packing = app(PackingService::class);
        $item = $fulfillment->items()->firstOrFail();
        // Fixture convention (cf. CancellationPhase7Test, PackingPhase6Test):
        // simulate completed prior pick progress; the add/seal guards under
        // test still execute through their canonical authorities.
        $item->update(['quantity_picked' => $item->quantity, 'status' => 'picked']);
        $package = $packing->createPackage($fulfillment->refresh());
        $packing->addItemToPackage($package, $item->id, (float) $item->quantity);
        $packing->sealPackage($package->fresh());

        $this->actingAs($this->operator(), 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/cancel", ['reason' => 'custody']
        )->assertStatus(422);

        $this->assertSame('packing', $fulfillment->fresh()->status);
    }

    public function test_cancel_with_packed_task_is_refused(): void
    {
        $fulfillment = $this->packToPacked($this->makeOrder());

        $this->actingAs($this->operator(), 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/cancel", ['reason' => 'packed']
        )->assertStatus(422);

        $this->assertSame('packing', $fulfillment->fresh()->status);
    }

    public function test_cancel_delivered_fulfillment_is_refused_by_dag(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order, $this->warehouseA->id, 'dl');
        $this->walkTo($fulfillment, 'ready_to_ship');
        $shipments = app(ShipmentService::class);
        $shipment = $shipments->createForFulfillment($fulfillment->refresh(), [], 'dl-ship');
        $shipments->dispatch($shipment->id);
        $shipments->markDelivered($shipment->id);
        $this->assertSame('delivered', $fulfillment->fresh()->status);

        $this->actingAs($this->operator(), 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/cancel", ['reason' => 'history']
        )->assertStatus(422);

        $this->assertSame('delivered', $fulfillment->fresh()->status);
    }

    private function packToPacked(Order $order): Fulfillment
    {
        $fulfillment = $this->releaseFixture($order);
        $this->walkTo($fulfillment, 'picked');

        $packing = app(PackingService::class);
        $task = $packing->createPackingTaskFromFulfillment($fulfillment->refresh());
        $station = PackingStation::create([
            'warehouse_id' => $this->warehouseA->id, 'code' => 'PS-' . uniqid(),
            'name' => 'Station', 'status' => 'active',
        ]);
        $picker = $this->user($this->warehouseA->id, []);
        $packing->assignToStation($task, $station->id, $picker->id);
        $packing->startPacking($task->fresh());
        $packing->completePacking($task->fresh(), 1.5, ['l' => 1, 'w' => 1, 'h' => 1]);

        return $fulfillment->refresh();
    }

    // ---------- assign ----------

    public function test_assign_sets_first_winner_and_rejects_second_with_409(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order);
        $operator = $this->operator();
        $first = $this->user($this->warehouseA->id, []);
        $second = $this->user($this->warehouseA->id, []);

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/assign", ['user_id' => $first->id]
        )->assertStatus(200);
        $this->assertSame($first->id, (int) $fulfillment->fresh()->assigned_to);

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/assign", ['user_id' => $second->id]
        )->assertStatus(409);
        $this->assertSame($first->id, (int) $fulfillment->fresh()->assigned_to);
    }

    public function test_assign_same_user_replay_returns_200(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order);
        $operator = $this->operator();

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/assign", ['user_id' => $operator->id]
        )->assertStatus(200);
        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/assign", ['user_id' => $operator->id]
        )->assertStatus(200);
    }

    public function test_assign_cross_warehouse_returns_403_with_no_assignment(): void
    {
        $order = $this->makeOrder();
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order, $this->warehouseB->id, 'ac');
        $operator = $this->operator($this->warehouseA->id);

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/assign", ['user_id' => $operator->id]
        )->assertStatus(403);

        $this->assertNull($fulfillment->fresh()->assigned_to);
    }

    public function test_assign_ignores_actor_forgery_fields(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order);
        $operator = $this->operator();
        $assignee = $this->user($this->warehouseA->id, []);

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/assign",
            ['user_id' => $assignee->id, 'actor_id' => 999999, 'cancelled_by' => 999999, 'status' => 'delivered']
        )->assertStatus(200);

        $fresh = $fulfillment->fresh();
        $this->assertSame($assignee->id, (int) $fresh->assigned_to);
        $this->assertSame('pending', $fresh->status);
    }

    public function test_assign_on_cancelled_fulfillment_documents_existing_service_behavior(): void
    {
        // Known domain quirk (P9-3 discovery): assignToUser has no terminal
        // guard. The adapter reflects the service; it does not re-guard.
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order);
        $operator = $this->operator();
        $assignee = $this->user($this->warehouseA->id, []);

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/cancel", ['reason' => 'end']
        )->assertStatus(200);

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/assign", ['user_id' => $assignee->id]
        )->assertStatus(200);
        $this->assertSame($assignee->id, (int) $fulfillment->fresh()->assigned_to);
    }

    public function test_assign_missing_user_returns_422(): void
    {
        $order = $this->makeOrder();
        $fulfillment = $this->releaseFixture($order);

        $this->actingAs($this->operator(), 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/assign", ['user_id' => 999999]
        )->assertStatus(422);
    }

    // ---------- invariants ----------

    public function test_release_and_cancel_leave_order_inventory_payment_untouched(): void
    {
        $order = $this->makeOrder();
        $before = $order->only(['status', 'payment_status', 'inventory_state']);
        $stockBefore = [(float) $this->product->stock_quantity, (float) $this->product->reserved_quantity];
        $operator = $this->operator();

        $release = $this->actingAs($operator, 'sanctum')->postJson(
            '/api/v1/admin/fulfillments/release',
            ['order_id' => $order->id, 'warehouse_id' => $this->warehouseA->id]
        );
        $release->assertStatus(201);

        $this->actingAs($operator, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$release->json('data.id')}/cancel", ['reason' => 'audit']
        )->assertStatus(200);

        $order = $order->fresh();
        $this->assertSame($before['status'], $order->status);
        $this->assertSame($before['payment_status'], $order->payment_status);
        $this->assertSame($before['inventory_state'], $order->inventory_state);
        $product = $this->product->fresh();
        $this->assertSame($stockBefore, [(float) $product->stock_quantity, (float) $product->reserved_quantity]);
        $this->assertSame(0, \App\Models\Shipment::where('order_id', $order->id)->count());
    }
}
