<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Inventory\InventoryOperation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\ItemType;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * WMS stock intake + relocation (product placement, receiving, transfers).
 *
 * Runs on the shared test database under DatabaseTransactions (rows roll
 * back per test). WMS/ledger tables are created here ONLY when missing
 * (production already has them; fresh databases get minimal parity).
 */
class StockOperationsTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $admin;

    private Warehouse $warehouse;

    private Warehouse $otherWarehouse;

    private Location $locationA;

    private Location $locationB;

    private Location $otherLocation;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Queue::fake();

        $this->createAllTestTables();
        $this->createWmsTablesIfMissing();

        foreach (['view-products', 'create-product', 'inventory-adjust', 'manage-warehouse'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::create([
            'name' => 'Stock Admin',
            'email' => 'stock-admin-' . Str::random(8) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->admin->givePermissionTo(['view-products', 'create-product', 'inventory-adjust', 'manage-warehouse']);

        $suffix = Str::random(6);
        $this->warehouse = Warehouse::create([
            'code' => 'STK-' . $suffix, 'name' => 'Stock WH', 'status' => 'active', 'is_default' => false,
        ]);
        $this->otherWarehouse = Warehouse::create([
            'code' => 'STK-O-' . $suffix, 'name' => 'Other WH', 'status' => 'active', 'is_default' => false,
        ]);
        $this->locationA = $this->makeLocation($this->warehouse->id, 'A');
        $this->locationB = $this->makeLocation($this->warehouse->id, 'B');
        $this->otherLocation = $this->makeLocation($this->otherWarehouse->id, 'X');

        $this->category = Category::create([
            'name' => 'Stock Cat ' . $suffix,
            'slug' => 'stock-cat-' . Str::random(8),
        ]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function createWmsTablesIfMissing(): void
    {
        if (!Schema::hasTable('warehouses')) {
            Schema::create('warehouses', function ($table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name');
                $table->text('address')->nullable();
                $table->string('city')->nullable();
                $table->string('country')->nullable();
                $table->string('status', 20)->default('active');
                $table->boolean('is_default')->default(false);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('locations')) {
            Schema::create('locations', function ($table) {
                $table->id();
                $table->foreignId('warehouse_id')->constrained()->onDelete('cascade');
                $table->foreignId('parent_id')->nullable()->constrained('locations')->onDelete('set null');
                $table->string('code', 50);
                $table->string('name');
                $table->string('type', 30)->nullable();
                $table->string('status', 20)->default('active');
                $table->integer('priority')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['warehouse_id', 'code']);
            });
        }

        if (!Schema::hasColumn('users', 'warehouse_id')) {
            Schema::table('users', function ($table) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('type');
            });
        }

        if (!Schema::hasTable('product_locations')) {
            Schema::create('product_locations', function ($table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->foreignId('location_id')->constrained()->onDelete('cascade');
                $table->foreignId('warehouse_id')->constrained()->onDelete('cascade');
                $table->decimal('quantity', 15, 2)->default(0);
                $table->decimal('allocated_hint', 15, 2)->default(0);
                $table->timestamps();
                $table->unique(['product_id', 'location_id']);
            });
        }

        if (!Schema::hasTable('inventory_operations')) {
            Schema::create('inventory_operations', function ($table) {
                $table->id();
                $table->string('idempotency_key', 64)->unique();
                $table->string('operation', 20);
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->unsignedBigInteger('source_location_id')->nullable();
                $table->unsignedBigInteger('destination_location_id')->nullable();
                $table->decimal('quantity', 15, 2);
                $table->string('reference', 255)->nullable();
                $table->string('status', 20)->default('processing');
                $table->json('result')->nullable();
                $table->timestamps();
            });
        }
    }

    private function makeLocation(int $warehouseId, string $suffix, array $overrides = []): Location
    {
        return Location::create(array_merge([
            'warehouse_id' => $warehouseId,
            'code' => 'L-' . $suffix . '-' . Str::random(5),
            'name' => 'Location ' . $suffix,
            'type' => Location::TYPE_STORAGE,
            'status' => Location::STATUS_ACTIVE,
        ], $overrides));
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Stock Product ' . Str::random(6)],
            'slug' => 'stock-product-' . Str::random(8),
            'description' => ['en' => 'Stock description'],
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'item_type' => ItemType::PHYSICAL,
            'status' => true,
            'in_stock' => true,
            'quantity' => 0,
            'stock_quantity' => 100,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ], $overrides));
    }

    private function scopedUser(int $warehouseId, array $permissions = ['inventory-adjust']): User
    {
        $user = User::create([
            'name' => 'Scoped User',
            'email' => 'scoped-' . Str::random(8) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'staff',
            'is_active' => true,
            'warehouse_id' => $warehouseId,
            'email_verified_at' => now(),
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => ['en' => 'Headset ' . Str::random(6)],
            'description' => ['en' => 'Bluetooth headset'],
            'product_type' => ProductType::SIMPLE,
            'price' => 150,
            'categories' => [$this->category->id],
            'images' => [UploadedFile::fake()->image('headset.jpg')],
            'quantity' => 50,
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ], $overrides);
    }

    private function receive(int $locationId, array $body)
    {
        return $this->postJson("/api/v1/admin/locations/{$locationId}/stock-receipts", $body);
    }

    private function transfer(array $body)
    {
        return $this->postJson('/api/v1/admin/stock-transfers', $body);
    }

    // ---------- product creation ----------

    public function test_create_product_without_wms_fields_is_unchanged(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/api/v1/products', $this->createPayload());
        $response->assertStatus(201);

        $product = Product::where('slug', $response->json('data.slug'))->firstOrFail();
        $this->assertSame(50, (int) $product->quantity);
        $this->assertSame(0, ProductLocation::where('product_id', $product->id)->count());
    }

    public function test_create_product_with_valid_placement(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/api/v1/products', $this->createPayload([
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->locationA->id,
        ]));
        $response->assertStatus(201);

        $product = Product::where('slug', $response->json('data.slug'))->firstOrFail();
        // Single authoritative initialization: stock == creation quantity.
        $this->assertSame(50, (int) $product->fresh()->stock_quantity);
        $placement = ProductLocation::where('product_id', $product->id)
            ->where('location_id', $this->locationA->id)->firstOrFail();
        $this->assertSame($this->warehouse->id, (int) $placement->warehouse_id);
        $this->assertEquals(50, (float) $placement->quantity);
    }

    public function test_create_product_with_location_only_derives_warehouse(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/api/v1/products', $this->createPayload([
            'location_id' => $this->locationB->id,
        ]));
        $response->assertStatus(201);

        $product = Product::where('slug', $response->json('data.slug'))->firstOrFail();
        $placement = ProductLocation::where('product_id', $product->id)->firstOrFail();
        $this->assertSame($this->warehouse->id, (int) $placement->warehouse_id);
        $this->assertSame($this->locationB->id, (int) $placement->location_id);
    }

    public function test_create_product_with_warehouse_only_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/api/v1/products', $this->createPayload([
            'warehouse_id' => $this->warehouse->id,
        ]));
        $response->assertStatus(422);
    }

    public function test_create_product_with_mismatched_warehouse_is_rejected_without_product(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $before = Product::count();

        $response = $this->postJson('/api/v1/products', array_merge(
            $this->createPayload(),
            ['name' => ['en' => 'Mismatch ' . Str::random(6)], 'warehouse_id' => $this->otherWarehouse->id, 'location_id' => $this->locationA->id]
        ));
        $response->assertStatus(422);
        $this->assertSame($before, Product::count());
        $this->assertSame(0, ProductLocation::where('location_id', $this->locationA->id)->count());
    }

    public function test_create_product_with_inactive_location_is_rejected_without_product(): void
    {
        $this->locationA->update(['status' => Location::STATUS_INACTIVE]);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/api/v1/products', $this->createPayload([
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->locationA->id,
        ]));
        $response->assertStatus(422);
        $this->assertSame(0, ProductLocation::where('location_id', $this->locationA->id)->count());
    }

    public function test_create_product_with_non_placeable_location_is_rejected(): void
    {
        $quarantine = $this->makeLocation($this->warehouse->id, 'Q', ['type' => Location::TYPE_QUARANTINE]);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/api/v1/products', $this->createPayload([
            'location_id' => $quarantine->id,
        ]));
        $response->assertStatus(422);
        $this->assertSame(0, ProductLocation::where('location_id', $quarantine->id)->count());
    }

    public function test_create_product_placement_requires_inventory_permission(): void
    {
        $user = $this->scopedUser($this->warehouse->id, []);
        $user->givePermissionTo(['view-products', 'create-product']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/products', $this->createPayload([
            'location_id' => $this->locationA->id,
        ]));
        $response->assertStatus(403);
    }

    // ---------- receiving ----------

    public function test_receive_stock_increases_authority_and_placement(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 100]);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->receive($this->locationB->id, [
            'product_id' => $product->id, 'quantity' => 50, 'reference' => 'PO-1',
        ]);
        $response->assertStatus(201);

        $this->assertSame(150, (int) $product->fresh()->stock_quantity);
        $this->assertEquals(100, (float) ProductLocation::where('product_id', $product->id)->where('location_id', $this->locationA->id)->value('quantity'));
        $this->assertEquals(50, (float) ProductLocation::where('product_id', $product->id)->where('location_id', $this->locationB->id)->value('quantity'));
        $this->assertSame(150, (int) $response->json('data.operation.stock_quantity'));
    }

    public function test_receive_stock_is_idempotent_on_retry(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 100]);
        Sanctum::actingAs($this->admin, ['*']);
        $body = ['product_id' => $product->id, 'quantity' => 50, 'idempotency_key' => 'recv-' . Str::random(10)];

        $first = $this->receive($this->locationA->id, $body);
        $first->assertStatus(201);
        $second = $this->receive($this->locationA->id, $body);
        $second->assertStatus(200);

        $this->assertTrue((bool) $second->json('data.replayed'));
        $this->assertSame(150, (int) $product->fresh()->stock_quantity);
        $this->assertSame(1, InventoryOperation::where('idempotency_key', $body['idempotency_key'])->count());
    }

    public function test_receive_rejects_unknown_product_and_location(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Unknown body id fails closed at validation (422); unknown route
        // location bypasses validation and 404s in the controller.
        $this->receive($this->locationA->id, ['product_id' => 999999999, 'quantity' => 5])->assertStatus(422);
        $this->receive(999999999, ['product_id' => $this->makeProduct()->id, 'quantity' => 5])->assertStatus(404);
    }

    public function test_receive_rejects_inactive_and_non_placeable_locations(): void
    {
        $product = $this->makeProduct();
        $this->locationB->update(['status' => Location::STATUS_INACTIVE]);
        $quarantine = $this->makeLocation($this->warehouse->id, 'Q', ['type' => Location::TYPE_QUARANTINE]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->receive($this->locationB->id, ['product_id' => $product->id, 'quantity' => 5])->assertStatus(422);
        $this->receive($quarantine->id, ['product_id' => $product->id, 'quantity' => 5])->assertStatus(422);
        $this->assertSame(100, (int) $product->fresh()->stock_quantity);
    }

    public function test_receive_rejects_invalid_quantity(): void
    {
        $product = $this->makeProduct();
        Sanctum::actingAs($this->admin, ['*']);

        $this->receive($this->locationA->id, ['product_id' => $product->id, 'quantity' => 0])->assertStatus(422);
        $this->receive($this->locationA->id, ['product_id' => $product->id, 'quantity' => -3])->assertStatus(422);
        $this->assertSame(100, (int) $product->fresh()->stock_quantity);
    }

    public function test_receive_enforces_warehouse_scope(): void
    {
        $product = $this->makeProduct();
        $scoped = $this->scopedUser($this->otherWarehouse->id);
        Sanctum::actingAs($scoped, ['*']);

        $this->receive($this->locationA->id, ['product_id' => $product->id, 'quantity' => 5])->assertStatus(403);
        $this->assertSame(100, (int) $product->fresh()->stock_quantity);
    }

    public function test_receive_guest_is_unauthorized(): void
    {
        $product = $this->makeProduct();

        $this->receive($this->locationA->id, ['product_id' => $product->id, 'quantity' => 5])->assertStatus(401);
    }

    public function test_receive_rejects_variable_and_digital_products(): void
    {
        $variable = $this->makeProduct(['product_type' => ProductType::VARIABLE]);
        $variable->variations()->create([
            'price' => 10, 'quantity' => 5, 'stock_quantity' => 5,
        ]);
        $digital = $this->makeProduct(['item_type' => ItemType::DIGITAL]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->receive($this->locationA->id, ['product_id' => $variable->id, 'quantity' => 5])->assertStatus(422);
        $this->receive($this->locationA->id, ['product_id' => $digital->id, 'quantity' => 5])->assertStatus(422);
    }

    // ---------- transfers ----------

    private function stockedProduct(): Product
    {
        $product = $this->makeProduct(['stock_quantity' => 150]);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $this->locationA->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 100, 'allocated_hint' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $this->locationB->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);

        return $product;
    }

    private function placementQty(Product $product, int $locationId): float
    {
        return (float) ProductLocation::where('product_id', $product->id)->where('location_id', $locationId)->value('quantity');
    }

    public function test_transfer_moves_stock_without_changing_total(): void
    {
        $product = $this->stockedProduct();
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->transfer([
            'product_id' => $product->id,
            'source_location_id' => $this->locationA->id,
            'destination_location_id' => $this->locationB->id,
            'quantity' => 30,
            'reference' => 'REBAL-1',
        ]);
        $response->assertStatus(201);

        $this->assertSame(150, (int) $product->fresh()->stock_quantity);
        $this->assertEquals(70, $this->placementQty($product, $this->locationA->id));
        $this->assertEquals(80, $this->placementQty($product, $this->locationB->id));
    }

    public function test_transfer_is_idempotent_on_retry(): void
    {
        $product = $this->stockedProduct();
        Sanctum::actingAs($this->admin, ['*']);
        $body = [
            'product_id' => $product->id,
            'source_location_id' => $this->locationA->id,
            'destination_location_id' => $this->locationB->id,
            'quantity' => 30,
            'idempotency_key' => 'xfer-' . Str::random(10),
        ];

        $this->transfer($body)->assertStatus(201);
        $replay = $this->transfer($body);
        $replay->assertStatus(200);

        $this->assertTrue((bool) $replay->json('data.replayed'));
        $this->assertSame(150, (int) $product->fresh()->stock_quantity);
        $this->assertEquals(70, $this->placementQty($product, $this->locationA->id));
        $this->assertEquals(80, $this->placementQty($product, $this->locationB->id));
    }

    public function test_transfer_rejects_insufficient_source(): void
    {
        $product = $this->stockedProduct();
        Sanctum::actingAs($this->admin, ['*']);

        $this->transfer([
            'product_id' => $product->id,
            'source_location_id' => $this->locationB->id,
            'destination_location_id' => $this->locationA->id,
            'quantity' => 500,
        ])->assertStatus(422);

        $this->assertSame(150, (int) $product->fresh()->stock_quantity);
        $this->assertEquals(100, $this->placementQty($product, $this->locationA->id));
        $this->assertEquals(50, $this->placementQty($product, $this->locationB->id));
    }

    public function test_transfer_rejects_same_and_cross_warehouse(): void
    {
        $product = $this->stockedProduct();
        Sanctum::actingAs($this->admin, ['*']);

        $this->transfer([
            'product_id' => $product->id,
            'source_location_id' => $this->locationA->id,
            'destination_location_id' => $this->locationA->id,
            'quantity' => 5,
        ])->assertStatus(422);

        $this->transfer([
            'product_id' => $product->id,
            'source_location_id' => $this->locationA->id,
            'destination_location_id' => $this->otherLocation->id,
            'quantity' => 5,
        ])->assertStatus(422);

        $this->assertEquals(100, $this->placementQty($product, $this->locationA->id));
    }

    public function test_transfer_respects_allocated_hint(): void
    {
        $product = $this->stockedProduct();
        ProductLocation::where('product_id', $product->id)
            ->where('location_id', $this->locationA->id)
            ->update(['allocated_hint' => 90]);
        Sanctum::actingAs($this->admin, ['*']);

        // Available at A is 100 - 90 = 10; moving 30 must fail.
        $this->transfer([
            'product_id' => $product->id,
            'source_location_id' => $this->locationA->id,
            'destination_location_id' => $this->locationB->id,
            'quantity' => 30,
        ])->assertStatus(422);

        $this->assertEquals(100, $this->placementQty($product, $this->locationA->id));
    }

    public function test_transfer_preserves_reservations(): void
    {
        $product = $this->stockedProduct();
        $product->forceFill(['reserved_quantity' => 5])->save();
        Sanctum::actingAs($this->admin, ['*']);

        $this->transfer([
            'product_id' => $product->id,
            'source_location_id' => $this->locationA->id,
            'destination_location_id' => $this->locationB->id,
            'quantity' => 10,
        ])->assertStatus(201);

        $fresh = $product->fresh();
        $this->assertSame(5, (int) $fresh->reserved_quantity);
        $this->assertSame(150, (int) $fresh->stock_quantity);
    }

    public function test_transfer_from_unplaced_source_is_rejected(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 100]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->transfer([
            'product_id' => $product->id,
            'source_location_id' => $this->locationA->id,
            'destination_location_id' => $this->locationB->id,
            'quantity' => 5,
        ])->assertStatus(422);

        $this->assertSame(100, (int) $product->fresh()->stock_quantity);
        // Failed transfer writes no placement rows.
        $this->assertSame(0, ProductLocation::where('product_id', $product->id)->count());
    }

    public function test_transfer_rejects_unknown_ids_at_validation(): void
    {
        $product = $this->stockedProduct();
        Sanctum::actingAs($this->admin, ['*']);

        $this->transfer([
            'product_id' => 999999999,
            'source_location_id' => $this->locationA->id,
            'destination_location_id' => $this->locationB->id,
            'quantity' => 5,
        ])->assertStatus(422);

        $this->transfer([
            'product_id' => $product->id,
            'source_location_id' => 999999999,
            'destination_location_id' => $this->locationB->id,
            'quantity' => 5,
        ])->assertStatus(422);
    }

    public function test_transfer_enforces_warehouse_scope(): void
    {
        $product = $this->stockedProduct();
        $scoped = $this->scopedUser($this->otherWarehouse->id);
        Sanctum::actingAs($scoped, ['*']);

        $this->transfer([
            'product_id' => $product->id,
            'source_location_id' => $this->locationA->id,
            'destination_location_id' => $this->locationB->id,
            'quantity' => 5,
        ])->assertStatus(403);
    }
}
