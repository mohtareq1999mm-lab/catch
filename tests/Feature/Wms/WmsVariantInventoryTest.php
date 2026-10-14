<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Inventory\InventoryOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Variant-aware inventory operations (mandatory extension).
 *
 * Covers the 20 required cases: simple + variant receive/transfer,
 * sibling isolation, reservations, warehouse/location guards, occupancy,
 * idempotency, concurrency-safe rollback, placements/activity identity,
 * stats without double-counting, auth isolation, l10n, backward compat.
 */
class WmsVariantInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Warehouse $other;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['view-location', 'manage-location', 'inventory-adjust', 'manage-warehouse', 'view-warehouse'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create(['code' => 'WH-VAR', 'name' => 'Var', 'status' => 'active', 'is_default' => true]);
        $this->other = Warehouse::create(['code' => 'WH-VAR-B', 'name' => 'Var B', 'status' => 'active', 'is_default' => false]);
    }

    private function user(?int $home = null, array $perms = ['view-location', 'inventory-adjust']): User
    {
        $u = User::factory()->create(['type' => 'staff', 'warehouse_id' => $home ?? $this->warehouse->id]);
        $u->givePermissionTo($perms);

        return $u;
    }

    private function manager(): User
    {
        return $this->user(null, ['view-location', 'manage-location', 'inventory-adjust', 'manage-warehouse', 'view-warehouse']);
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
            'name' => 'T-Shirt', 'slug' => 'tshirt-'.Str::random(8),
            'price' => 200, 'product_type' => 'variable', 'status' => 1,
            'in_stock' => true, 'quantity' => 0, 'stock_quantity' => 0,
            'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);
        $blackS = ProductVariant::create(['product_id' => $p->id, 'sku' => 'TSHIRT-BLK-S-'.Str::random(4), 'price' => 200, 'stock_quantity' => 20, 'reserved_quantity' => 3, 'sold_quantity' => 0, 'in_stock' => true]);
        $blackL = ProductVariant::create(['product_id' => $p->id, 'sku' => 'TSHIRT-BLK-L-'.Str::random(4), 'price' => 200, 'stock_quantity' => 50, 'reserved_quantity' => 10, 'sold_quantity' => 0, 'in_stock' => true]);
        $whiteL = ProductVariant::create(['product_id' => $p->id, 'sku' => 'TSHIRT-WHT-L-'.Str::random(4), 'price' => 200, 'stock_quantity' => 15, 'reserved_quantity' => 2, 'sold_quantity' => 0, 'in_stock' => true]);

        return [$p, $blackS, $blackL, $whiteL];
    }

    // 1. Receiving stock for a simple product.
    public function test_receive_simple_product(): void
    {
        $p = $this->simpleProduct();
        $loc = $this->location('V-R1');

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'quantity' => 10,
        ])->assertStatus(201)->assertJsonPath('success', true)
          ->assertJsonPath('data.operation.product_id', $p->id)
          ->assertJsonPath('data.operation.received_quantity', 10);

        $this->assertSame(10, (int) $p->fresh()->stock_quantity);
        $this->assertDatabaseHas('product_locations', ['product_id' => $p->id, 'location_id' => $loc->id, 'quantity' => 10]);
    }

    // 2. Receiving stock for a valid variant.
    public function test_receive_valid_variant(): void
    {
        [$p, $blackS, $blackL] = $this->variableProductWithVariants();
        $loc = $this->location('V-R2');

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id, 'quantity' => 5,
        ])->assertStatus(201)
          ->assertJsonPath('data.operation.product_variant_id', $blackL->id);

        $this->assertSame(55, (int) $blackL->fresh()->stock_quantity);
        $this->assertSame(0, (int) $p->fresh()->stock_quantity);
        $this->assertDatabaseHas('product_locations', ['product_id' => $p->id, 'product_variant_id' => $blackL->id, 'location_id' => $loc->id]);
    }

    // 3. Rejecting a variant that does not belong to the parent.
    public function test_reject_foreign_variant(): void
    {
        [$p] = $this->variableProductWithVariants();
        $other = $this->simpleProduct();
        $foreignVariant = ProductVariant::create(['product_id' => $other->id, 'sku' => 'FOR-'.Str::random(6), 'price' => 10, 'stock_quantity' => 5, 'in_stock' => true]);
        // Force $other to look variable so the mismatch path is isolated.
        $other->update(['product_type' => 'variable']);
        $loc = $this->location('V-R3');

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'product_variant_id' => $foreignVariant->id, 'quantity' => 2,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('product_locations', ['product_variant_id' => $foreignVariant->id, 'location_id' => $loc->id]);
    }

    // 4. Rejecting a missing variant when required.
    public function test_reject_missing_variant_for_variable(): void
    {
        [$p] = $this->variableProductWithVariants();
        $loc = $this->location('V-R4');

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'quantity' => 2,
        ])->assertStatus(422);

        $this->assertSame(0, (int) ProductLocation::where('product_id', $p->id)->count());
    }

    // 5. Sibling isolation on receive.
    public function test_receive_does_not_touch_siblings(): void
    {
        [$p, $blackS, $blackL, $whiteL] = $this->variableProductWithVariants();
        $loc = $this->location('V-R5');

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id, 'quantity' => 10,
        ])->assertStatus(201);

        $this->assertSame(60, (int) $blackL->fresh()->stock_quantity);
        $this->assertSame(20, (int) $blackS->fresh()->stock_quantity);
        $this->assertSame(15, (int) $whiteL->fresh()->stock_quantity);
    }

    // 6+7+8. Transfer preserves variant total, siblings unchanged.
    public function test_transfer_variant_preserves_total_and_siblings(): void
    {
        [$p, $blackS, $blackL] = $this->variableProductWithVariants();
        $src = $this->location('V-TS');
        $dst = $this->location('V-TD');

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$src->id}/stock-receipts", [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id, 'quantity' => 30,
        ])->assertStatus(201);

        $beforeTotal = (int) $blackL->fresh()->stock_quantity; // 80
        $sibBefore = (int) $blackS->fresh()->stock_quantity;

        $this->actingAs($this->manager(), 'sanctum')->postJson('/api/v1/admin/stock-transfers', [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id,
            'source_location_id' => $src->id, 'destination_location_id' => $dst->id, 'quantity' => 10,
        ])->assertStatus(201)
          ->assertJsonPath('data.operation.transferred_quantity', 10)
          ->assertJsonPath('data.operation.product_variant_id', $blackL->id);

        $this->assertSame($beforeTotal, (int) $blackL->fresh()->stock_quantity);
        $this->assertSame($sibBefore, (int) $blackS->fresh()->stock_quantity);
        $srcPl = ProductLocation::where('location_id', $src->id)->where('product_variant_id', $blackL->id)->firstOrFail();
        $dstPl = ProductLocation::where('location_id', $dst->id)->where('product_variant_id', $blackL->id)->firstOrFail();
        $this->assertEquals(20, (float) $srcPl->quantity);
        $this->assertEquals(10, (float) $dstPl->quantity);
    }

    // 9. Reject transfers exceeding transferable quantity.
    public function test_reject_excessive_transfer(): void
    {
        [$p, , $blackL] = $this->variableProductWithVariants();
        $src = $this->location('V-TE1');
        $dst = $this->location('V-TE2');

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$src->id}/stock-receipts", [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id, 'quantity' => 5,
        ])->assertStatus(201);

        // blackL had 50 + 5 = 55 authority; placement has 5 transferable.
        $this->actingAs($this->manager(), 'sanctum')->postJson('/api/v1/admin/stock-transfers', [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id,
            'source_location_id' => $src->id, 'destination_location_id' => $dst->id, 'quantity' => 999,
        ])->assertStatus(422);
    }

    // 10. Reserved inventory cannot be transferred (available = qty - hint).
    public function test_reserved_hint_blocks_transfer(): void
    {
        $p = $this->simpleProduct(['stock_quantity' => 50]);
        $src = $this->location('V-TR1');
        $dst = $this->location('V-TR2');

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$src->id}/stock-receipts", [
            'product_id' => $p->id, 'quantity' => 10,
        ])->assertStatus(201);

        $pl = ProductLocation::where('location_id', $src->id)->where('product_id', $p->id)->firstOrFail();
        $pl->update(['allocated_hint' => 10]); // fully hinted → 0 transferable

        $this->actingAs($this->manager(), 'sanctum')->postJson('/api/v1/admin/stock-transfers', [
            'product_id' => $p->id,
            'source_location_id' => $src->id, 'destination_location_id' => $dst->id, 'quantity' => 1,
        ])->assertStatus(422);
    }

    // 11. Invalid warehouse/location combination.
    public function test_reject_foreign_warehouse_location(): void
    {
        $p = $this->simpleProduct();
        $foreign = $this->location('V-FW', $this->other->id);
        $user = $this->user($this->warehouse->id, ['inventory-adjust', 'view-location']);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/locations/{$foreign->id}/stock-receipts", [
            'product_id' => $p->id, 'quantity' => 2,
        ])->assertStatus(403);
    }

    // 12. Occupied-bin rules for variants (one SKU per bin).
    public function test_occupied_bin_rejects_different_variant(): void
    {
        [$p, $blackS, $blackL] = $this->variableProductWithVariants();
        $loc = $this->location('V-OCC');

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'product_variant_id' => $blackS->id, 'quantity' => 5,
        ])->assertStatus(201);

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id, 'quantity' => 5,
        ])->assertStatus(422);

        // Sibling simple vs variant also blocked.
        $simple = $this->simpleProduct();
        // (different product entirely → also occupied)
        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $simple->id, 'quantity' => 1,
        ])->assertStatus(422);
    }

    // 13. Idempotent replay without duplicate stock.
    public function test_idempotent_receipt_replays(): void
    {
        $p = $this->simpleProduct();
        $loc = $this->location('V-IDEM');
        $key = 'idem-'.Str::random(10);

        $first = $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'quantity' => 7, 'idempotency_key' => $key,
        ]);
        $first->assertStatus(201)->assertJsonPath('data.replayed', false);

        $second = $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'quantity' => 7, 'idempotency_key' => $key,
        ]);
        $second->assertStatus(200)->assertJsonPath('data.replayed', true);

        $this->assertSame(7, (int) $p->fresh()->stock_quantity);
        $this->assertSame(1, InventoryOperation::where('idempotency_key', $key)->count());
    }

    // 14. Failed transfer rolls back completely (no partial pair).
    public function test_failed_transfer_has_no_partial_writes(): void
    {
        $p = $this->simpleProduct(['stock_quantity' => 20]);
        $src = $this->location('V-RB1');
        $dst = $this->location('V-RB2');
        ProductLocation::create(['product_id' => $p->id, 'location_id' => $src->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 5, 'allocated_hint' => 0]);

        $this->actingAs($this->manager(), 'sanctum')->postJson('/api/v1/admin/stock-transfers', [
            'product_id' => $p->id,
            'source_location_id' => $src->id, 'destination_location_id' => $dst->id, 'quantity' => 999,
        ])->assertStatus(422);

        $this->assertEquals(5, (float) ProductLocation::where('location_id', $src->id)->firstOrFail()->quantity);
        $this->assertSame(0, (int) ProductLocation::where('location_id', $dst->id)->count());
        $this->assertSame(20, (int) $p->fresh()->stock_quantity);
    }

    // 15. Variant identity in placement responses.
    public function test_placements_expose_variant_identity(): void
    {
        [$p, $blackS, $blackL] = $this->variableProductWithVariants();
        $loc = $this->location('V-PL');
        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id, 'quantity' => 4,
        ])->assertStatus(201);

        $res = $this->actingAs($this->manager(), 'sanctum')
            ->getJson("/api/v1/admin/products/{$p->id}/placements?product_variant_id={$blackL->id}")
            ->assertStatus(200);

        $placements = $res->json('data.placements');
        $this->assertCount(1, $placements);
        $this->assertSame($blackL->id, $placements[0]['product_variant_id']);
        $this->assertSame($blackL->sku, $placements[0]['sku']);
        $this->assertArrayHasKey('allocated_hint', $placements[0]);
        $this->assertSame('informational-only', $placements[0]['allocated_hint_note']);
        $variants = $res->json('data.product.variants');
        $this->assertNotEmpty($variants);
    }

    // 16. Variant identity in activity records.
    public function test_activity_records_variant(): void
    {
        [$p, , $blackL] = $this->variableProductWithVariants();
        $loc = $this->location('V-ACT');
        $key = 'var-act-'.Str::random(8);

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id, 'quantity' => 3, 'reference' => 'PO-VAR-1',
            'idempotency_key' => $key,
        ])->assertStatus(201);

        $op = InventoryOperation::where('idempotency_key', $key)->firstOrFail();
        $this->assertSame($p->id, (int) $op->product_id);
        $this->assertSame($blackL->id, (int) $op->product_variant_id);
        $this->assertSame('PO-VAR-1', $op->reference);
        $this->assertSame($blackL->id, (int) ($op->result['product_variant_id'] ?? 0));
    }

    // 17. Stats do not double-count parent aggregates.
    public function test_overview_does_not_double_count(): void
    {
        $simple = $this->simpleProduct(['stock_quantity' => 10]);
        [$p, $blackS, $blackL] = $this->variableProductWithVariants();
        // Parent aggregate trap: set parent stock to 999 — overview must ignore it.
        $p->update(['stock_quantity' => 999]);

        $res = $this->actingAs($this->manager(), 'sanctum')
            ->getJson('/api/v1/admin/inventory/overview')
            ->assertStatus(200);

        // simple 10 + variants (20+50+15=85) = 95; parent 999 ignored.
        $this->assertSame(85, (int) $res->json('data.variant_units'));
        $this->assertSame(10, (int) $res->json('data.simple_units'));
        $total = (int) $res->json('data.total_sku_units');
        $this->assertSame(95, $total);
        $this->assertTrue($total < 999 + 100, 'parent aggregate must not be counted');
        $this->assertSame((int) $res->json('data.simple_units') + (int) $res->json('data.variant_units'), $total);
    }

    // 18. Authorization + warehouse isolation.
    public function test_warehouse_isolation_for_variant_ops(): void
    {
        [$p, , $blackL] = $this->variableProductWithVariants();
        $foreign = $this->location('V-ISO', $this->other->id);
        $scoped = $this->user($this->warehouse->id, ['inventory-adjust', 'view-location']);

        $this->actingAs($scoped, 'sanctum')->postJson("/api/v1/admin/locations/{$foreign->id}/stock-receipts", [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id, 'quantity' => 1,
        ])->assertStatus(403);

        // Scoped callers are confined to home: foreign filter is ignored and
        // the home-scoped list returns 200 (existing placements-contract
        // behavior — no enumeration of foreign bins).
        $this->actingAs($scoped, 'sanctum')
            ->getJson("/api/v1/admin/products/{$p->id}/placements?warehouse_id={$this->other->id}")
            ->assertStatus(200);

        $this->actingAs($scoped, 'sanctum')
            ->getJson('/api/v1/admin/inventory/overview')
            ->assertStatus(200);
    }

    // 19. Arabic + English messages.
    public function test_variant_messages_localized(): void
    {
        app()->setLocale('en');
        $this->assertSame('Variant #1 does not belong to product #2.', __('wms.stock.variant_mismatch', ['variant' => 1, 'product' => 2]));
        $prev = app()->getLocale();
        app()->setLocale('ar');
        try {
            $this->assertSame('المتغير #1 لا ينتمي إلى المنتج #2.', __('wms.stock.variant_mismatch', ['variant' => 1, 'product' => 2]));
            $this->assertSame('تم جلب نظرة المخزون بنجاح.', __('wms.stock.overview_fetched'));
        } finally {
            app()->setLocale($prev);
        }
    }

    // 20. Simple backward compat (no variant key → old shape + new nullable fields).
    public function test_simple_backward_compat(): void
    {
        $p = $this->simpleProduct();
        $loc = $this->location('V-BC');

        $res = $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'quantity' => 6,
        ])->assertStatus(201);

        $this->assertNull($res->json('data.operation.product_variant_id'));
        $this->assertArrayHasKey('product_id', $res->json('data.operation'));

        $pl = $this->actingAs($this->manager(), 'sanctum')->getJson("/api/v1/admin/products/{$p->id}/placements")->assertStatus(200);
        $this->assertNull($pl->json('data.placements.0.product_variant_id'));
        $this->assertFalse((bool) $pl->json('data.product.has_variants'));
    }

    public function test_missing_variant_filter_returns_404(): void
    {
        [$p] = $this->variableProductWithVariants();

        $this->actingAs($this->manager(), 'sanctum')
            ->getJson("/api/v1/admin/products/{$p->id}/placements?product_variant_id=999999999")
            ->assertStatus(404);
    }

    public function test_overview_totals_scope_is_global(): void
    {
        $res = $this->actingAs($this->manager(), 'sanctum')
            ->getJson('/api/v1/admin/inventory/overview')
            ->assertStatus(200);

        $this->assertSame('global', $res->json('data.totals_scope'));
    }

    public function test_transfer_result_carries_sku(): void
    {
        [$p, , $blackL] = $this->variableProductWithVariants();
        $src = $this->location('V-SKU-S');
        $dst = $this->location('V-SKU-D');

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$src->id}/stock-receipts", [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id, 'quantity' => 8,
        ])->assertStatus(201);

        $res = $this->actingAs($this->manager(), 'sanctum')->postJson('/api/v1/admin/stock-transfers', [
            'product_id' => $p->id, 'product_variant_id' => $blackL->id,
            'source_location_id' => $src->id, 'destination_location_id' => $dst->id, 'quantity' => 3,
        ])->assertStatus(201);

        $this->assertSame($blackL->sku, $res->json('data.operation.sku'));
        $this->assertSame($blackL->id, $res->json('data.operation.product_variant_id'));
    }
}
