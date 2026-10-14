<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Empty-location API: DELETE /api/v1/admin/wms/locations/{location}/products
 * (alias: DELETE /api/v1/admin/locations/{location}/products).
 *
 * Proves the dedicated endpoint exists, empties only eligible zero-stock
 * hints atomically, keeps the bin intact, preserves central stock, and
 * refuses unsafe operations with translated 422s.
 */
class WmsLocationEmptyTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Warehouse $otherWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
            'view-fulfillment', 'manage-fulfillment', 'picking-execute', 'inventory-adjust',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-EMPTY', 'name' => 'Empty', 'status' => 'active', 'is_default' => true,
        ]);
        $this->otherWarehouse = Warehouse::create([
            'code' => 'WH-EMPTY-B', 'name' => 'Empty B', 'status' => 'active', 'is_default' => false,
        ]);
    }

    private function manager(?int $home = null): User
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $home ?? $this->warehouse->id]);
        $user->givePermissionTo(['view-location', 'manage-location', 'view-fulfillment', 'manage-fulfillment', 'picking-execute', 'inventory-adjust']);

        return $user;
    }

    private function location(string $code, ?int $warehouseId = null, array $overrides = []): Location
    {
        return Location::create(array_merge([
            'warehouse_id' => $warehouseId ?? $this->warehouse->id,
            'code' => $code,
            'name' => 'Bin '.$code,
            'type' => Location::TYPE_STORAGE,
            'status' => Location::STATUS_ACTIVE,
        ], $overrides));
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Empty product',
            'slug' => 'empty-product-'.Str::random(8),
            'price' => 50,
            'product_type' => \Marvel\Enums\ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'quantity' => 0,
            'stock_quantity' => 100,
            'reserved_quantity' => 5,
            'sold_quantity' => 0,
        ], $overrides));
    }

    private function fulfillmentItem(ProductLocation $placement, ?Product $product = null, string $fulfillmentStatus = 'pending'): FulfillmentItem
    {
        $product ??= $placement->product()->firstOrFail();
        $order = $this->makeOrder($product);
        $fulfillment = Fulfillment::create([
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-EMPTY-'.Str::random(6),
            'status' => $fulfillmentStatus,
        ]);

        return FulfillmentItem::create([
            'fulfillment_id' => $fulfillment->id,
            'order_item_id' => $order->orderItems()->firstOrFail()->id,
            'product_id' => $product->id,
            'product_location_id' => $placement->id,
            'quantity' => 5,
            'quantity_picked' => 0,
            'status' => 'pending',
        ]);
    }

    private function makeOrder(Product $product): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'Empty', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 250, 'total_price' => 250,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => 'Empty product',
            'product_sku' => 'SKU-EMPTY', 'product_quantity' => 5,
            'product_price' => 50, 'product_total_price' => 250,
        ]);

        return $order->refresh();
    }

    // ---------- route + dispatch ----------

    public function test_exact_mission_uri_is_registered_as_delete(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/admin/wms/locations/{location}/products');

        $this->assertNotNull($route, 'mission URI api/v1/admin/wms/locations/{location}/products must be registered');
        $this->assertContains('DELETE', $route->methods());
        $this->assertSame('App\Http\Controllers\Api\Admin\Wms\LocationController@emptyProducts', $route->getActionName() === 'Closure' ? '' : $this->actionOf($route));
        $this->assertSame('api.admin.wms.locations.products.destroy', $route->getName());
    }

    public function test_conventional_alias_uri_is_registered_as_delete(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/admin/locations/{location}/products');

        $this->assertNotNull($route);
        $this->assertContains('DELETE', $route->methods());
        $this->assertSame('api.admin.locations.products.destroy', $route->getName());
    }

    private function actionOf($route): string
    {
        $action = $route->getAction();

        if (isset($action['controller'])) {
            return $action['controller'];
        }

        return $route->getActionName();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $loc = $this->location('E-401');

        $this->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products")->assertStatus(401);
        $this->deleteJson("/api/v1/admin/locations/{$loc->id}/products")->assertStatus(401);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $loc = $this->location('E-403');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products")
            ->assertStatus(403);
    }

    public function test_viewer_cannot_empty(): void
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $user->givePermissionTo(['view-location']);
        $loc = $this->location('E-VIEW');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products")
            ->assertStatus(403);
    }

    public function test_cross_warehouse_is_forbidden(): void
    {
        $foreign = $this->location('E-FOREIGN', $this->otherWarehouse->id);
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $user->givePermissionTo(['manage-location', 'view-location']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$foreign->id}/products")
            ->assertStatus(403);

        $this->assertDatabaseHas('locations', ['id' => $foreign->id]);
    }

    public function test_missing_location_returns_404(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->deleteJson('/api/v1/admin/wms/locations/999999/products')
            ->assertStatus(404);
    }

    // ---------- successful emptying ----------

    public function test_empty_removes_all_eligible_placements_and_keeps_location(): void
    {
        $manager = $this->manager();
        $product = $this->product();
        $loc = $this->location('E-OK');
        $p1 = ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);
        $p2 = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);

        $stockBefore = (int) $product->fresh()->stock_quantity;
        $reservedBefore = (int) $product->fresh()->reserved_quantity;

        $response = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.location_id', $loc->id)
            ->assertJsonPath('data.warehouse_id', $this->warehouse->id)
            ->assertJsonPath('data.removed', 2)
            ->assertJsonPath('data.remaining', 0);

        $removedIds = $response->json('data.removed_ids');
        $this->assertEqualsCanonicalizing([$p1->id, $p2->id], $removedIds);

        // All eligible removed, not just the first one.
        $this->assertDatabaseMissing('product_locations', ['id' => $p1->id]);
        $this->assertDatabaseMissing('product_locations', ['id' => $p2->id]);

        // Location itself remains present and active.
        $this->assertDatabaseHas('locations', ['id' => $loc->id]);
        $this->assertSame('active', $loc->fresh()->status);

        // Central stock + reservations untouched.
        $this->assertSame($stockBefore, (int) $product->fresh()->stock_quantity);
        $this->assertSame($reservedBefore, (int) $product->fresh()->reserved_quantity);

        // Translated success (never a raw key).
        $this->assertSame(__('wms.location.emptied'), $response->json('message'));
        $this->assertStringNotContainsString('wms.', $response->json('message'));
    }

    public function test_empty_via_conventional_alias_behaves_identically(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-ALIAS');
        $placement = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/locations/{$loc->id}/products")
            ->assertStatus(200)
            ->assertJsonPath('data.removed', 1);

        $this->assertDatabaseMissing('product_locations', ['id' => $placement->id]);
        $this->assertDatabaseHas('locations', ['id' => $loc->id]);
    }

    public function test_already_empty_is_idempotent_success(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-EMPTY');

        $first = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products");
        $first->assertStatus(200)->assertJsonPath('data.removed', 0);
        $this->assertSame(__('wms.location.already_empty'), $first->json('message'));

        $second = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products");
        $second->assertStatus(200)->assertJsonPath('data.removed', 0);
        $this->assertSame(__('wms.location.already_empty'), $second->json('message'));

        $this->assertDatabaseHas('locations', ['id' => $loc->id]);
    }

    public function test_repeated_empty_after_success_reports_already_empty(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-REPLAY');
        $placement = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products")
            ->assertStatus(200)
            ->assertJsonPath('data.removed', 1);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products")
            ->assertStatus(200)
            ->assertJsonPath('data.removed', 0)
            ->assertJsonPath('data.remaining', 0);

        $this->assertDatabaseMissing('product_locations', ['id' => $placement->id]);
        $this->assertDatabaseHas('locations', ['id' => $loc->id]);
    }

    public function test_unrelated_locations_are_preserved(): void
    {
        $manager = $this->manager();
        $product = $this->product();
        $target = $this->location('E-TARGET');
        $other = $this->location('E-OTHER');
        $victim = ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $target->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);
        $kept = ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $other->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$target->id}/products")
            ->assertStatus(200);

        $this->assertDatabaseMissing('product_locations', ['id' => $victim->id]);
        $this->assertDatabaseHas('product_locations', ['id' => $kept->id]);
        $this->assertDatabaseHas('locations', ['id' => $other->id]);
    }

    public function test_order_inventory_state_is_unchanged(): void
    {
        $manager = $this->manager();
        $product = $this->product();
        $loc = $this->location('E-ORDER');
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);
        $order = $this->makeOrder($product);
        $stateBefore = $order->inventory_state;

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products")
            ->assertStatus(200);

        $this->assertSame($stateBefore, $order->fresh()->inventory_state);
        $this->assertSame(Order::INVENTORY_STATE_COMMITTED, $order->fresh()->inventory_state);
    }

    public function test_location_remains_usable_for_receiving_after_empty(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-REUSE');
        $placement = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products")
            ->assertStatus(200);

        $this->assertDatabaseMissing('product_locations', ['id' => $placement->id]);

        // Bin is still active and placeable: existing deletion behaviour intact
        // is proven by deactivation still working (history-preserving path).
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/locations/{$loc->id}/deactivate")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');
    }

    // ---------- unsafe conditions (no partial changes) ----------

    public function test_physical_stock_blocks_empty_and_preserves_everything(): void
    {
        $manager = $this->manager();
        $product = $this->product(['stock_quantity' => 100, 'reserved_quantity' => 5]);
        $loc = $this->location('E-STOCK');
        $placement = ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 12, 'allocated_hint' => 0,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products");

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(__('wms.location.empty_stock', ['location' => $loc->id]), $response->json('message'));

        // Nothing removed: no partial change, central authority intact.
        $this->assertDatabaseHas('product_locations', ['id' => $placement->id]);
        $this->assertSame(100, (int) $product->fresh()->stock_quantity);
        $this->assertSame(5, (int) $product->fresh()->reserved_quantity);
        $this->assertDatabaseHas('locations', ['id' => $loc->id]);
    }

    public function test_allocated_hint_blocks_empty(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-HINT');
        // DB guard: allocated_hint <= quantity, so a hint always rides on
        // live quantity — both guards refuse, stock message wins.
        $placement = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 5, 'allocated_hint' => 3,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products")
            ->assertStatus(422);

        $this->assertDatabaseHas('product_locations', ['id' => $placement->id]);
    }

    public function test_mixed_stock_and_empty_rows_refuses_entire_operation(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-MIXED');
        $emptyRow = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);
        $stockRow = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 4, 'allocated_hint' => 0,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products")
            ->assertStatus(422);

        // All-or-nothing: even the eligible row survives the refusal.
        $this->assertDatabaseHas('product_locations', ['id' => $emptyRow->id]);
        $this->assertDatabaseHas('product_locations', ['id' => $stockRow->id]);
    }

    public function test_open_picking_task_blocks_empty(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-TASK');

        foreach (['pending', 'assigned', 'picking'] as $i => $status) {
            $bin = $this->location("E-TASK-{$i}");
            $placement = ProductLocation::create([
                'product_id' => $this->product()->id, 'location_id' => $bin->id,
                'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
            ]);
            // Isolate the OPEN-TASK guard (M3): close the pre-task
            // commitment window first so a wrong/empty open-status set cannot
            // hide behind the commitment guard.
            $item = $this->fulfillmentItem($placement);
            $item->update(['quantity_picked' => $item->quantity, 'status' => 'picked']);
            $item->fulfillment()->update(['status' => 'picked']);
            PickingTask::create([
                'fulfillment_item_id' => $item->id,
                'product_location_id' => $placement->id,
                'quantity_to_pick' => 2, 'quantity_picked' => 0, 'status' => $status,
            ]);

            $response = $this->actingAs($manager, 'sanctum')
                ->deleteJson("/api/v1/admin/wms/locations/{$bin->id}/products");

            $response->assertStatus(422)->assertJsonPath('success', false);
            $this->assertSame(
                __('wms.location.empty_tasks', ['id' => $bin->id]),
                $response->json('message')
            );
            $this->assertDatabaseHas('product_locations', ['id' => $placement->id]);
        }

        // Control location without tasks still empties (tested elsewhere).
        $this->assertDatabaseHas('locations', ['id' => $loc->id]);
    }

    public function test_unpicked_fulfillment_commitment_blocks_empty(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-COMMIT');
        $placement = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);
        // Pinned commitment, no picking task yet (pre-task window).
        $this->fulfillmentItem($placement);

        $response = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products");

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(
            __('wms.location.empty_commitment', ['id' => $loc->id]),
            $response->json('message')
        );
        $this->assertDatabaseHas('product_locations', ['id' => $placement->id]);
    }

    public function test_picking_history_blocks_empty_and_suggests_deactivate(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-HIST');
        $placement = ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);
        $item = $this->fulfillmentItem($placement);
        // Close the pre-task commitment window so the HISTORY guard is the
        // one under test: fully picked item on a picked fulfillment.
        $item->update(['quantity_picked' => $item->quantity, 'status' => 'picked']);
        $item->fulfillment()->update(['status' => 'picked']);
        PickingTask::create([
            'fulfillment_item_id' => $item->id,
            'product_location_id' => $placement->id,
            'quantity_to_pick' => 2, 'quantity_picked' => 2, 'status' => 'picked',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products")
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);

        $this->assertDatabaseHas('product_locations', ['id' => $placement->id]);

        // History-preserving path still works.
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/locations/{$loc->id}/deactivate")
            ->assertStatus(200);
    }

    // ---------- localization + regression ----------

    public function test_business_errors_are_translated_not_raw_keys(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-L10N');
        ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 7, 'allocated_hint' => 0,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products");

        $response->assertStatus(422);
        $message = (string) $response->json('message');
        $this->assertNotSame('wms.location.empty_stock', $message);
        $this->assertStringNotContainsString('wms.', $message);
        $this->assertStringNotContainsString('Exception', $message);
    }

    public function test_arabic_locale_returns_arabic_message(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-AR');
        ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);

        // Prove the Arabic catalogue entry itself (S2): resolve under ar
        // locale instead of asserting the en string under an ar header.
        $previous = app()->getLocale();
        app()->setLocale('ar');
        try {
            $expected = __('wms.location.emptied');
            $this->assertSame('تم إفراغ الموقع بنجاح.', $expected);
        } finally {
            app()->setLocale($previous);
        }

        $response = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products");

        $response->assertStatus(200);
        $this->assertStringNotContainsString('wms.', (string) $response->json('message'));
    }

    public function test_pending_return_blocks_empty(): void
    {
        $manager = $this->manager();
        $product = $this->product();
        $loc = $this->location('E-RET-ACT');
        $placement = ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);

        $order = $this->makeOrder($product);
        $request = \App\Models\Fulfillment\ReturnRequest::create([
            'return_number' => 'RET-'.Str::random(8),
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $order->user_id,
            'status' => 'pending',
        ]);
        \App\Models\Fulfillment\ReturnItem::create([
            'return_request_id' => $request->id,
            'order_item_id' => $order->orderItems()->firstOrFail()->id,
            'product_id' => $product->id,
            'quantity_returned' => 1,
            'quantity_approved' => 1,
            'quantity_restocked' => 0,
            'condition' => 'good',
            'product_location_id' => $placement->id,
            'restocked_at' => null,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products");

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(
            __('wms.location.empty_return', ['id' => $loc->id]),
            $response->json('message')
        );
        $this->assertDatabaseHas('product_locations', ['id' => $placement->id]);
    }

    public function test_completed_return_history_is_preserved_as_row(): void
    {
        $manager = $this->manager();
        $product = $this->product();
        $loc = $this->location('E-RET-DONE');
        $placement = ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
        ]);

        $order = $this->makeOrder($product);
        $request = \App\Models\Fulfillment\ReturnRequest::create([
            'return_number' => 'RET-'.Str::random(8),
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $order->user_id,
            'status' => 'completed',
        ]);
        $returnItem = \App\Models\Fulfillment\ReturnItem::create([
            'return_request_id' => $request->id,
            'order_item_id' => $order->orderItems()->firstOrFail()->id,
            'product_id' => $product->id,
            'quantity_returned' => 1,
            'quantity_approved' => 1,
            'quantity_restocked' => 1,
            'condition' => 'good',
            'product_location_id' => $placement->id,
            'restocked_at' => now(),
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/wms/locations/{$loc->id}/products")
            ->assertStatus(200)
            ->assertJsonPath('data.removed', 1);

        // Placement gone, but the return history row survives (FK SET NULL).
        $this->assertDatabaseMissing('product_locations', ['id' => $placement->id]);
        $this->assertDatabaseHas('return_items', ['id' => $returnItem->id]);
        $this->assertNull($returnItem->fresh()->product_location_id);
        $this->assertDatabaseHas('locations', ['id' => $loc->id]);
    }

    public function test_destroy_still_requires_stock_transfer_first(): void
    {
        $manager = $this->manager();
        $loc = $this->location('E-DEL');
        ProductLocation::create([
            'product_id' => $this->product()->id, 'location_id' => $loc->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 5, 'allocated_hint' => 0,
        ]);

        // Regression: existing delete guard untouched by the new endpoint.
        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/admin/locations/{$loc->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('locations', ['id' => $loc->id]);
    }
}
