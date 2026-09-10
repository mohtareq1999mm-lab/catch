<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class BrandApiTest extends TestCase
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
        config(['filesystems.disks.brands' => [
            'driver' => 'local',
            'root' => storage_path('app/public/brands'),
            'url' => env('APP_URL') . '/storage/brands',
            'visibility' => 'public',
        ]]);

        $this->createAllTestTables();

        if (!Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('email_verified_at')->nullable();
            });
        }

        foreach (['view-brands', 'create-brand', 'update-brand', 'delete-brand'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->viewUser = User::create([
            'name' => 'View User',
            'email' => 'view.brand@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000002',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->viewUser->givePermissionTo('view-brands');

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin.brand@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000001',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo(['create-brand', 'update-brand', 'delete-brand', 'view-brands']);
    }

    // =========================================================================
    // GET /api/v1/brands — List Brands (requires view-brands)
    // =========================================================================

    public function test_authenticated_user_can_list_brands()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);
        Brand::create(['name' => ['en' => 'Adidas'], 'slug' => 'adidas']);

        $response = $this->getJson(self::PREFIX . '/brands');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(2, 'data.data');
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'data', 'page', 'current_page', 'from', 'to', 'last_page',
                'path', 'per_page', 'total', 'next_page_url', 'prev_page_url', 'last_page_url', 'first_page_url',
            ],
        ]);
    }

    public function test_guest_gets_401_for_list_brands()
    {
        $response = $this->getJson(self::PREFIX . '/brands');

        $response->assertStatus(401);
    }

    public function test_list_brands_returns_empty_data_when_none_exist()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/brands');

        $response->assertOk();
        $response->assertJsonCount(0, 'data.data');
        $response->assertJsonPath('data.total', 0);
    }

    public function test_list_brands_pagination()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Brand::create(['name' => ['en' => 'Brand A'], 'slug' => 'brand-a']);
        Brand::create(['name' => ['en' => 'Brand B'], 'slug' => 'brand-b']);
        Brand::create(['name' => ['en' => 'Brand C'], 'slug' => 'brand-c']);

        $response = $this->getJson(self::PREFIX . '/brands?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.data');
        $response->assertJsonPath('data.per_page', 2);
        $response->assertJsonPath('data.total', 3);
    }

    public function test_list_brands_with_search()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);
        Brand::create(['name' => ['en' => 'Adidas'], 'slug' => 'adidas']);

        $response = $this->getJson(self::PREFIX . '/brands?search=Nike');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.data');
    }

    public function test_list_brands_with_ordering()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Brand::create(['name' => ['en' => 'Alpha'], 'slug' => 'alpha']);
        Brand::create(['name' => ['en' => 'Beta'], 'slug' => 'beta']);

        $response = $this->getJson(self::PREFIX . '/brands?order=slug&sortedBy=desc');

        $response->assertOk();
        $this->assertEquals('beta', $response->json('data.data.0.slug'));
    }

    public function test_list_brands_active_filter()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Brand::create(['name' => ['en' => 'Active'], 'slug' => 'active', 'status' => true]);
        Brand::create(['name' => ['en' => 'Inactive'], 'slug' => 'inactive', 'status' => false]);

        $response = $this->getJson(self::PREFIX . '/brands?active=true');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.data');
        $this->assertEquals('Active', $response->json('data.data.0.name'));
    }

    public function test_list_brands_inactive_filter()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Brand::create(['name' => ['en' => 'Active'], 'slug' => 'active', 'status' => true]);
        Brand::create(['name' => ['en' => 'Inactive'], 'slug' => 'inactive', 'status' => false]);

        $response = $this->getJson(self::PREFIX . '/brands?inactive=true');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.data');
    }

    // =========================================================================
    // GET /api/v1/brands/{id} — Show Brand (requires view-brands)
    // =========================================================================

    public function test_authenticated_user_can_show_brand_by_id()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);

        $response = $this->getJson(self::PREFIX . '/brands/' . $brand->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $brand->id);
        $response->assertJsonPath('data.slug', 'nike');
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'name', 'slug', 'image', 'details', 'status',
            ],
        ]);
    }

    public function test_authenticated_user_can_show_brand_by_slug()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);

        $response = $this->getJson(self::PREFIX . '/brands/nike');

        $response->assertOk();
        $response->assertJsonPath('data.id', $brand->id);
        $response->assertJsonPath('data.slug', 'nike');
    }

    public function test_guest_gets_401_for_show_brand()
    {
        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);

        $response = $this->getJson(self::PREFIX . '/brands/' . $brand->id);
        $response->assertStatus(401);
    }

    public function test_show_brand_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/brands/9999');
        $response->assertStatus(404);
    }

    public function test_show_brand_returns_404_for_nonexistent_slug()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/brands/nonexistent-slug');
        $response->assertStatus(404);
    }

    // =========================================================================
    // POST /api/v1/brands — Create Brand (requires create-brand)
    // =========================================================================

    public function test_unauthenticated_user_cannot_create_brand()
    {
        $response = $this->postJson(self::PREFIX . '/brands', [
            'name' => ['en' => 'Nike'],
        ]);
        $response->assertStatus(401);
    }

    public function test_user_without_create_permission_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/brands', [
            'name' => ['en' => 'Nike'],
        ]);
        $response->assertStatus(403);
    }

    public function test_authenticated_admin_can_create_brand()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/brands', [
            'name' => ['en' => 'Nike', 'ar' => 'نايك'],
        ], [], [
            'image-desktop' => $imageDesktop,
            'image-mobile' => $imageMobile,
        ], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'multipart/form-data',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.slug', 'nike');
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'name', 'slug', 'image', 'details', 'status',
            ],
        ]);
        $this->assertDatabaseHas('brands', ['slug' => 'nike']);
    }

    public function test_create_brand_returns_422_for_missing_name()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/brands', [], [], [
            'image-desktop' => $imageDesktop,
            'image-mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);
        $response->assertStatus(422);
    }

    public function test_create_brand_returns_422_for_invalid_status()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/brands', [
            'name' => ['en' => 'Nike'],
            'status' => 99,
        ], [], [
            'image-desktop' => $imageDesktop,
            'image-mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);
        $response->assertStatus(422);
    }

    // =========================================================================
    // PUT /api/v1/brands/{id} — Update Brand (requires update-brand)
    // =========================================================================

    public function test_unauthenticated_user_cannot_update_brand()
    {
        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);

        $response = $this->putJson(self::PREFIX . '/brands/' . $brand->id, [
            'name' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);

        $response = $this->putJson(self::PREFIX . '/brands/' . $brand->id, [
            'name' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(403);
    }

    public function test_authenticated_admin_can_update_brand()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);

        $response = $this->putJson(self::PREFIX . '/brands/' . $brand->id, [
            'name' => ['en' => 'Adidas', 'ar' => 'أديداس'],
            'status' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('brands', ['slug' => 'adidas']);
    }

    public function test_update_brand_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/brands/9999', [
            'name' => ['en' => 'Ghost'],
        ]);
        $response->assertStatus(404);
    }

    // =========================================================================
    // DELETE /api/v1/brands/{id} — Delete Brand (requires delete-brand)
    // =========================================================================

    public function test_unauthenticated_user_cannot_delete_brand()
    {
        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);

        $response = $this->deleteJson(self::PREFIX . '/brands/' . $brand->id);
        $response->assertStatus(401);
    }

    public function test_user_without_delete_permission_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);

        $response = $this->deleteJson(self::PREFIX . '/brands/' . $brand->id);
        $response->assertStatus(403);
    }

    public function test_authenticated_admin_can_soft_delete_brand()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);

        $response = $this->deleteJson(self::PREFIX . '/brands/' . $brand->id);
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertSoftDeleted('brands', ['id' => $brand->id]);
    }

    public function test_delete_brand_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->deleteJson(self::PREFIX . '/brands/9999');
        $response->assertStatus(404);
    }

    public function test_soft_deleted_brand_not_listed_in_index()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        Brand::create(['name' => ['en' => 'Visible'], 'slug' => 'visible']);
        $deleted = Brand::create(['name' => ['en' => 'Deleted Brand'], 'slug' => 'deleted']);
        $deleted->delete();

        Sanctum::actingAs($this->viewUser, ['*']);
        $response = $this->getJson(self::PREFIX . '/brands');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.data');
    }

    public function test_soft_deleted_brand_returns_404_on_show()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);
        $brand->delete();

        Sanctum::actingAs($this->viewUser, ['*']);
        $response = $this->getJson(self::PREFIX . '/brands/' . $brand->id);
        $response->assertStatus(404);
    }

    public function test_brand_can_be_restored()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike']);
        $brand->delete();

        $brand->restore();

        $this->assertNotSoftDeleted('brands', ['id' => $brand->id]);
    }

    // =========================================================================
    // Product Relation
    // =========================================================================

    public function test_create_brand_with_product_association()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 100,
            'sku' => 'TST-001',
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
        ]);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/brands', [
            'name' => ['en' => 'Nike'],
            'products' => [$product->id],
        ], [], [
            'image-desktop' => $imageDesktop,
            'image-mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.products.0.id', $product->id);
        $this->assertDatabaseHas('brand_product', [
            'brand_id' => $response->json('data.id'),
            'product_id' => $product->id,
        ]);
    }

    // =========================================================================
    // Translation Flow
    // =========================================================================

    public function test_brand_name_is_translatable()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/brands', [
            'name' => ['en' => 'Nike', 'ar' => 'نايك'],
        ], [], [
            'image-desktop' => $imageDesktop,
            'image-mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertCreated();

        $brandId = $response->json('data.id');

        $response = $this->withHeaders(['lang' => 'ar'])->getJson(self::PREFIX . '/brands');
        $response->assertJsonPath('data.data.0.name', 'نايك');

        $response = $this->withHeaders(['lang' => 'en'])->getJson(self::PREFIX . '/brands');
        $response->assertJsonPath('data.data.0.name', 'Nike');
    }

    // =========================================================================
    // Response Structure
    // =========================================================================

    public function test_brand_resource_structure_on_show()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $brand = Brand::create(['name' => ['en' => 'Nike'], 'slug' => 'nike', 'details' => ['en' => 'Sportswear']]);

        $response = $this->getJson(self::PREFIX . '/brands/' . $brand->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'name', 'slug', 'image' => ['desktop', 'mobile'], 'details', 'status',
            ],
        ]);
        $response->assertJsonPath('data.image.desktop', '');
        $response->assertJsonPath('data.image.mobile', '');
    }

    // =========================================================================
    // Regression Tests — Slug Behavior
    // =========================================================================

    public function test_explicit_slug_is_preserved_on_create(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $brand = Brand::create([
            'name' => ['en' => 'My Brand'],
            'slug' => 'explicit-slug-456',
        ]);

        $this->assertEquals('explicit-slug-456', $brand->slug);
    }

    public function test_slug_auto_generated_when_not_provided(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $brand = Brand::create([
            'name' => ['en' => 'Auto Brand'],
        ]);

        $this->assertEquals('auto-brand', $brand->slug);
    }

    public function test_slug_stable_when_name_unchanged(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $brand = Brand::create([
            'name' => ['en' => 'Stable'],
            'slug' => 'stable-slug',
        ]);

        $brand->update(['details' => ['en' => 'Updated details only']]);

        $this->assertEquals('stable-slug', $brand->slug);
    }

    public function test_slug_updates_when_name_changes_and_slug_not_provided(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $brand = Brand::create([
            'name' => ['en' => 'Old Name'],
            'slug' => 'old-name',
        ]);

        $brand->update(['name' => ['en' => 'New Name']]);

        $this->assertEquals('new-name', $brand->slug);
    }

    public function test_slug_preserved_when_both_name_and_slug_provided(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $brand = Brand::create([
            'name' => ['en' => 'Original'],
        ]);

        $brand->update([
            'name' => ['en' => 'Updated Name'],
            'slug' => 'custom-slug',
        ]);

        $this->assertEquals('custom-slug', $brand->slug);
    }

    public function test_status_update_does_not_change_slug(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $brand = Brand::create([
            'name' => ['en' => 'Toggle Status'],
            'slug' => 'toggle-status',
        ]);

        $brand->update(['status' => false]);
        $this->assertEquals('toggle-status', $brand->slug);

        $brand->update(['status' => true]);
        $this->assertEquals('toggle-status', $brand->slug);
    }
}
