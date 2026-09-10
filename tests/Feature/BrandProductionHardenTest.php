<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class BrandProductionHardenTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $adminUser;

    private User $viewUser;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

        config(['scout.driver' => 'null']);

        $this->createAllTestTables();

        foreach (['view-brands', 'create-brand', 'update-brand', 'delete-brand'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->viewUser = User::create([
            'name' => 'View User',
            'email' => 'view.brand.harden@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000002',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->viewUser->givePermissionTo('view-brands');

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin.brand.harden@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000001',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo(['create-brand', 'update-brand', 'delete-brand', 'view-brands']);
    }

    // =========================================================================
    // Slug Preservation (Regression: BUG-1)
    // =========================================================================

    public function test_slug_does_not_change_when_updating_non_name_field(): void
    {
        Sanctum::actingAs($this->adminUser);

        $brand = Brand::create([
            'name' => ['en' => 'Original Name'],
            'slug' => 'original-name',
        ]);

        $this->putJson(self::PREFIX . '/brands/' . $brand->id, [
            'status' => 0,
        ]);

        $brand->refresh();
        $this->assertEquals('original-name', $brand->slug);
    }

    public function test_slug_changes_when_name_changes(): void
    {
        Sanctum::actingAs($this->adminUser);

        $brand = Brand::create([
            'name' => ['en' => 'Old Name'],
            'slug' => 'old-name',
        ]);

        $this->putJson(self::PREFIX . '/brands/' . $brand->id, [
            'name' => ['en' => 'New Name'],
        ]);

        $brand->refresh();
        $this->assertEquals('new-name', $brand->slug);
    }

    public function test_slug_does_not_change_when_updating_details_alone(): void
    {
        Sanctum::actingAs($this->adminUser);

        $brand = Brand::create([
            'name' => ['en' => 'Test Brand'],
            'slug' => 'test-brand',
            'details' => ['en' => 'Old details'],
        ]);

        $this->putJson(self::PREFIX . '/brands/' . $brand->id, [
            'details' => ['en' => 'New details here'],
        ]);

        $brand->refresh();
        $this->assertEquals('test-brand', $brand->slug);
    }

    // =========================================================================
    // Unique Name Validation
    // =========================================================================

    public function test_create_brand_with_duplicate_name_returns_422(): void
    {
        Sanctum::actingAs($this->adminUser);

        Brand::create(['name' => ['en' => 'Unique'], 'slug' => 'unique']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/brands', [
            'name' => ['en' => 'Unique'],
        ], [], [
            'image-desktop' => $imageDesktop,
            'image-mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertStatus(422);
    }

    // =========================================================================
    // Soft Delete / Restore / Force Delete
    // =========================================================================

    public function test_soft_deleted_brand_can_be_restored(): void
    {
        Sanctum::actingAs($this->adminUser);

        $brand = Brand::create(['name' => ['en' => 'Restore Me'], 'slug' => 'restore-me']);
        $brand->delete();

        $this->assertSoftDeleted('brands', ['id' => $brand->id]);

        $brand->restore();

        $this->assertNotSoftDeleted('brands', ['id' => $brand->id]);
    }

    public function test_soft_deleted_brand_still_has_pivot_relations(): void
    {
        $brand = Brand::create(['name' => ['en' => 'Test Brand'], 'slug' => 'test-brand']);
        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 100,
            'sku' => 'BRD-TST-001',
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
        ]);

        $brand->products()->sync([$product->id]);
        $this->assertDatabaseCount('brand_product', 1);

        $brand->delete();
        $this->assertSoftDeleted('brands', ['id' => $brand->id]);
        $this->assertDatabaseCount('brand_product', 1);

        $brand->restore();
        $this->assertDatabaseCount('brand_product', 1);
        $this->assertEquals(1, $brand->products()->count());
    }

    // =========================================================================
    // Media Lifecycle
    // =========================================================================

    public function test_create_brand_with_images(): void
    {
        Storage::fake('public');

        Sanctum::actingAs($this->adminUser);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/brands', [
            'name' => ['en' => 'Image Brand'],
        ], [], [
            'image-desktop' => $imageDesktop,
            'image-mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertCreated();

        $brand = Brand::find($response->json('data.id'));
        $this->assertNotNull($brand->getFirstMedia('brands-desktop'));
        $this->assertNotNull($brand->getFirstMedia('brands-mobile'));
    }

    public function test_update_brand_images(): void
    {
        Storage::fake('public');

        Sanctum::actingAs($this->adminUser);

        $brand = Brand::create(['name' => ['en' => 'Media Brand'], 'slug' => 'media-brand']);

        $newDesktop = UploadedFile::fake()->image('new-desktop.jpg', 100, 100);
        $newMobile = UploadedFile::fake()->image('new-mobile.jpg', 100, 100);

        $this->call('POST', self::PREFIX . '/brands/' . $brand->id, [
            'name' => ['en' => 'Media Brand Updated'],
            '_method' => 'PUT',
        ], [], [
            'image-desktop' => $newDesktop,
            'image-mobile' => $newMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $brand->refresh();
        $this->assertNotNull($brand->getFirstMedia('brands-desktop'));
        $this->assertNotNull($brand->getFirstMedia('brands-mobile'));
    }

    // =========================================================================
    // Brand-Product Sync
    // =========================================================================

    public function test_sync_brand_products_does_not_create_duplicates(): void
    {
        $brand = Brand::create(['name' => ['en' => 'Sync Test'], 'slug' => 'sync-test']);
        $product = Product::create([
            'name' => 'Sync Product',
            'slug' => 'sync-product',
            'price' => 50,
            'sku' => 'SYNC-001',
            'in_stock' => true,
            'stock_quantity' => 5,
            'product_type' => 'simple',
        ]);

        $brand->products()->sync([$product->id]);
        $this->assertDatabaseCount('brand_product', 1);

        $brand->products()->sync([$product->id]);
        $this->assertDatabaseCount('brand_product', 1);
    }

    public function test_update_brand_replaces_products(): void
    {
        Sanctum::actingAs($this->adminUser);

        $brand = Brand::create(['name' => ['en' => 'Product Brand'], 'slug' => 'product-brand']);

        $productA = Product::create([
            'name' => 'Product A',
            'slug' => 'product-a',
            'price' => 10,
            'sku' => 'PROD-A',
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
        ]);
        $productB = Product::create([
            'name' => 'Product B',
            'slug' => 'product-b',
            'price' => 20,
            'sku' => 'PROD-B',
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
        ]);

        $brand->products()->sync([$productA->id]);

        $this->putJson(self::PREFIX . '/brands/' . $brand->id, [
            'products' => [$productB->id],
        ]);

        $this->assertDatabaseHas('brand_product', [
            'brand_id' => $brand->id,
            'product_id' => $productB->id,
        ]);
        $this->assertDatabaseMissing('brand_product', [
            'brand_id' => $brand->id,
            'product_id' => $productA->id,
        ]);
    }

    // =========================================================================
    // Reorder
    // =========================================================================

    public function test_reorder_brands(): void
    {
        Sanctum::actingAs($this->adminUser);

        $brandA = Brand::create(['name' => ['en' => 'Alpha'], 'slug' => 'alpha', 'order' => 1]);
        $brandB = Brand::create(['name' => ['en' => 'Beta'], 'slug' => 'beta', 'order' => 2]);

        $this->putJson(self::PREFIX . '/brands/reorder', [
            'brands' => [$brandB->id, $brandA->id],
        ]);

        $brandA->refresh();
        $brandB->refresh();

        $this->assertEquals(1, $brandB->order);
        $this->assertEquals(2, $brandA->order);
    }

    public function test_reorder_with_invalid_brand_id_returns_422(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->putJson(self::PREFIX . '/brands/reorder', [
            'brands' => [99999],
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // Search and Filter Edge Cases
    // =========================================================================

    public function test_list_brands_with_empty_search_returns_all(): void
    {
        Sanctum::actingAs($this->viewUser);

        Brand::create(['name' => ['en' => 'Alpha'], 'slug' => 'alpha']);
        Brand::create(['name' => ['en' => 'Beta'], 'slug' => 'beta']);

        $response = $this->getJson(self::PREFIX . '/brands?search=');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.data');
    }

    public function test_list_brands_with_contradictory_filters_returns_empty(): void
    {
        Sanctum::actingAs($this->viewUser);

        Brand::create(['name' => ['en' => 'Active'], 'slug' => 'active', 'status' => true]);
        Brand::create(['name' => ['en' => 'Inactive'], 'slug' => 'inactive', 'status' => false]);

        $response = $this->getJson(self::PREFIX . '/brands?active=true&inactive=true');

        $response->assertOk();
        $response->assertJsonCount(0, 'data.data');
    }

    // =========================================================================
    // Mass Assignment Protection
    // =========================================================================

    public function test_mass_assignment_protection(): void
    {
        $brand = Brand::create([
            'name' => ['en' => 'Protected'],
            'slug' => 'protected',
            'id' => 99999,
        ]);

        $this->assertNotEquals(99999, $brand->id);
    }

    // =========================================================================
    // API Response Structure
    // =========================================================================

    public function test_brand_list_response_structure(): void
    {
        Sanctum::actingAs($this->viewUser);

        Brand::create(['name' => ['en' => 'Struct'], 'slug' => 'struct']);

        $response = $this->getJson(self::PREFIX . '/brands');

        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'data' => [
                    '*' => ['id', 'name', 'slug', 'image' => ['desktop', 'mobile'], 'details', 'status'],
                ],
                'page', 'current_page', 'from', 'to', 'last_page',
                'path', 'per_page', 'total', 'next_page_url', 'prev_page_url',
                'last_page_url', 'first_page_url',
            ],
        ]);
    }

    // =========================================================================
    // Error Handling
    // =========================================================================

    public function test_create_brand_fails_with_no_images(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson(self::PREFIX . '/brands', [
            'name' => ['en' => 'No Image Brand'],
        ]);

        $response->assertStatus(422);
    }

    public function test_update_nonexistent_brand_returns_404(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->putJson(self::PREFIX . '/brands/99999', [
            'name' => ['en' => 'Ghost'],
        ]);

        $response->assertStatus(404);
    }
}
