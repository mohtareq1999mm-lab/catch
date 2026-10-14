<?php

namespace Tests\Feature\Wms;

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
 * Multi-location product placement: one product in many locations of one
 * warehouse, occupied-location refusals, placement-aware Admin responses,
 * and the split-allocation end-to-end path.
 *
 * Conventions: central products.stock_quantity stays the authority;
 * product_locations rows are hints (quantity/allocated_hint). One product
 * per location is the occupancy invariant (UNVERIFIED at raw-SQL level —
 * enforced in StockOperationService; direct model writes bypass it like
 * every other domain guard).
 */
class WmsMultiLocationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Warehouse $otherWarehouse;

    private Location $a01;

    private Location $a02;

    private Location $b01;

    private Location $foreign;

    private Product $product;

    private Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();
        // Message assertions are English (production default is Arabic).
        app()->setLocale('en');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-location', 'manage-location', 'inventory-adjust',
            'view-fulfillment', 'manage-fulfillment', 'manage-warehouse',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-ML', 'name' => 'Multi', 'status' => 'active', 'is_default' => true,
        ]);
        $this->otherWarehouse = Warehouse::create([
            'code' => 'WH-ML-O', 'name' => 'Other', 'status' => 'active', 'is_default' => false,
        ]);
        $this->a01 = $this->makeLocation('A-01');
        $this->a02 = $this->makeLocation('A-02');
        $this->b01 = $this->makeLocation('B-01');
        $this->foreign = $this->makeLocation('F-01', $this->otherWarehouse->id);

        $this->product = $this->makeProduct('SKU-ML-A');
        $this->otherProduct = $this->makeProduct('SKU-ML-B');
    }

    // ---------- helpers ----------

    private function makeLocation(string $code, ?int $warehouseId = null): Location
    {
        return Location::create([
            'warehouse_id' => $warehouseId ?? $this->warehouse->id,
            'code' => $code, 'barcode' => 'BC-' . $code . '-' . uniqid(),
            'name' => 'Bin ' . $code, 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
    }

    private function makeProduct(string $sku, int $stock = 0): Product
    {
        return Product::create([
            'name' => 'P-' . $sku, 'slug' => strtolower($sku) . '-' . uniqid(), 'sku' => $sku,
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => $stock, 'reserved_quantity' => 0,
        ]);
    }

    private function manager(array $perms = ['inventory-adjust', 'manage-fulfillment', 'view-fulfillment', 'view-location']): User
    {
        return $this->user($this->warehouse->id, $perms);
    }

    private function user(int $warehouseId, array $perms): User
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $warehouseId]);
        foreach ($perms as $perm) {
            $user->givePermissionTo($perm);
        }

        return $user;
    }

    private function receiveHttp(User $actor, int $locationId, array $body)
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/admin/locations/{$locationId}/stock-receipts", $body);
    }

    private function transferHttp(User $actor, array $body)
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/admin/stock-transfers', $body);
    }

    private function placementsHttp(User $actor, int $productId, ?int $warehouseId = null)
    {
        $uri = "/api/v1/admin/products/{$productId}/placements";
        if ($warehouseId !== null) {
            $uri .= "?warehouse_id={$warehouseId}";
        }

        return $this->actingAs($actor, 'sanctum')->getJson($uri);
    }

    // ---------- receiving: multi-location ----------

    public function test_same_product_received_into_two_locations(): void
    {
        $manager = $this->manager();

        $this->receiveHttp($manager, $this->a01->id, [
            'product_id' => $this->product->id, 'quantity' => 10,
        ])->assertStatus(201);
        $this->receiveHttp($manager, $this->a02->id, [
            'product_id' => $this->product->id, 'quantity' => 15,
        ])->assertStatus(201);

        // Two rows for one product (pair uniqueness, not product uniqueness).
        $this->assertSame(2, ProductLocation::where('product_id', $this->product->id)->count());
        // Authority incremented exactly once per receipt.
        $this->assertSame(25, (int) $this->product->fresh()->stock_quantity);
        $this->assertEquals(10, (float) ProductLocation::where('product_id', $this->product->id)->where('location_id', $this->a01->id)->firstOrFail()->quantity);
        $this->assertEquals(15, (float) ProductLocation::where('product_id', $this->product->id)->where('location_id', $this->a02->id)->firstOrFail()->quantity);
    }

    public function test_receive_into_existing_placement_increments_without_duplicate_row(): void
    {
        $manager = $this->manager();

        $this->receiveHttp($manager, $this->a01->id, [
            'product_id' => $this->product->id, 'quantity' => 10,
        ])->assertStatus(201);
        $this->receiveHttp($manager, $this->a01->id, [
            'product_id' => $this->product->id, 'quantity' => 5,
        ])->assertStatus(201);

        $this->assertSame(1, ProductLocation::where('product_id', $this->product->id)->where('location_id', $this->a01->id)->count());
        $this->assertEquals(15, (float) ProductLocation::where('product_id', $this->product->id)->where('location_id', $this->a01->id)->firstOrFail()->quantity);
        $this->assertSame(15, (int) $this->product->fresh()->stock_quantity);
    }

    // ---------- occupied-location policy ----------

    public function test_receive_different_product_into_occupied_location_refused(): void
    {
        $manager = $this->manager();
        $this->receiveHttp($manager, $this->a01->id, [
            'product_id' => $this->product->id, 'quantity' => 10,
        ])->assertStatus(201);

        $stockBefore = (int) $this->otherProduct->fresh()->stock_quantity;

        $response = $this->receiveHttp($manager, $this->a01->id, [
            'product_id' => $this->otherProduct->id, 'quantity' => 5,
        ]);
        $response->assertStatus(422);
        // Exact translated message (not a raw key leak): the occupant is
        // named so the operator knows what to empty first.
        $this->assertSame(
            __('wms.stock.location_occupied', ['location' => $this->a01->id, 'occupant' => $this->product->id, 'product' => $this->otherProduct->id]),
            $response->json('message')
        );

        // Refusal leaves everything untouched: no row, no stock moves.
        $this->assertSame(0, ProductLocation::where('product_id', $this->otherProduct->id)->count());
        $this->assertSame($stockBefore, (int) $this->otherProduct->fresh()->stock_quantity);
        $this->assertSame(10, (int) $this->product->fresh()->stock_quantity);
        $this->assertEquals(10, (float) ProductLocation::where('product_id', $this->product->id)->where('location_id', $this->a01->id)->firstOrFail()->quantity);
    }

    public function test_receive_into_location_with_only_zero_history_rows_allowed(): void
    {
        // Retained zero-quantity history does not occupy.
        ProductLocation::create([
            'product_id' => $this->otherProduct->id, 'location_id' => $this->a01->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);

        $this->receiveHttp($this->manager(), $this->a01->id, [
            'product_id' => $this->product->id, 'quantity' => 7,
        ])->assertStatus(201);

        $this->assertSame(7, (int) $this->product->fresh()->stock_quantity);
    }

    public function test_initial_placement_into_occupied_location_refused_without_product(): void
    {
        $manager = $this->manager();
        $this->receiveHttp($manager, $this->a01->id, [
            'product_id' => $this->product->id, 'quantity' => 10,
        ])->assertStatus(201);
        $before = Product::count();

        Permission::firstOrCreate(['name' => 'view-products', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'create-product', 'guard_name' => 'api']);
        $manager->givePermissionTo(['view-products', 'create-product']);

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/products', [
            'name' => ['en' => 'Occupied ' . uniqid()],
            'description' => ['en' => 'd'],
            'product_type' => 'simple',
            'price' => 150,
            'quantity' => 5,
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
            'location_id' => $this->a01->id,
        ])->assertStatus(422);

        // Atomic rollback: no product, no placement for it.
        $this->assertSame($before, Product::count());
    }

    // ---------- transfers ----------

    public function test_transfer_into_empty_destination_creates_row_central_unchanged(): void
    {
        $manager = $this->manager();
        $this->receiveHttp($manager, $this->a01->id, [
            'product_id' => $this->product->id, 'quantity' => 10,
        ])->assertStatus(201);

        $response = $this->transferHttp($manager, [
            'product_id' => $this->product->id,
            'source_location_id' => $this->a01->id,
            'destination_location_id' => $this->a02->id,
            'quantity' => 4,
        ]);
        $response->assertStatus(201);

        // Both sides described; authority provably unchanged.
        $data = $response->json('data.operation');
        $this->assertSame(10, (int) $data['stock_quantity']);
        $this->assertEquals(6, (float) $data['source_placement']['quantity']);
        $this->assertEquals(4, (float) $data['destination_placement']['quantity']);
        $this->assertSame(10, (int) $this->product->fresh()->stock_quantity);
    }

    public function test_transfer_into_same_product_destination_merges(): void
    {
        $manager = $this->manager();
        $this->receiveHttp($manager, $this->a01->id, ['product_id' => $this->product->id, 'quantity' => 10])->assertStatus(201);
        $this->receiveHttp($manager, $this->a02->id, ['product_id' => $this->product->id, 'quantity' => 5])->assertStatus(201);

        $this->transferHttp($manager, [
            'product_id' => $this->product->id,
            'source_location_id' => $this->a01->id,
            'destination_location_id' => $this->a02->id,
            'quantity' => 3,
        ])->assertStatus(201);

        // No duplicate row: the pair row is incremented.
        $this->assertSame(1, ProductLocation::where('product_id', $this->product->id)->where('location_id', $this->a02->id)->count());
        $this->assertEquals(8, (float) ProductLocation::where('product_id', $this->product->id)->where('location_id', $this->a02->id)->firstOrFail()->quantity);
        $this->assertEquals(7, (float) ProductLocation::where('product_id', $this->product->id)->where('location_id', $this->a01->id)->firstOrFail()->quantity);
        $this->assertSame(15, (int) $this->product->fresh()->stock_quantity);
    }

    public function test_transfer_into_occupied_destination_refused(): void
    {
        $manager = $this->manager();
        $this->receiveHttp($manager, $this->a01->id, ['product_id' => $this->product->id, 'quantity' => 10])->assertStatus(201);
        $this->receiveHttp($manager, $this->a02->id, ['product_id' => $this->otherProduct->id, 'quantity' => 5])->assertStatus(201);

        $response = $this->transferHttp($manager, [
            'product_id' => $this->product->id,
            'source_location_id' => $this->a01->id,
            'destination_location_id' => $this->a02->id,
            'quantity' => 2,
        ]);
        $response->assertStatus(422);
        $this->assertSame(
            __('wms.stock.location_occupied', ['location' => $this->a02->id, 'occupant' => $this->otherProduct->id, 'product' => $this->product->id]),
            $response->json('message')
        );

        // Both sides + authority untouched.
        $this->assertEquals(10, (float) ProductLocation::where('product_id', $this->product->id)->where('location_id', $this->a01->id)->firstOrFail()->quantity);
        $this->assertEquals(5, (float) ProductLocation::where('product_id', $this->otherProduct->id)->where('location_id', $this->a02->id)->firstOrFail()->quantity);
        $this->assertSame(0, ProductLocation::where('product_id', $this->product->id)->where('location_id', $this->a02->id)->count());
        $this->assertSame(10, (int) $this->product->fresh()->stock_quantity);
    }

    // ---------- product placements read model ----------

    public function test_product_placements_endpoint_returns_all_three_locations(): void
    {
        $manager = $this->manager();
        foreach ([[$this->a01, 10], [$this->a02, 15], [$this->b01, 5]] as [$loc, $qty]) {
            $this->receiveHttp($manager, $loc->id, [
                'product_id' => $this->product->id, 'quantity' => $qty,
            ])->assertStatus(201);
        }
        // Reservation stays central: simulate a committed reservation slice.
        $this->product->update(['reserved_quantity' => 4]);

        $response = $this->placementsHttp($manager, $this->product->id);
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame($this->product->id, (int) $data['product']['id']);
        $this->assertSame('SKU-ML-A', $data['product']['sku']);
        $this->assertSame(30, (int) $data['product']['stock_quantity']);
        $this->assertSame(4, (int) $data['product']['reserved_quantity']);
        $this->assertSame(26, (int) $data['product']['available_quantity']);

        $codes = collect($data['placements'])->pluck('location_code')->sort()->values()->all();
        $this->assertSame(['A-01', 'A-02', 'B-01'], $codes);
        $byCode = collect($data['placements'])->keyBy('location_code');
        $this->assertEquals(10, (float) $byCode['A-01']['quantity']);
        $this->assertEquals(15, (float) $byCode['A-02']['quantity']);
        $this->assertEquals(5, (float) $byCode['B-01']['quantity']);
        foreach ($data['placements'] as $placement) {
            $this->assertSame($this->warehouse->id, (int) $placement['warehouse_id']);
        }
    }

    public function test_product_placements_endpoint_scopes_and_404s(): void
    {
        $manager = $this->manager();
        $this->receiveHttp($manager, $this->a01->id, ['product_id' => $this->product->id, 'quantity' => 10])->assertStatus(201);

        // Global holder may narrow with ?warehouse_id.
        $global = User::factory()->create(['type' => 'staff', 'warehouse_id' => null]);
        $global->givePermissionTo('manage-warehouse', 'view-location');
        $narrowed = $this->placementsHttp($global, $this->product->id, $this->otherWarehouse->id);
        $narrowed->assertStatus(200);
        $this->assertSame([], $narrowed->json('data.placements'));

        // Foreign-homed scoped reader sees none of this warehouse's rows.
        $foreignReader = $this->user($this->otherWarehouse->id, ['view-location']);
        $response = $this->placementsHttp($foreignReader, $this->product->id);
        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.placements'));

        // Unknown product → 404, unknown placement set → 200 [].
        $this->placementsHttp($manager, 999999)->assertStatus(404);
        $empty = $this->placementsHttp($manager, $this->otherProduct->id);
        $empty->assertStatus(200);
        $this->assertSame([], $empty->json('data.placements'));
    }

    public function test_location_show_includes_placements_while_index_stays_light(): void
    {
        $manager = $this->manager();
        $this->receiveHttp($manager, $this->a01->id, ['product_id' => $this->product->id, 'quantity' => 10])->assertStatus(201);

        $show = $this->actingAs($manager, 'sanctum')->getJson("/api/v1/admin/locations/{$this->a01->id}");
        $show->assertStatus(200);
        $placements = $show->json('data.placements');
        $this->assertCount(1, $placements);
        $this->assertSame($this->product->id, (int) $placements[0]['product_id']);
        $this->assertSame('SKU-ML-A', $placements[0]['product_sku']);
        $this->assertEquals(10, (float) $placements[0]['quantity']);

        $index = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/admin/locations?limit=100');
        $index->assertStatus(200);
        // Paginated collection serializes its items directly under data.
        $rows = $index->json('data') ?? [];
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('placements', $row);
        }
    }

    // ---------- assign-placement response ----------

    public function test_assign_placement_response_carries_current_placement(): void
    {
        // Bare product (no placement) → NULL-allocation item.
        $bare = $this->makeProduct('SKU-ML-C', 20);
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'ML', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100, 'total_price' => 100, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $bare->id, 'product_name' => 'C', 'product_sku' => 'SKU-ML-C',
            'product_quantity' => 2, 'product_price' => 100, 'product_total_price' => 200,
        ]);
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $this->warehouse->id, 'ml-ap-1');
        $item = $fulfillment->items()->firstOrFail();
        $this->assertNull($item->product_location_id);

        $own = ProductLocation::create([
            'product_id' => $bare->id, 'location_id' => $this->a01->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 20, 'allocated_hint' => 0,
        ]);

        $response = $this->actingAs($this->manager(), 'sanctum')->postJson(
            "/api/v1/admin/fulfillment-items/{$item->id}/assign-placement",
            ['product_location_id' => $own->id]
        );
        $response->assertStatus(200);

        // The Admin identifies the new shelf from the response alone.
        $placement = $response->json('data.placement');
        $this->assertSame($own->id, (int) $placement['id']);
        $this->assertSame($this->a01->id, (int) $placement['location_id']);
        $this->assertSame('A-01', $placement['code']);
        $this->assertNotEmpty($placement['barcode']);
        $this->assertSame($own->id, (int) $response->json('data.product_location_id'));

        // Central stock untouched by a pure placement assignment.
        $this->assertSame(20, (int) $bare->fresh()->stock_quantity);
    }

    // ---------- split allocation across locations ----------

    public function test_order_line_splits_into_items_across_two_locations(): void
    {
        $manager = $this->manager();
        // 6 + 6 across two bins; order line needs 10 → must split 6 + 4.
        $this->receiveHttp($manager, $this->a01->id, ['product_id' => $this->product->id, 'quantity' => 6])->assertStatus(201);
        $this->receiveHttp($manager, $this->a02->id, ['product_id' => $this->product->id, 'quantity' => 6])->assertStatus(201);

        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'ML-S', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100, 'total_price' => 1000, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'A', 'product_sku' => 'SKU-ML-A',
            'product_quantity' => 10, 'product_price' => 100, 'product_total_price' => 1000,
        ]);

        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $this->warehouse->id, 'ml-split-1');

        $items = $fulfillment->items()->orderBy('id')->get();
        $this->assertSame(2, $items->count());
        $this->assertSame(10, (int) $items->sum('quantity'));
        $this->assertNotSame(
            (int) $items[0]->product_location_id,
            (int) $items[1]->product_location_id
        );

        // Picking tasks can be created for both placement-backed items.
        $tasks = app(\App\Services\Fulfillment\OrderPickingService::class)->createTasksForFulfillment($fulfillment);
        $this->assertSame(2, count($tasks));
    }

    public function test_duplicate_receipt_keys_replay_without_double_stock(): void
    {
        $manager = $this->manager();
        $body = ['product_id' => $this->product->id, 'quantity' => 5, 'idempotency_key' => 'ml-dup-1'];

        $this->receiveHttp($manager, $this->a01->id, $body)->assertStatus(201);
        $this->receiveHttp($manager, $this->a01->id, $body)->assertStatus(200);

        $this->assertSame(5, (int) $this->product->fresh()->stock_quantity);
        $this->assertSame(1, ProductLocation::where('product_id', $this->product->id)->where('location_id', $this->a01->id)->count());
    }
}
