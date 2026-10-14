<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Inventory\InventoryOperation;
use App\Services\Fulfillment\FulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Receiving reliability: idempotency-key payload binding (Case C),
 * operation-status resolution, confirmed-failure fallback to another
 * location (Case B), failure atomicity, and the assign-placement SKU
 * guard — for simple products and exact variants.
 *
 * Conventions (mirror WmsVariantInventoryTest / WmsMultiLocationTest):
 * - Authority is SKU-level: products.stock_quantity (simple),
 *   product_variants.stock_quantity (variants). Parents of variable
 *   products are never written.
 * - Placements are non-authoritative hints; one SKU per bin.
 * - Message assertions run in English (production default is Arabic).
 */
class WmsReceivingReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Warehouse $other;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['view-location', 'manage-location', 'inventory-adjust', 'manage-warehouse', 'view-warehouse', 'view-fulfillment', 'manage-fulfillment'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create(['code' => 'WH-REL', 'name' => 'Rel', 'status' => 'active', 'is_default' => true]);
        $this->other = Warehouse::create(['code' => 'WH-REL-B', 'name' => 'Rel B', 'status' => 'active', 'is_default' => false]);
    }

    private function manager(): User
    {
        $u = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $u->givePermissionTo(['view-location', 'manage-location', 'inventory-adjust', 'manage-warehouse', 'view-warehouse', 'view-fulfillment', 'manage-fulfillment']);

        return $u;
    }

    private function scoped(): User
    {
        $u = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $u->givePermissionTo(['view-location', 'inventory-adjust', 'view-fulfillment', 'manage-fulfillment']);

        return $u;
    }

    private function location(string $code, ?int $wh = null): Location
    {
        return Location::create([
            'warehouse_id' => $wh ?? $this->warehouse->id, 'code' => $code, 'name' => 'Bin '.$code,
            'type' => Location::TYPE_STORAGE, 'status' => Location::STATUS_ACTIVE,
        ]);
    }

    private function simpleProduct(array $over = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Simple '.Str::random(6), 'slug' => 'simple-'.Str::random(8),
            'price' => 100, 'product_type' => 'simple', 'status' => 1,
            'in_stock' => true, 'quantity' => 0, 'stock_quantity' => 0,
            'reserved_quantity' => 0, 'sold_quantity' => 0,
        ], $over));
    }

    private function variableProductWithVariants(): array
    {
        $p = Product::create([
            'name' => 'Tee', 'slug' => 'tee-'.Str::random(8),
            'price' => 200, 'product_type' => 'variable', 'status' => 1,
            'in_stock' => true, 'quantity' => 0, 'stock_quantity' => 0,
            'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);
        $s = ProductVariant::create(['product_id' => $p->id, 'sku' => 'TEE-S-'.Str::random(4), 'price' => 200, 'stock_quantity' => 20, 'reserved_quantity' => 0, 'sold_quantity' => 0, 'in_stock' => true]);
        $l = ProductVariant::create(['product_id' => $p->id, 'sku' => 'TEE-L-'.Str::random(4), 'price' => 200, 'stock_quantity' => 50, 'reserved_quantity' => 0, 'sold_quantity' => 0, 'in_stock' => true]);

        return [$p, $s, $l];
    }

    private function receive(User $actor, int $locationId, array $body)
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/admin/locations/{$locationId}/stock-receipts", $body);
    }

    // ---------- Case C: conflicting key reuse is rejected ----------

    public function test_conflicting_key_different_quantity_rejected(): void
    {
        $p = $this->simpleProduct();
        $loc = $this->location('R-C1');
        $key = 'rel-'.Str::random(10);

        $this->receive($this->manager(), $loc->id, [
            'product_id' => $p->id, 'quantity' => 7, 'idempotency_key' => $key,
        ])->assertStatus(201);

        $res = $this->receive($this->manager(), $loc->id, [
            'product_id' => $p->id, 'quantity' => 9, 'idempotency_key' => $key,
        ]);
        $res->assertStatus(409);
        $this->assertSame(__('wms.stock.idempotency_conflict', ['key' => $key]), $res->json('message'));

        // No second execution: authority and ledger keep the original write.
        $this->assertSame(7, (int) $p->fresh()->stock_quantity);
        $this->assertSame(1, InventoryOperation::where('idempotency_key', $key)->count());
        $this->assertEquals(7, (float) InventoryOperation::where('idempotency_key', $key)->firstOrFail()->quantity);
    }

    public function test_conflicting_key_different_destination_rejected(): void
    {
        $p = $this->simpleProduct();
        $a = $this->location('R-C2A');
        $b = $this->location('R-C2B');
        $key = 'rel-'.Str::random(10);

        $this->receive($this->manager(), $a->id, [
            'product_id' => $p->id, 'quantity' => 5, 'idempotency_key' => $key,
        ])->assertStatus(201);

        $this->receive($this->manager(), $b->id, [
            'product_id' => $p->id, 'quantity' => 5, 'idempotency_key' => $key,
        ])->assertStatus(409);

        $this->assertSame(5, (int) $p->fresh()->stock_quantity);
        $this->assertSame(0, (int) ProductLocation::where('location_id', $b->id)->count());
    }

    public function test_conflicting_key_different_product_rejected(): void
    {
        $p = $this->simpleProduct();
        $q = $this->simpleProduct();
        $loc = $this->location('R-C3');
        $key = 'rel-'.Str::random(10);

        $this->receive($this->manager(), $loc->id, [
            'product_id' => $p->id, 'quantity' => 4, 'idempotency_key' => $key,
        ])->assertStatus(201);

        $this->receive($this->manager(), $loc->id, [
            'product_id' => $q->id, 'quantity' => 4, 'idempotency_key' => $key,
        ])->assertStatus(409);

        $this->assertSame(4, (int) $p->fresh()->stock_quantity);
        $this->assertSame(0, (int) $q->fresh()->stock_quantity);
    }

    public function test_conflicting_key_different_variant_rejected(): void
    {
        [$p, $s, $l] = $this->variableProductWithVariants();
        $loc = $this->location('R-C4');
        $key = 'rel-'.Str::random(10);

        $this->receive($this->manager(), $loc->id, [
            'product_id' => $p->id, 'product_variant_id' => $s->id, 'quantity' => 3, 'idempotency_key' => $key,
        ])->assertStatus(201);

        // Same key, sibling variant: must not replay and must not post.
        $this->receive($this->manager(), $loc->id, [
            'product_id' => $p->id, 'product_variant_id' => $l->id, 'quantity' => 3, 'idempotency_key' => $key,
        ])->assertStatus(409);

        $this->assertSame(23, (int) $s->fresh()->stock_quantity);
        $this->assertSame(50, (int) $l->fresh()->stock_quantity);
        $this->assertSame(0, (int) $p->fresh()->stock_quantity);
    }

    public function test_cross_operation_key_reuse_rejected(): void
    {
        $p = $this->simpleProduct();
        $loc = $this->location('R-C7');
        $dst = $this->location('R-C8');
        $mgr = $this->manager();

        $this->receive($mgr, $loc->id, ['product_id' => $p->id, 'quantity' => 6])->assertStatus(201);

        $key = 'rel-'.Str::random(10);
        $this->receive($mgr, $loc->id, [
            'product_id' => $p->id, 'quantity' => 4, 'idempotency_key' => $key,
        ])->assertStatus(201);

        // Same key, same product/quantity, but a TRANSFER instead of a
        // receipt: the operation binding refuses with 409 and moves nothing.
        $res = $this->actingAs($mgr, 'sanctum')->postJson('/api/v1/admin/stock-transfers', [
            'product_id' => $p->id,
            'source_location_id' => $loc->id, 'destination_location_id' => $dst->id,
            'quantity' => 4, 'idempotency_key' => $key,
        ]);
        $res->assertStatus(409);
        $this->assertSame(__('wms.stock.idempotency_conflict', ['key' => $key]), $res->json('message'));

        $this->assertSame(10, (int) $p->fresh()->stock_quantity);
        $this->assertEquals(10, (float) ProductLocation::where('location_id', $loc->id)->firstOrFail()->quantity);
        $this->assertSame(0, (int) ProductLocation::where('location_id', $dst->id)->count());
    }

    public function test_transfer_conflicting_key_rejected(): void
    {
        $p = $this->simpleProduct();
        $src = $this->location('R-C5S');
        $dst = $this->location('R-C5D');
        $mgr = $this->manager();

        $this->receive($mgr, $src->id, ['product_id' => $p->id, 'quantity' => 10])->assertStatus(201);

        $key = 'rel-'.Str::random(10);
        $transfer = ['product_id' => $p->id, 'source_location_id' => $src->id, 'destination_location_id' => $dst->id, 'quantity' => 4, 'idempotency_key' => $key];

        $this->actingAs($mgr, 'sanctum')->postJson('/api/v1/admin/stock-transfers', $transfer)->assertStatus(201);

        $conflict = $transfer;
        $conflict['quantity'] = 6;
        $this->actingAs($mgr, 'sanctum')->postJson('/api/v1/admin/stock-transfers', $conflict)->assertStatus(409);

        // Single execution: source 6, destination 4, authority 10.
        $this->assertEquals(6, (float) ProductLocation::where('location_id', $src->id)->firstOrFail()->quantity);
        $this->assertEquals(4, (float) ProductLocation::where('location_id', $dst->id)->firstOrFail()->quantity);
        $this->assertSame(10, (int) $p->fresh()->stock_quantity);
    }

    public function test_reference_only_difference_still_replays(): void
    {
        $p = $this->simpleProduct();
        $loc = $this->location('R-C6');
        $key = 'rel-'.Str::random(10);

        $this->receive($this->manager(), $loc->id, [
            'product_id' => $p->id, 'quantity' => 5, 'reference' => 'PO-1', 'idempotency_key' => $key,
        ])->assertStatus(201);

        // Reference is operator metadata, not binding identity: same stock
        // move replays instead of conflicting.
        $this->receive($this->manager(), $loc->id, [
            'product_id' => $p->id, 'quantity' => 5, 'reference' => 'PO-2', 'idempotency_key' => $key,
        ])->assertStatus(200)->assertJsonPath('data.replayed', true);

        $this->assertSame(5, (int) $p->fresh()->stock_quantity);
    }

    // ---------- Case C: operation-status resolution ----------

    public function test_operation_status_returns_completed(): void
    {
        $p = $this->simpleProduct();
        $loc = $this->location('R-S1');
        $key = 'rel-'.Str::random(10);

        $this->receive($this->manager(), $loc->id, [
            'product_id' => $p->id, 'quantity' => 8, 'reference' => 'PO-S1', 'idempotency_key' => $key,
        ])->assertStatus(201);

        $res = $this->actingAs($this->manager(), 'sanctum')
            ->getJson("/api/v1/admin/inventory/operations/{$key}")
            ->assertStatus(200);

        $this->assertSame($key, $res->json('data.key'));
        $this->assertSame('receipt', $res->json('data.operation'));
        $this->assertSame('completed', $res->json('data.status'));
        $this->assertSame($p->id, $res->json('data.product_id'));
        $this->assertNull($res->json('data.product_variant_id'));
        $this->assertSame($loc->id, $res->json('data.destination_location_id'));
        $this->assertEquals(8, (float) $res->json('data.quantity'));
        $this->assertSame('PO-S1', $res->json('data.reference'));
        $this->assertSame(8, (int) $res->json('data.result.received_quantity'));
    }

    public function test_operation_status_unknown_key_404(): void
    {
        $this->actingAs($this->manager(), 'sanctum')
            ->getJson('/api/v1/admin/inventory/operations/rel-no-such-key')
            ->assertStatus(404);
    }

    public function test_operation_status_reports_processing(): void
    {
        $p = $this->simpleProduct();
        $loc = $this->location('R-S2');
        $key = 'rel-'.Str::random(10);

        InventoryOperation::create([
            'idempotency_key' => $key, 'operation' => InventoryOperation::OPERATION_RECEIPT,
            'product_id' => $p->id, 'warehouse_id' => $this->warehouse->id,
            'destination_location_id' => $loc->id, 'quantity' => 2,
            'status' => InventoryOperation::STATUS_PROCESSING,
        ]);

        $res = $this->actingAs($this->manager(), 'sanctum')
            ->getJson("/api/v1/admin/inventory/operations/{$key}")
            ->assertStatus(200);

        $this->assertSame('processing', $res->json('data.status'));
    }

    public function test_operation_status_cross_warehouse_hidden(): void
    {
        $p = $this->simpleProduct();
        $foreign = $this->location('R-S3', $this->other->id);
        $key = 'rel-'.Str::random(10);

        InventoryOperation::create([
            'idempotency_key' => $key, 'operation' => InventoryOperation::OPERATION_RECEIPT,
            'product_id' => $p->id, 'warehouse_id' => $this->other->id,
            'destination_location_id' => $foreign->id, 'quantity' => 1,
            'status' => InventoryOperation::STATUS_COMPLETED, 'result' => ['product_id' => $p->id],
        ]);

        // Scoped callers cannot enumerate foreign operations.
        $this->actingAs($this->scoped(), 'sanctum')
            ->getJson("/api/v1/admin/inventory/operations/{$key}")
            ->assertStatus(404);

        // Global holders resolve any operation.
        $this->actingAs($this->manager(), 'sanctum')
            ->getJson("/api/v1/admin/inventory/operations/{$key}")
            ->assertStatus(200);
    }

    // ---------- Case B: confirmed failure, then another location ----------

    public function test_fallback_to_another_location_after_occupied_failure(): void
    {
        $mgr = $this->manager();
        $occupier = $this->simpleProduct();
        $product = $this->simpleProduct();
        $busy = $this->location('R-F1');
        $free = $this->location('R-F2');

        $this->receive($mgr, $busy->id, ['product_id' => $occupier->id, 'quantity' => 10])->assertStatus(201);

        $failed = $this->receive($mgr, $busy->id, ['product_id' => $product->id, 'quantity' => 5]);
        $failed->assertStatus(422);
        $this->assertSame(
            __('wms.stock.location_occupied', ['location' => $busy->id, 'occupant' => $occupier->id, 'product' => $product->id]),
            $failed->json('message')
        );

        // Confirmed failure committed nothing.
        $this->assertSame(0, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, (int) ProductLocation::where('product_id', $product->id)->count());

        // New explicit attempt elsewhere succeeds exactly once.
        $this->receive($mgr, $free->id, ['product_id' => $product->id, 'quantity' => 5])->assertStatus(201);

        $this->assertSame(5, (int) $product->fresh()->stock_quantity);
        $this->assertEquals(5, (float) ProductLocation::where('product_id', $product->id)->where('location_id', $free->id)->firstOrFail()->quantity);

        $placements = $this->actingAs($mgr, 'sanctum')
            ->getJson("/api/v1/admin/products/{$product->id}/placements")
            ->assertStatus(200)
            ->json('data.placements');
        $this->assertCount(1, $placements);
        $this->assertSame($free->id, (int) $placements[0]['location_id']);
    }

    public function test_failed_variant_receipt_leaves_no_partial_writes(): void
    {
        [$p, $s, $l] = $this->variableProductWithVariants();
        $mgr = $this->manager();
        $bin = $this->location('R-F3');

        $this->receive($mgr, $bin->id, [
            'product_id' => $p->id, 'product_variant_id' => $s->id, 'quantity' => 4,
        ])->assertStatus(201);

        $sBefore = (int) $s->fresh()->stock_quantity;
        $lBefore = (int) $l->fresh()->stock_quantity;

        $this->receive($mgr, $bin->id, [
            'product_id' => $p->id, 'product_variant_id' => $l->id, 'quantity' => 6,
        ])->assertStatus(422);

        $this->assertSame($sBefore, (int) $s->fresh()->stock_quantity);
        $this->assertSame($lBefore, (int) $l->fresh()->stock_quantity);
        $this->assertSame(0, (int) $p->fresh()->stock_quantity);
        $this->assertSame(0, (int) ProductLocation::where('product_id', $p->id)->where('product_variant_id', $l->id)->count());
    }

    // ---------- SKU guard on placement assignment ----------

    public function test_assign_placement_variant_mismatch_refused(): void
    {
        [$p, $s, $l] = $this->variableProductWithVariants();
        $mgr = $this->manager();
        $binS = $this->location('R-A1');
        $binL = $this->location('R-A2');

        $this->receive($mgr, $binS->id, [
            'product_id' => $p->id, 'product_variant_id' => $s->id, 'quantity' => 10,
        ])->assertStatus(201);
        $this->receive($mgr, $binL->id, [
            'product_id' => $p->id, 'product_variant_id' => $l->id, 'quantity' => 10,
        ])->assertStatus(201);

        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'REL-A', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $s->product_id, 'product_variant_id' => $s->id,
            'product_name' => 'Tee S', 'product_sku' => $s->sku,
            'product_quantity' => 2, 'product_price' => 200, 'product_total_price' => 400,
        ]);

        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $this->warehouse->id, 'rel-assign-1');
        $item = $fulfillment->items()->where('product_variant_id', $s->id)->firstOrFail();

        $siblingPlacement = ProductLocation::where('location_id', $binL->id)
            ->where('product_variant_id', $l->id)->firstOrFail();

        $res = $this->actingAs($mgr, 'sanctum')->postJson(
            "/api/v1/admin/fulfillment-items/{$item->id}/assign-placement",
            ['product_location_id' => $siblingPlacement->id]
        );
        $res->assertStatus(422);
        $this->assertSame(__('wms.fulfillment.placement_variant'), $res->json('message'));

        // Refusal changed nothing.
        $this->assertNotSame($siblingPlacement->id, (int) $item->fresh()->product_location_id);
    }
}
