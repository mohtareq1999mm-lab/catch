<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as MarvelPermission;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Product/variant/location contracts + catalog backfill (AC-01..AC-22).
 *
 * Covers: dry-run writes nothing, apply creates hints without touching
 * authority, apply rejects invalid mappings, reruns are idempotent,
 * product create validates placement, product update preserves placements,
 * simple products are valid without variants.
 *
 * Isolated MySQL only (RefreshDatabase). Sequential (shared test DB).
 */
class ProductCatalogBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-location', 'manage-location', 'inventory-adjust', 'manage-warehouse', 'view-warehouse',
            MarvelPermission::VIEW_PRODUCTS, MarvelPermission::CREATE_PRODUCT,
            MarvelPermission::UPDATE_PRODUCT, MarvelPermission::DELETE_PRODUCT,
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create(['code' => 'WH-CAT', 'name' => 'Catalog', 'status' => 'active', 'is_default' => true]);
    }

    private function user(?int $home = null, array $perms = []): User
    {
        $u = User::factory()->create(['type' => 'staff', 'warehouse_id' => $home ?? $this->warehouse->id]);
        foreach ($perms as $perm) {
            $u->givePermissionTo($perm);
        }

        return $u;
    }

    private function manager(): User
    {
        return $this->user(null, ['view-location', 'manage-location', 'inventory-adjust', 'manage-warehouse', 'view-warehouse']);
    }

    private function productAdmin(): User
    {
        return $this->user(null, [MarvelPermission::VIEW_PRODUCTS, MarvelPermission::CREATE_PRODUCT, MarvelPermission::UPDATE_PRODUCT, 'inventory-adjust', 'manage-warehouse']);
    }

    private function bin(string $code, string $type = Location::TYPE_STORAGE, string $status = Location::STATUS_ACTIVE): Location
    {
        return Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => $code, 'name' => 'Bin ' . $code,
            'type' => $type, 'status' => $status,
        ]);
    }

    private function simpleProduct(array $over = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Simple ' . Str::random(6)], 'slug' => 'simple-' . Str::random(8),
            'description' => ['en' => 'desc'],
            'price' => 100, 'product_type' => 'simple', 'status' => 1,
            'in_stock' => true, 'quantity' => 0, 'stock_quantity' => 0,
            'reserved_quantity' => 0, 'sold_quantity' => 0,
        ], $over));
    }

    // ---------- reconciliation dry-run ----------

    public function test_reconcile_dry_run_writes_nothing(): void
    {
        $p = $this->simpleProduct(['stock_quantity' => 10]);
        $loc = $this->bin('R-01');
        ProductLocation::create([
            'product_id' => $p->id, 'product_variant_id' => null,
            'location_id' => $loc->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 10, 'allocated_hint' => 0,
        ]);
        $before = [Product::count(), ProductVariant::count(), ProductLocation::count()];

        $code = \Illuminate\Support\Facades\Artisan::call('inventory:reconcile-catalog', ['--chunk' => 50]);

        $this->assertSame(0, $code);
        $this->assertSame($before, [Product::count(), ProductVariant::count(), ProductLocation::count()]);
    }

    public function test_reconcile_reports_unplaced_stock_as_findings(): void
    {
        $this->simpleProduct(['stock_quantity' => 7]);

        $code = \Illuminate\Support\Facades\Artisan::call('inventory:reconcile-catalog', ['--json' => true]);

        $this->assertSame(1, $code); // findings, not clean — and nothing written
        $this->assertSame(0, ProductLocation::count());
    }

    public function test_reconcile_rejects_unknown_warehouse(): void
    {
        $code = \Illuminate\Support\Facades\Artisan::call('inventory:reconcile-catalog', ['--warehouse' => 999999]);

        $this->assertSame(2, $code);
        $this->assertSame(0, ProductLocation::count());
    }

    public function test_reconcile_dry_run_writes_no_audit_or_ledger_rows(): void
    {
        $p = $this->simpleProduct(['stock_quantity' => 7]);
        $opsBefore = \App\Models\Inventory\InventoryOperation::count();
        $logBefore = \Spatie\Activitylog\Models\Activity::count();

        \Illuminate\Support\Facades\Artisan::call('inventory:reconcile-catalog', []);

        $this->assertSame($opsBefore, \App\Models\Inventory\InventoryOperation::count());
        $this->assertSame($logBefore, \Spatie\Activitylog\Models\Activity::count());
        $this->assertSame(7, (int) $p->fresh()->stock_quantity);
    }

    // ---------- canonical apply workflow: inventory:backfill-placements ----------
    // Mapping-driven writes belong to exactly one entry point (AC-02). These
    // tests exercise that canonical command; reconcile-catalog stays
    // read-only by design.

    private function mappingFile(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catmap') . '.json';
        file_put_contents($path, json_encode($entries));

        return $path;
    }

    private function backfill(string $path, bool $apply = false): int
    {
        $params = ['--mapping' => $path, '--json' => true];
        if ($apply) {
            $params['--apply'] = true;
        }

        return \Illuminate\Support\Facades\Artisan::call('inventory:backfill-placements', $params);
    }

    public function test_backfill_dry_run_validates_without_writing(): void
    {
        $p = $this->simpleProduct(['stock_quantity' => 30]);
        $loc = $this->bin('A-00');
        $path = $this->mappingFile([
            ['product_id' => $p->id, 'location_id' => $loc->id, 'quantity' => 30],
        ]);

        $this->assertSame(0, $this->backfill($path, false));
        $this->assertSame(0, ProductLocation::count());
        $this->assertSame(30, (int) $p->fresh()->stock_quantity);
    }

    public function test_apply_valid_mapping_creates_hints_without_touching_authority(): void
    {
        $p1 = $this->simpleProduct(['stock_quantity' => 30]);
        $p2 = $this->simpleProduct(['stock_quantity' => 5]);
        $loc1 = $this->bin('A-01');
        $loc2 = $this->bin('A-02');
        $path = $this->mappingFile([
            ['product_id' => $p1->id, 'location_id' => $loc1->id, 'quantity' => 30],
            ['product_id' => $p2->id, 'location_id' => $loc2->id, 'quantity' => 5],
        ]);

        $this->assertSame(0, $this->backfill($path, true));
        $this->assertDatabaseHas('product_locations', ['product_id' => $p1->id, 'location_id' => $loc1->id, 'quantity' => 30]);
        $this->assertDatabaseHas('product_locations', ['product_id' => $p2->id, 'location_id' => $loc2->id, 'quantity' => 5]);
        // Authority preserved exactly.
        $this->assertSame(30, (int) $p1->fresh()->stock_quantity);
        $this->assertSame(5, (int) $p2->fresh()->stock_quantity);
        $this->assertSame(0, (int) $p1->fresh()->reserved_quantity);

        // Rerun is idempotent: no new rows (convergence skips).
        $this->assertSame(0, $this->backfill($path, true));
        $this->assertSame(2, ProductLocation::count());
    }

    public function test_apply_rejects_invalid_mappings_and_creates_nothing_for_them(): void
    {
        $good = $this->simpleProduct(['stock_quantity' => 4]);
        $goodLoc = $this->bin('B-01');
        $badLoc = 999999;
        $inactive = $this->bin('B-02', Location::TYPE_STORAGE, Location::STATUS_INACTIVE);
        $variable = Product::create([
            'name' => ['en' => 'Var ' . Str::random(6)], 'slug' => 'var-' . Str::random(8),
            'description' => ['en' => 'd'], 'price' => 50, 'product_type' => 'variable',
            'status' => 1, 'in_stock' => true, 'stock_quantity' => 0, 'reserved_quantity' => 0,
        ]);
        $variant = ProductVariant::create(['product_id' => $variable->id, 'sku' => 'V-' . Str::random(6), 'price' => 50, 'stock_quantity' => 9, 'in_stock' => true]);
        $other = $this->simpleProduct();
        $foreignVariant = ProductVariant::create(['product_id' => $other->id, 'sku' => 'F-' . Str::random(6), 'price' => 10, 'stock_quantity' => 3, 'in_stock' => true]);

        $path = $this->mappingFile([
            ['product_id' => $good->id, 'location_id' => $goodLoc->id, 'quantity' => 4],
            ['product_id' => 999999, 'location_id' => $goodLoc->id, 'quantity' => 1],
            ['product_id' => $good->id, 'location_id' => $badLoc, 'quantity' => 1],
            ['product_id' => $good->id, 'location_id' => $inactive->id, 'quantity' => 1],
            ['product_id' => $variable->id, 'location_id' => $goodLoc->id, 'quantity' => 1], // variant required
            ['product_id' => $variable->id, 'product_variant_id' => $foreignVariant->id, 'location_id' => $goodLoc->id, 'quantity' => 1], // mismatch
            ['product_id' => $good->id, 'location_id' => $goodLoc->id, 'quantity' => 0], // non-positive
        ]);

        $this->assertSame(1, $this->backfill($path, true)); // failures present
        $this->assertSame(1, ProductLocation::count());
        $this->assertDatabaseHas('product_locations', ['product_id' => $good->id, 'location_id' => $goodLoc->id]);
        $this->assertSame(4, (int) $good->fresh()->stock_quantity);
        $this->assertSame(9, (int) $variant->fresh()->stock_quantity);
    }

    public function test_apply_requires_mapping_file(): void
    {
        $this->assertSame(2, \Illuminate\Support\Facades\Artisan::call('inventory:backfill-placements', ['--apply' => true, '--mapping' => '/nonexistent/mapping.json']));
        $this->assertSame(0, ProductLocation::count());
    }

    public function test_apply_empty_and_malformed_mappings(): void
    {
        // Empty array: canonical contract reports zero rows with success
        // (nothing requested, nothing done) — documented, not a failure.
        $this->assertSame(0, $this->backfill($this->mappingFile([]), true));

        $bad = tempnam(sys_get_temp_dir(), 'catmap') . '.json';
        file_put_contents($bad, '{not json');
        $this->assertSame(2, $this->backfill($bad, true));

        $this->assertSame(0, ProductLocation::count());
    }

    public function test_apply_enforces_hint_cap_and_leaves_no_row(): void
    {
        $p = $this->simpleProduct(['stock_quantity' => 5]);
        $loc = $this->bin('C-01');
        $path = $this->mappingFile([
            ['product_id' => $p->id, 'location_id' => $loc->id, 'quantity' => 1000],
        ]);

        $this->assertSame(1, $this->backfill($path, true));
        // Hint-cap refusal persists nothing (no manufactured placement).
        $this->assertSame(0, ProductLocation::count());
        $this->assertSame(5, (int) $p->fresh()->stock_quantity);
    }

    public function test_apply_records_audit_and_preserves_derived_fields(): void
    {
        $p = $this->simpleProduct(['stock_quantity' => 20, 'in_stock' => true]);
        $loc = $this->bin('D-01');
        $path = $this->mappingFile([
            ['product_id' => $p->id, 'location_id' => $loc->id, 'quantity' => 20],
        ]);
        $opsBefore = \App\Models\Inventory\InventoryOperation::count();
        $logBefore = \Spatie\Activitylog\Models\Activity::count();

        $this->assertSame(0, $this->backfill($path, true));

        $placement = ProductLocation::where('product_id', $p->id)->firstOrFail();
        $this->assertEquals(20, (float) $placement->quantity);
        $this->assertEquals(0, (float) $placement->allocated_hint);
        // Authority + derived flags untouched; no ledger row (hint-only backfill).
        $this->assertSame(20, (int) $p->fresh()->stock_quantity);
        $this->assertTrue((bool) $p->fresh()->in_stock);
        $this->assertSame($opsBefore, \App\Models\Inventory\InventoryOperation::count());
        // Canonical audit trail exists.
        $this->assertGreaterThan($logBefore, \Spatie\Activitylog\Models\Activity::count());
    }

    public function test_apply_variant_and_rejects_digital_cross_scope_and_occupied(): void
    {
        $parent = Product::create([
            'name' => ['en' => 'VP ' . Str::random(6)], 'slug' => 'vp-' . Str::random(8),
            'description' => ['en' => 'd'], 'price' => 70, 'product_type' => 'variable',
            'status' => 1, 'in_stock' => true, 'stock_quantity' => 0, 'reserved_quantity' => 0,
        ]);
        $variant = ProductVariant::create(['product_id' => $parent->id, 'sku' => 'VV-' . Str::random(6), 'price' => 70, 'stock_quantity' => 14, 'in_stock' => true]);
        $digital = $this->simpleProduct(['item_type' => 'DIGITAL', 'stock_quantity' => 9]);
        $loc = $this->bin('E-01');
        $otherWarehouse = \App\Models\Fulfillment\Warehouse::create(['code' => 'WH-OTHER', 'name' => 'Other', 'status' => 'active', 'is_default' => false]);
        $foreignLoc = Location::create([
            'warehouse_id' => $otherWarehouse->id, 'code' => 'F-01', 'name' => 'Foreign',
            'type' => Location::TYPE_STORAGE, 'status' => Location::STATUS_ACTIVE,
        ]);
        // Occupy E-02 with a different SKU (live quantity).
        $occupant = $this->simpleProduct(['stock_quantity' => 6]);
        $occupied = $this->bin('E-02');
        ProductLocation::create([
            'product_id' => $occupant->id, 'product_variant_id' => null,
            'location_id' => $occupied->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 6, 'allocated_hint' => 0,
        ]);

        $path = $this->mappingFile([
            ['product_id' => $parent->id, 'product_variant_id' => $variant->id, 'location_id' => $loc->id, 'quantity' => 14],
            ['product_id' => $digital->id, 'location_id' => $loc->id, 'quantity' => 9],
            // Per-row warehouse mismatch: location is in $otherWarehouse.
            ['product_id' => $occupant->id, 'location_id' => $foreignLoc->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 6],
            ['product_id' => $this->simpleProduct(['stock_quantity' => 2])->id, 'location_id' => $occupied->id, 'quantity' => 2],
        ]);

        // Foreign-warehouse entry must fail, not re-home.
        $this->assertSame(1, $this->backfill($path, true));

        // Variant happy path applied with exact SKU identity.
        $this->assertDatabaseHas('product_locations', [
            'product_id' => $parent->id, 'product_variant_id' => $variant->id, 'location_id' => $loc->id,
        ]);
        $this->assertSame(14, (int) $variant->fresh()->stock_quantity);
        $this->assertSame(0, (int) $parent->fresh()->stock_quantity);
        // Digital / cross-scope / occupied entries created nothing new.
        $this->assertSame(2, ProductLocation::count());
    }

    // ---------- product request contracts ----------

    public function test_product_create_with_unknown_location_is_rejected_without_product(): void
    {
        $category = Category::create(['name' => 'C1', 'slug' => 'c1-' . Str::random(4)]);
        $before = Product::count();

        $this->actingAs($this->productAdmin(), 'sanctum')->postJson('/api/v1/products', [
            'name' => ['en' => 'Bad Place ' . Str::random(4)],
            'description' => ['en' => 'd'],
            'categories' => [$category->id],
            'images' => [UploadedFile::fake()->image('t.jpg')],
            'product_type' => 'simple',
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
            'quantity' => 5,
            'location_id' => 999999,
        ])->assertStatus(422);

        $this->assertSame($before, Product::count());
    }

    public function test_product_update_preserves_placements_and_ignores_location_fields(): void
    {
        $p = $this->simpleProduct();
        $loc = $this->bin('U-01');
        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $p->id, 'quantity' => 12,
        ])->assertStatus(201);
        $before = ProductLocation::where('product_id', $p->id)->get()->map(
            fn ($r) => [$r->location_id, (float) $r->quantity, $r->product_variant_id]
        )->all();

        // Name-only update: placements must survive.
        $this->actingAs($this->productAdmin(), 'sanctum')->putJson("/api/v1/products/{$p->id}", [
            'name' => ['en' => 'Renamed ' . Str::random(4)],
        ])->assertStatus(200);

        // Supplied location fields on update have no placement semantics:
        // accepted without creating rows or errors.
        $this->actingAs($this->productAdmin(), 'sanctum')->putJson("/api/v1/products/{$p->id}", [
            'name' => ['en' => 'Renamed2 ' . Str::random(4)],
            'location_id' => $loc->id,
        ])->assertStatus(200);

        $after = ProductLocation::where('product_id', $p->id)->get()->map(
            fn ($r) => [$r->location_id, (float) $r->quantity, $r->product_variant_id]
        )->all();
        $this->assertSame($before, $after);
        $this->assertSame(12, (int) $p->fresh()->stock_quantity);
    }

    public function test_simple_product_valid_without_variant_and_readable_without_placement(): void
    {
        $p = $this->simpleProduct(['stock_quantity' => 3]);

        $this->actingAs($this->user(null, ['view-location', 'manage-warehouse']), 'sanctum')
            ->getJson("/api/v1/admin/products/{$p->id}/placements")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.product.id', $p->id);

        // Admin detail exposes authoritative stock + variant capability.
        $this->actingAs($this->productAdmin(), 'sanctum')
            ->getJson("/api/v1/products/{$p->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $p->id)
            ->assertJsonPath('data.product_type', 'simple')
            ->assertJsonPath('data.stock_quantity', 3);
    }

    public function test_variant_mismatch_rejected_on_receive(): void
    {
        $parent = Product::create([
            'name' => ['en' => 'P ' . Str::random(6)], 'slug' => 'p-' . Str::random(8),
            'description' => ['en' => 'd'], 'price' => 60, 'product_type' => 'variable',
            'status' => 1, 'in_stock' => true, 'stock_quantity' => 0, 'reserved_quantity' => 0,
        ]);
        $other = $this->simpleProduct();
        $foreign = ProductVariant::create(['product_id' => $other->id, 'sku' => 'X-' . Str::random(6), 'price' => 10, 'stock_quantity' => 5, 'in_stock' => true]);
        $loc = $this->bin('M-01');

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $parent->id, 'product_variant_id' => $foreign->id, 'quantity' => 2,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('product_locations', ['product_variant_id' => $foreign->id, 'location_id' => $loc->id]);
    }
}
