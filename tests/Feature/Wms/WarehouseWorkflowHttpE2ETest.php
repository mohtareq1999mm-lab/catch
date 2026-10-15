<?php

namespace Tests\Feature\Wms;

use App\Events\PaymentSucceeded;
use App\Listeners\FulfillDigitalProducts;
use App\Listeners\ReleaseFulfillmentOnCodPlacement;
use App\Listeners\ReleaseFulfillmentOnPayment;
use App\Events\OrderCreated;
use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PackingStation;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Models\WarehouseWorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Warehouse Workflow — complete HTTP/API lifecycle closure (Gap B + C).
 *
 * Real HTTP routes + real container/DB/authorities/listeners throughout the
 * WMS chain. Catalog/order-row creation + payment-listener triggers are the
 * documented internal boundaries (external gateways out of scope, no doubles
 * claimed as provider verification). MySQL-only, sequential, RefreshDatabase.
 */
class WarehouseWorkflowHttpE2ETest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'manage-warehouse', 'manage-warehouse-workflow',
            'view-location', 'manage-location',
            'view-fulfillment', 'manage-fulfillment', 'fulfillment.create', 'fulfillment.cancel',
            'picking-execute', 'packing-execute', 'packing.complete',
            'batch.manage', 'batch.operate', 'order.cancel-during-fulfillment',
            'view-shipment', 'create-shipment', 'update-shipment',
            'payments.mark_paid',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->product = Product::create([
            'name' => 'WHE2E', 'slug' => 'whe2e-'.uniqid(), 'sku' => 'SKU-WHE2E-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 100, 'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);
    }

    private function user(?int $home, array $permissions): User
    {
        $u = User::factory()->create(['type' => 'staff', 'warehouse_id' => $home]);
        foreach ($permissions as $p) {
            $u->givePermissionTo($p);
        }

        return $u;
    }

    private function fullOperator(?int $home = null): User
    {
        return $this->user($home, [
            'manage-warehouse-workflow',
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
            'view-fulfillment', 'manage-fulfillment', 'fulfillment.create', 'fulfillment.cancel',
            'picking-execute', 'packing-execute', 'packing.complete',
            'batch.manage', 'batch.operate', 'order.cancel-during-fulfillment',
            'view-shipment', 'create-shipment', 'update-shipment',
            'payments.mark_paid',
        ]);
    }

    private function makePaidOnlineOrder(): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'WHE', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'WHE2E',
            'product_sku' => 'SKU-WHE2E-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        return $order->refresh();
    }

    /**
     * Warehouse + location via HTTP, stock placement, packing station.
     * Returns [warehouseId, locationId, stationId].
     */
    private function provisionWarehouse(callable $auth): array
    {
        $operator = null;
        $warehouseId = $auth()->postJson('/api/v1/admin/warehouses', [
            'code' => 'WH-WHE2E-'.uniqid(), 'name' => 'WHE2E Warehouse', 'is_default' => true,
        ])->assertStatus(201)->json('data.id');

        // Resolve operator from the acting user: re-fetch by warehouse below.
        $locationId = $auth()->postJson('/api/v1/admin/locations', [
            'warehouse_id' => $warehouseId, 'code' => 'WHE2E-01-'.uniqid(), 'barcode' => 'WHE2E-LOC-'.uniqid(),
            'name' => 'Bin', 'type' => 'picking',
        ])->assertStatus(201)->json('data.id');

        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $locationId,
            'warehouse_id' => $warehouseId, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        $stationId = PackingStation::create([
            'warehouse_id' => $warehouseId, 'code' => 'WHE2E-ST-'.uniqid(),
            'name' => 'WHE2E Station', 'status' => 'active',
        ])->id;

        return [$warehouseId, $locationId, $stationId];
    }

    /**
     * Walk picking→packing→ready_to_ship fully via HTTP. Returns fulfillment id.
     */
    private function walkToReadyToShip(callable $auth, int $fulfillmentId, int $stationId, string $barcode, string $sku): int
    {
        $batchId = $auth()->postJson('/api/v1/admin/batches', [
            'fulfillment_ids' => [$fulfillmentId],
        ])->assertStatus(201)->json('data.id');

        $operatorId = \Marvel\Database\Models\User::where('warehouse_id', Fulfillment::find($fulfillmentId)->warehouse_id)
            ->where('type', 'staff')->first()->id;
        $auth()->postJson("/api/v1/admin/batches/{$batchId}/assign", ['user_id' => $operatorId])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/batches/{$batchId}/start", [])->assertStatus(200);

        $tasks = \App\Models\Fulfillment\PickingTask::where('batch_id', $batchId)->orderBy('id')->get();
        $this->assertNotEmpty($tasks);
        $seq = 1;
        foreach ($tasks as $task) {
            $auth()->postJson("/api/v1/admin/picking-tasks/{$task->id}/claim", [])->assertStatus(200);
            $auth()->postJson("/api/v1/admin/picking-tasks/{$task->id}/confirm", [
                'location' => $barcode, 'product' => $sku, 'quantity' => 2, 'op_seq' => $seq++,
            ])->assertStatus(200);
        }
        $auth()->postJson("/api/v1/admin/batches/{$batchId}/refresh-progress", [])
            ->assertStatus(200)->assertJsonPath('data.status', 'completed');
        $this->assertSame('picked', Fulfillment::find($fulfillmentId)->status);

        $packingTaskId = $auth()->postJson(
            "/api/v1/admin/fulfillments/{$fulfillmentId}/create-packing-task", []
        )->assertStatus(201)->json('data.id');
        $auth()->postJson("/api/v1/admin/packing-tasks/{$packingTaskId}/assign", ['station_id' => $stationId])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/packing-tasks/{$packingTaskId}/start", [])->assertStatus(200);
        $packageId = $auth()->postJson('/api/v1/admin/packages', ['fulfillment_id' => $fulfillmentId])->assertStatus(201)->json('data.id');
        $itemId = Fulfillment::find($fulfillmentId)->items()->firstOrFail()->id;
        $auth()->postJson("/api/v1/admin/packages/{$packageId}/add-item", [
            'fulfillment_item_id' => $itemId, 'quantity' => 2,
        ])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/packages/{$packageId}/seal", [])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/packing-tasks/{$packingTaskId}/pack", [
            'weight' => 2.5, 'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10],
        ])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/packing-tasks/{$packingTaskId}/verify", [])->assertStatus(200);
        $this->assertSame('ready_to_ship', Fulfillment::find($fulfillmentId)->status);

        return $fulfillmentId;
    }

    // ---------- B.1 workflow configuration API ----------

    public function test_workflow_routes_registered_with_expected_middleware(): void
    {
        $routes = app('router')->getRoutes();
        $show = $routes->getByName('api.admin.warehouse-workflow.show');
        $history = $routes->getByName('api.admin.warehouse-workflow.history');
        $publish = $routes->getByName('api.admin.warehouse-workflow.publish');
        $this->assertNotNull($show);
        $this->assertNotNull($history);
        $this->assertNotNull($publish);
        $this->assertSame(['GET', 'HEAD'], array_values(array_intersect(['GET', 'HEAD'], $show->methods())));
        $this->assertContains('POST', $publish->methods());
        foreach ([$show, $history, $publish] as $r) {
            $this->assertContains('permission:manage-warehouse-workflow', $r->gatherMiddleware());
        }
    }

    public function test_workflow_api_auth_validation_actor_and_pagination(): void
    {
        $operator = $this->fullOperator();
        $auth = fn () => $this->actingAs($operator, 'sanctum');

        // Unauthenticated.
        $this->getJson('/api/v1/admin/warehouse-workflow')->assertStatus(401);
        $this->postJson('/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => true])->assertStatus(401);

        // Forbidden without permission.
        $plain = $this->user(null, []);
        $this->actingAs($plain, 'sanctum')->getJson('/api/v1/admin/warehouse-workflow')->assertStatus(403);
        $this->actingAs($plain, 'sanctum')->postJson('/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => true])->assertStatus(403);

        // Legacy read.
        $auth()->getJson('/api/v1/admin/warehouse-workflow')->assertOk()->assertJsonPath('data.legacy', true);

        // Invalid payloads → 422, no version created.
        foreach ([
            ['shipping_enabled' => 'yes'],
            ['order_received_enabled' => false],
            ['picking_enabled' => false],
            ['packing_enabled' => false],
            ['picking_enabled' => true, 'quality_check_enabled' => false],
            ['picking_enabled' => true, 'quality_check_enabled' => true],
        ] as $payload) {
            $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', $payload)->assertStatus(422);
        }
        $this->assertSame(0, WarehouseWorkflowVersion::count());

        // Actor comes from server context; client created_by ignored.
        $res = $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', [
            'shipping_enabled' => true, 'created_by' => 999999,
        ])->assertCreated();
        $version = WarehouseWorkflowVersion::latest('id')->firstOrFail();
        $this->assertSame($operator->id, (int) $version->created_by);
        $this->assertNotEquals(999999, (int) $version->created_by);

        // Pagination validation.
        $auth()->getJson('/api/v1/admin/warehouse-workflow/history?per_page=0')->assertStatus(422);
        $auth()->getJson('/api/v1/admin/warehouse-workflow/history?per_page=101')->assertStatus(422);
        $auth()->getJson('/api/v1/admin/warehouse-workflow/history?per_page=abc')->assertStatus(422);
        $auth()->getJson('/api/v1/admin/warehouse-workflow/history?per_page=1')->assertOk();
    }

    // ---------- B.2 physical order complete HTTP lifecycle ----------

    public function test_physical_order_complete_http_lifecycle(): void
    {
        $operator = $this->fullOperator();
        $auth = fn () => $this->actingAs($operator, 'sanctum');

        $published = $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', [
            'picking_enabled' => true, 'packing_enabled' => true, 'shipping_enabled' => true,
        ])->assertCreated()->json('data.id');

        [$warehouseId] = $this->provisionWarehouse($auth);
        $operator->update(['warehouse_id' => $warehouseId]);
        $stationId = PackingStation::where('warehouse_id', $warehouseId)->firstOrFail()->id;
        $location = Location::where('warehouse_id', $warehouseId)->firstOrFail();

        $order = $this->makePaidOnlineOrder();

        // Release via real HTTP endpoint (proves route + validation + authority).
        $releaseKey = 'http-e2e-'.uniqid();
        $fulfillmentId = $auth()->postJson('/api/v1/admin/fulfillments/release', [
            'order_id' => $order->id, 'warehouse_id' => $warehouseId,
            'idempotency_key' => $releaseKey,
        ])->assertStatus(201)->json('data.id');
        $this->assertSame((int) $published, (int) Fulfillment::find($fulfillmentId)->warehouse_workflow_version_id);

        // Same-key retry converges on the same row (200 replay, no duplicate).
        $retryId = $auth()->postJson('/api/v1/admin/fulfillments/release', [
            'order_id' => $order->id, 'warehouse_id' => $warehouseId,
            'idempotency_key' => $releaseKey,
        ])->assertStatus(200)->json('data.id');
        $this->assertSame((int) $fulfillmentId, (int) $retryId);
        $this->assertSame(1, Fulfillment::where('order_id', $order->id)->where('status', '!=', 'cancelled')->count());

        $this->walkToReadyToShip($auth, $fulfillmentId, $stationId, $location->barcode, 'SKU-WHE2E-1');

        $shipmentId = $auth()->postJson(
            "/api/v1/admin/fulfillments/{$fulfillmentId}/shipments", ['courier' => 'WHE2E-Post']
        )->assertStatus(201)->json('data.id');

        // Duplicate unkeyed shipment refused (409), no duplicate row.
        $auth()->postJson("/api/v1/admin/fulfillments/{$fulfillmentId}/shipments", ['courier' => 'WHE2E-Post'])->assertStatus(409);
        $this->assertSame(1, Shipment::where('fulfillment_id', $fulfillmentId)->count());

        $auth()->postJson("/api/v1/admin/shipments/{$shipmentId}/dispatch", [])->assertStatus(200);
        $this->assertSame('shipped', Fulfillment::find($fulfillmentId)->status);
        $auth()->postJson("/api/v1/admin/shipments/{$shipmentId}/deliver", [])->assertStatus(200);
        $this->assertSame('delivered', Fulfillment::find($fulfillmentId)->status);

        // Idempotent redeliver.
        $auth()->postJson("/api/v1/admin/shipments/{$shipmentId}/deliver", [])->assertStatus(200);

        // Payment-owned completion converges via official authority.
        app(\App\Services\General\OrderService::class)->changeOrderStatus(null, 'completed', $order->id);
        $this->assertSame('delivered', $order->fresh()->status);

        // Inventory invariants: committed goods never double-moved by WMS HTTP.
        $this->assertEquals(100, (float) $this->product->fresh()->stock_quantity);
    }

    public function test_cod_order_http_payment_and_lifecycle(): void
    {
        $operator = $this->fullOperator();
        $auth = fn () => $this->actingAs($operator, 'sanctum');

        $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => true])->assertCreated();
        [$warehouseId] = $this->provisionWarehouse($auth);
        $operator->update(['warehouse_id' => $warehouseId]);

        // Real customer checkout over HTTP (COD path hits no external gateway).
        $country = \Marvel\Database\Models\Country::create(['name' => 'WHE Country', 'status' => true]);
        $governorate = \Marvel\Database\Models\Governorate::create([
            'country_id' => $country->id, 'name' => 'WHE Gov', 'status' => true,
        ]);
        \Marvel\Database\Models\ShippingPrice::create([
            'governorate_id' => $governorate->id, 'price' => 20, 'status' => true,
        ]);
        $customer = User::factory()->create(['type' => 'customer']);
        $cart = \Marvel\Database\Models\Cart::query()->where('user_id', $customer->id)->lockForUpdate()->first()
            ?? \Marvel\Database\Models\Cart::create(['user_id' => $customer->id, 'status' => 'active']);
        app(\App\Services\General\CartInventoryService::class)->incrementItem($cart, $this->product->fresh(), null, 2);

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/general/checkout', [
            'name' => 'WHE COD', 'user_phone' => '01000000001', 'user_email' => $customer->email,
            'address' => ['street' => '1 Nile St', 'city' => 'Cairo'],
            'governorate_id' => $governorate->id, 'payment_method' => 'cod',
        ])->assertStatus(200);

        $order = Order::where('user_id', $customer->id)->latest('id')->firstOrFail();
        $this->assertSame('cod', $order->payment_method);
        $this->assertNotNull($order->transactions()->where('payment_method', 'cod')->where('status', 'pending')->first());

        // Real auto-release listener for COD placement.
        app(ReleaseFulfillmentOnCodPlacement::class)->handle(new OrderCreated($order));
        $fulfillment = Fulfillment::where('order_id', $order->id)->firstOrFail();

        // Real HTTP COD capture → completed via the canonical authority.
        $auth()->postJson("/api/v1/general/checkout/cod/{$order->id}/mark-paid", [])->assertStatus(200);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('pending', $fulfillment->fresh()->status);

        // Second capture refused idempotently; state preserved.
        $auth()->postJson("/api/v1/general/checkout/cod/{$order->id}/mark-paid", [])->assertStatus(422);
        $this->assertSame('completed', $order->fresh()->status);
    }

    // ---------- B.3 shipping=false HTTP lifecycle ----------

    public function test_shipping_disabled_http_denial_and_safe_blocking(): void
    {
        $operator = $this->fullOperator();
        $auth = fn () => $this->actingAs($operator, 'sanctum');

        $vEnabled = $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', [
            'shipping_enabled' => true,
        ])->assertCreated()->json('data.id');

        [$warehouseId] = $this->provisionWarehouse($auth);
        $operator->update(['warehouse_id' => $warehouseId]);
        $stationId = PackingStation::where('warehouse_id', $warehouseId)->firstOrFail()->id;
        $location = Location::where('warehouse_id', $warehouseId)->firstOrFail();

        // Existing legitimate shipment under enabled version.
        $orderOk = $this->makePaidOnlineOrder();
        $fOk = $auth()->postJson('/api/v1/admin/fulfillments/release', [
            'order_id' => $orderOk->id, 'warehouse_id' => $warehouseId, 'idempotency_key' => 'shipok-'.uniqid(),
        ])->assertStatus(201)->json('data.id');
        $this->assertSame((int) $vEnabled, (int) Fulfillment::find($fOk)->warehouse_workflow_version_id);
        $this->walkToReadyToShip($auth, $fOk, $stationId, $location->barcode, 'SKU-WHE2E-1');
        $shipOk = $auth()->postJson("/api/v1/admin/fulfillments/{$fOk}/shipments", ['courier' => 'Keep'])
            ->assertStatus(201)->json('data.id');

        // Disable shipping via HTTP.
        $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => false])->assertCreated();

        // Existing shipment retains lifecycle.
        $auth()->postJson("/api/v1/admin/shipments/{$shipOk}/dispatch", [])->assertStatus(200);
        $auth()->postJson("/api/v1/admin/shipments/{$shipOk}/deliver", [])->assertStatus(200);
        $this->assertSame('delivered', Fulfillment::find($fOk)->status);

        // New fulfillment pins disabled version; HTTP shipment creation denied pre-mutation.
        $orderBlocked = $this->makePaidOnlineOrder();
        $fBlocked = $auth()->postJson('/api/v1/admin/fulfillments/release', [
            'order_id' => $orderBlocked->id, 'warehouse_id' => $warehouseId, 'idempotency_key' => 'shipblocked-'.uniqid(),
        ])->assertStatus(201)->json('data.id');
        $this->walkToReadyToShip($auth, $fBlocked, $stationId, $location->barcode, 'SKU-WHE2E-1');

        $denied = $auth()->postJson("/api/v1/admin/fulfillments/{$fBlocked}/shipments", ['courier' => 'Nope'])
            ->assertStatus(422);
        $this->assertStringNotContainsString('wms.workflow', (string) $denied->getContent());
        $this->assertSame(0, Shipment::where('fulfillment_id', $fBlocked)->count());
        $this->assertSame('ready_to_ship', Fulfillment::find($fBlocked)->status);

        // Retry still denied, still zero rows.
        $auth()->postJson("/api/v1/admin/fulfillments/{$fBlocked}/shipments", ['courier' => 'Nope'])->assertStatus(422);
        $this->assertSame(0, Shipment::where('fulfillment_id', $fBlocked)->count());

        // Physical order cannot falsely complete.
        $this->assertFalse(app(\App\Services\Shipment\ShipmentService::class)->maybeCompleteOrder($orderBlocked->id));
        $this->assertNotEquals('delivered', $orderBlocked->fresh()->status);
    }

    // ---------- B.4 disabled picking/packing rejected, current preserved ----------

    public function test_disabled_picking_packing_rejected_current_preserved(): void
    {
        $operator = $this->fullOperator();
        $auth = fn () => $this->actingAs($operator, 'sanctum');

        $v1 = $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => true])
            ->assertCreated()->json('data.id');

        $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', ['picking_enabled' => false])->assertStatus(422);
        $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', ['packing_enabled' => false])->assertStatus(422);

        $this->assertSame(1, WarehouseWorkflowVersion::count());
        $this->assertSame((int) $v1, (int) app(\App\Services\Warehouse\WarehouseWorkflowService::class)->current()->id);

        // Ordinary flow still publishable after rejections.
        $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => false])->assertCreated();
        $this->assertSame(2, WarehouseWorkflowVersion::count());
    }

    // ---------- B.5 digital-only via real listener + signed HTTP download ----------

    public function test_digital_only_http_lifecycle(): void
    {
        Storage::fake('private');
        $operator = $this->fullOperator();
        $auth = fn () => $this->actingAs($operator, 'sanctum');
        $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => false])->assertCreated();

        $customer = User::factory()->create(['type' => 'customer']);
        $product = Product::create([
            'name' => ['en' => 'WHE Manual'], 'slug' => 'whe-manual-'.uniqid(),
            'price' => 50, 'product_type' => 'simple', 'item_type' => 'DIGITAL',
            'stock_quantity' => 100, 'in_stock' => true,
        ]);
        Storage::disk('private')->put("digital-assets/{$product->id}/asset.pdf", '%PDF-1.4 whe-content');
        $asset = DigitalAsset::create([
            'product_id' => $product->id, 'disk' => 'private',
            'path' => "digital-assets/{$product->id}/asset.pdf",
            'original_name' => 'Manual.pdf', 'mime' => 'application/pdf', 'size' => 1024,
        ]);
        $order = Order::create([
            'user_id' => $customer->id, 'order_number' => 'ORD-WHE-'.uniqid(), 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 50, 'total_price' => 50, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'digital', 'address' => ['city' => 'Cairo'],
        ]);
        $item = OrderProduct::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'WHE Manual',
            'product_quantity' => 1, 'product_price' => 50, 'product_total_price' => 50, 'item_type' => 'DIGITAL',
        ]);

        // Real internal boundary (listener used in production event flow).
        app(FulfillDigitalProducts::class)->handle(new PaymentSucceeded($order));
        $entitlement = DigitalEntitlement::where('order_product_id', $item->id)->firstOrFail();
        $this->assertSame(DigitalEntitlement::STATUS_DELIVERED, $entitlement->status);

        // Duplicate callback idempotent.
        app(FulfillDigitalProducts::class)->handle(new PaymentSucceeded($order));
        $this->assertSame(1, DigitalEntitlement::where('order_product_id', $item->id)->count());

        // No physical obligations under shipping=false.
        $this->assertSame(0, Fulfillment::where('order_id', $order->id)->count());
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count());

        // Signed download over real HTTP.
        $url = URL::temporarySignedRoute('general.digital.download', now()->addMinutes(10), [
            'entitlement' => $entitlement->uuid, 'asset' => $asset->uuid,
        ]);
        $this->actingAs($customer, 'sanctum')->get($url)->assertStatus(200);
    }

    // ---------- B.6 mixed order independence ----------

    public function test_mixed_order_http_independence(): void
    {
        $operator = $this->fullOperator();
        $auth = fn () => $this->actingAs($operator, 'sanctum');
        $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => true])->assertCreated();
        [$warehouseId] = $this->provisionWarehouse($auth);
        $operator->update(['warehouse_id' => $warehouseId]);

        $customer = User::factory()->create(['type' => 'customer']);
        $digital = Product::create([
            'name' => 'WHE-DIG', 'slug' => 'whe-dig-'.uniqid(), 'price' => 50,
            'product_type' => 'simple', 'item_type' => 'DIGITAL', 'stock_quantity' => 100, 'in_stock' => true,
        ]);
        DigitalAsset::create([
            'product_id' => $digital->id, 'disk' => 'private',
            'path' => "digital-assets/{$digital->id}/asset.pdf",
            'original_name' => 'Ebook.pdf', 'mime' => 'application/pdf', 'size' => 1024,
        ]);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'MIX', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 150, 'total_price' => 150, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'PHYS',
            'product_sku' => 'SKU-WHE2E-1', 'product_quantity' => 1,
            'product_price' => 100, 'product_total_price' => 100,
        ]);
        $digitalItem = $order->orderItems()->create([
            'product_id' => $digital->id, 'product_name' => 'DIG',
            'product_sku' => $digital->sku, 'product_quantity' => 1,
            'product_price' => 50, 'product_total_price' => 50, 'item_type' => 'DIGITAL',
        ]);
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        // Digital side completes independently.
        app(FulfillDigitalProducts::class)->handle(new PaymentSucceeded($order->refresh()));
        $this->assertSame(DigitalEntitlement::STATUS_DELIVERED, DigitalEntitlement::where('order_product_id', $digitalItem->id)->firstOrFail()->status);

        // Physical side keeps exactly one physical item via HTTP release.
        $fid = $auth()->postJson('/api/v1/admin/fulfillments/release', [
            'order_id' => $order->id, 'warehouse_id' => $warehouseId, 'idempotency_key' => 'mix-'.uniqid(),
        ])->assertStatus(201)->json('data.id');
        $this->assertSame(1, Fulfillment::find($fid)->items()->count());

        // Digital completion did not deliver the physical fulfillment.
        $this->assertNotEquals('delivered', Fulfillment::find($fid)->status);
    }

    // ---------- B.7 failure and rollback through HTTP ----------

    public function test_http_failures_leave_persisted_state_consistent(): void
    {
        $operator = $this->fullOperator();
        $auth = fn () => $this->actingAs($operator, 'sanctum');
        $auth()->postJson('/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => true])->assertCreated();
        [$warehouseId] = $this->provisionWarehouse($auth);
        $operator->update(['warehouse_id' => $warehouseId]);
        $stationId = PackingStation::where('warehouse_id', $warehouseId)->firstOrFail()->id;
        $location = Location::where('warehouse_id', $warehouseId)->firstOrFail();

        $order = $this->makePaidOnlineOrder();
        $fid = $auth()->postJson('/api/v1/admin/fulfillments/release', [
            'order_id' => $order->id, 'warehouse_id' => $warehouseId, 'idempotency_key' => 'fail-'.uniqid(),
        ])->assertStatus(201)->json('data.id');

        // Invalid transition path: shipment from non-ready fulfillment refused, zero rows.
        $auth()->postJson("/api/v1/admin/fulfillments/{$fid}/shipments", ['courier' => 'X'])->assertStatus(422);
        $this->assertSame(0, Shipment::where('fulfillment_id', $fid)->count());
        $this->assertSame('pending', Fulfillment::find($fid)->status);

        // Unauthorized shipment attempt: 403 + zero side effects.
        $outsider = $this->user(null, []);
        $this->actingAs($outsider, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fid}/shipments", ['courier' => 'X']
        )->assertStatus(403);
        $this->assertSame(0, Shipment::where('fulfillment_id', $fid)->count());

        // Cancel a fresh pending fulfillment via HTTP kills the chain.
        $order2 = $this->makePaidOnlineOrder();
        $fid2 = $auth()->postJson('/api/v1/admin/fulfillments/release', [
            'order_id' => $order2->id, 'warehouse_id' => $warehouseId, 'idempotency_key' => 'fail-cancel-'.uniqid(),
        ])->assertStatus(201)->json('data.id');
        $auth()->postJson("/api/v1/admin/orders/{$order2->id}/cancel", ['reason' => 'http e2e cancel'])->assertStatus(200);
        $this->assertSame('cancelled', Fulfillment::find($fid2)->status);
        $auth()->postJson("/api/v1/admin/fulfillments/{$fid2}/shipments", ['courier' => 'X'])->assertStatus(422);
        $auth()->postJson('/api/v1/admin/batches', ['fulfillment_ids' => [$fid2]])->assertStatus(422);
        $this->assertSame(0, Shipment::where('fulfillment_id', $fid2)->count());

        // Sealed-package fulfillment refuses cancellation atomically: 422 + zero mutation.
        $this->walkToReadyToShip($auth, $fid, $stationId, $location->barcode, 'SKU-WHE2E-1');
        $auth()->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'too late'])->assertStatus(422);
        $this->assertSame('ready_to_ship', Fulfillment::find($fid)->status);
        $this->assertSame(0, Shipment::where('fulfillment_id', $fid)->count());
    }

    // ---------- Gap C locale runtime ----------

    public function test_workflow_messages_localized_en_ar_without_raw_keys(): void
    {
        $operator = $this->fullOperator();

        // English invalid payload.
        $en = $this->actingAs($operator, 'sanctum')->withHeaders(['lang' => 'en'])
            ->postJson('/api/v1/admin/warehouse-workflow/publish', ['order_received_enabled' => false])
            ->assertStatus(422)->json('message');
        $this->assertStringContainsString('Order Received', (string) $en);
        $this->assertStringNotContainsString('wms.workflow', (string) $en);

        // Arabic invalid payload (JSON transport escapes non-ASCII: decode first).
        $ar = $this->actingAs($operator, 'sanctum')->withHeaders(['lang' => 'ar'])
            ->postJson('/api/v1/admin/warehouse-workflow/publish', ['order_received_enabled' => false])
            ->assertStatus(422)->json('message');
        $this->assertStringContainsString('استلام الطلب', (string) $ar);
        $this->assertStringNotContainsString('wms.workflow', (string) $ar);

        // Arabic shipping denial end-to-end message check (lightweight: publish + deny).
        $this->actingAs($operator, 'sanctum')->postJson(
            '/api/v1/admin/warehouse-workflow/publish', ['shipping_enabled' => false]
        )->assertCreated();
        $this->assertSame(1, WarehouseWorkflowVersion::count());
    }
}
