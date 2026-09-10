<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class BannerApiTest extends TestCase
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
        config(['filesystems.disks.banners' => [
            'driver' => 'local',
            'root' => storage_path('app/public/banners'),
            'url' => env('APP_URL') . '/storage/banners',
            'visibility' => 'public',
        ]]);

        $this->createAllTestTables();

        if (!Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('email_verified_at')->nullable();
            });
        }

        foreach (['view-banners', 'create-banners', 'update-banners', 'delete-banners'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->viewUser = User::create([
            'name' => 'View User',
            'email' => 'view.banner@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000002',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->viewUser->givePermissionTo('view-banners');

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin.banner@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000001',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo(['create-banners', 'update-banners', 'delete-banners', 'view-banners']);
    }

    // =========================================================================
    // Authentication Tests
    // =========================================================================

    public function test_guest_gets_401_for_list_banners()
    {
        $response = $this->getJson(self::PREFIX . '/banners');
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_show_banner()
    {
        $banner = Banner::create(['title' => ['en' => 'Test'], 'slug' => 'test']);
        $response = $this->getJson(self::PREFIX . '/banners/' . $banner->id);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_create_banner()
    {
        $response = $this->postJson(self::PREFIX . '/banners', [
            'title' => ['en' => 'Summer Sale'],
        ]);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_update_banner()
    {
        $banner = Banner::create(['title' => ['en' => 'Test'], 'slug' => 'test']);
        $response = $this->putJson(self::PREFIX . '/banners/' . $banner->id, [
            'title' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_delete_banner()
    {
        $banner = Banner::create(['title' => ['en' => 'Test'], 'slug' => 'test']);
        $response = $this->deleteJson(self::PREFIX . '/banners/' . $banner->id);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_change_status()
    {
        $banner = Banner::create(['title' => ['en' => 'Test'], 'slug' => 'test', 'status' => false]);
        $response = $this->putJson(self::PREFIX . '/banner/change-status', [
            'id' => $banner->id,
        ]);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_reorder()
    {
        $response = $this->postJson(self::PREFIX . '/banner/reorder', [
            'banners' => [1, 2],
        ]);
        $response->assertStatus(401);
    }

    // =========================================================================
    // Authorization Tests
    // =========================================================================

    public function test_user_without_view_permission_gets_forbidden_for_index()
    {
        $user = User::create([
            'name' => 'No Perm User',
            'email' => 'noperm.banner@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000003',
            'is_active' => true,
            'type' => 'admin',
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(self::PREFIX . '/banners');
        $response->assertStatus(403);
    }

    public function test_user_without_create_permission_gets_forbidden_for_store()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/banners', [
            'title' => ['en' => 'Summer Sale'],
        ]);
        $response->assertStatus(403);
    }

    public function test_user_without_update_permission_gets_forbidden_for_update()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $banner = Banner::create(['title' => ['en' => 'Test'], 'slug' => 'test']);

        $response = $this->putJson(self::PREFIX . '/banners/' . $banner->id, [
            'title' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(403);
    }

    public function test_user_without_delete_permission_gets_forbidden_for_destroy()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $banner = Banner::create(['title' => ['en' => 'Test'], 'slug' => 'test']);

        $response = $this->deleteJson(self::PREFIX . '/banners/' . $banner->id);
        $response->assertStatus(403);
    }

    public function test_user_without_update_permission_gets_forbidden_for_change_status()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $banner = Banner::create(['title' => ['en' => 'Test'], 'slug' => 'test', 'status' => false]);

        $response = $this->putJson(self::PREFIX . '/banner/change-status', [
            'id' => $banner->id,
        ]);
        $response->assertStatus(403);
    }

    public function test_user_without_update_permission_gets_forbidden_for_reorder()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/banner/reorder', [
            'banners' => [1, 2],
        ]);
        $response->assertStatus(403);
    }

    // =========================================================================
    // GET /api/v1/banners — List Banners
    // =========================================================================

    public function test_authenticated_user_can_list_banners()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Banner::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);
        Banner::create(['title' => ['en' => 'New Arrivals'], 'slug' => 'new-arrivals']);

        $response = $this->getJson(self::PREFIX . '/banners');

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

    public function test_list_banners_returns_empty_when_none_exist()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/banners');

        $response->assertOk();
        $response->assertJsonCount(0, 'data.data');
        $response->assertJsonPath('data.total', 0);
    }

    // =========================================================================
    // GET /api/v1/banners/{id} — Show Banner
    // =========================================================================

    public function test_authenticated_user_can_show_banner()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $banner = Banner::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->getJson(self::PREFIX . '/banners/' . $banner->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $banner->id);
        $response->assertJsonPath('data.slug', 'summer-sale');
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'title', 'slug', 'description', 'image', 'status',
            ],
        ]);
    }

    public function test_show_banner_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/banners/9999');
        $response->assertStatus(404);
    }

    // =========================================================================
    // POST /api/v1/banners — Create Banner
    // =========================================================================

    public function test_authenticated_admin_can_create_banner()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/banners', [
            'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
            'description' => ['en' => 'Best deals this summer', 'ar' => 'أفضل العروض هذا الصيف'],
        ], [], [
            'image_desktop' => $imageDesktop,
            'image_mobile' => $imageMobile,
        ], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'multipart/form-data',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.slug', 'summer-sale');
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'title', 'slug', 'description', 'image', 'status',
            ],
        ]);
        $this->assertDatabaseHas('banners', ['slug' => 'summer-sale']);
    }

    public function test_create_banner_returns_422_for_missing_title()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/banners', [], [], [
            'image_desktop' => $imageDesktop,
            'image_mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);
        $response->assertStatus(422);
    }

    public function test_create_banner_returns_422_for_missing_images()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->call('POST', self::PREFIX . '/banners', [
            'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
            'description' => ['en' => 'Best deals', 'ar' => 'أفضل العروض'],
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response->assertStatus(422);
    }

    // =========================================================================
    // PUT /api/v1/banners/{id} — Update Banner
    // =========================================================================

    public function test_authenticated_admin_can_update_banner()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $banner = Banner::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->putJson(self::PREFIX . '/banners/' . $banner->id, [
            'title' => ['en' => 'Winter Sale', 'ar' => 'تخفيضات الشتاء'],
            'status' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('banners', ['slug' => 'winter-sale']);
    }

    public function test_update_banner_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/banners/9999', [
            'title' => ['en' => 'Ghost'],
        ]);
        $response->assertStatus(404);
    }

    // =========================================================================
    // DELETE /api/v1/banners/{id} — Delete Banner
    // =========================================================================

    public function test_authenticated_admin_can_soft_delete_banner()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $banner = Banner::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->deleteJson(self::PREFIX . '/banners/' . $banner->id);
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertSoftDeleted('banners', ['id' => $banner->id]);
    }

    public function test_delete_banner_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->deleteJson(self::PREFIX . '/banners/9999');
        $response->assertStatus(404);
    }

    public function test_soft_deleted_banner_not_listed_in_index()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        Banner::create(['title' => ['en' => 'Visible'], 'slug' => 'visible']);
        $deleted = Banner::create(['title' => ['en' => 'Deleted'], 'slug' => 'deleted']);
        $deleted->delete();

        Sanctum::actingAs($this->viewUser, ['*']);
        $response = $this->getJson(self::PREFIX . '/banners');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.data');
    }

    // =========================================================================
    // PUT /api/v1/banner/change-status — Toggle Status
    // =========================================================================

    public function test_authenticated_admin_can_toggle_banner_status()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $banner = Banner::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale', 'status' => false]);

        $response = $this->putJson(self::PREFIX . '/banner/change-status', [
            'id' => $banner->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', true);
    }

    public function test_change_status_returns_422_for_missing_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/banner/change-status', []);
        $response->assertStatus(422);
    }

    public function test_change_status_returns_422_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/banner/change-status', [
            'id' => 9999,
        ]);
        $response->assertStatus(422);
    }

    // =========================================================================
    // POST /api/v1/banner/reorder — Reorder Banners
    // =========================================================================

    public function test_authenticated_admin_can_reorder_banners()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $bannerA = Banner::create(['title' => ['en' => 'A'], 'slug' => 'a']);
        $bannerB = Banner::create(['title' => ['en' => 'B'], 'slug' => 'b']);

        $response = $this->postJson(self::PREFIX . '/banner/reorder', [
            'banners' => [$bannerB->id, $bannerA->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_reorder_returns_422_for_missing_banners()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/banner/reorder', []);
        $response->assertStatus(422);
    }

    public function test_reorder_returns_422_for_invalid_ids()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/banner/reorder', [
            'banners' => [9999],
        ]);
        $response->assertStatus(422);
    }

    // =========================================================================
    // Product Relation
    // =========================================================================

    public function test_create_banner_with_product_association()
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

        $response = $this->call('POST', self::PREFIX . '/banners', [
            'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
            'description' => ['en' => 'Best deals', 'ar' => 'أفضل العروض'],
            'products' => [$product->id],
        ], [], [
            'image_desktop' => $imageDesktop,
            'image_mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk();
        $this->assertDatabaseHas('banner_product', [
            'banner_id' => $response->json('data.id'),
            'product_id' => $product->id,
        ]);
    }

    // =========================================================================
    // Translation Flow
    // =========================================================================

    public function test_banner_title_is_translatable()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/banners', [
            'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
            'description' => ['en' => 'Best deals', 'ar' => 'أفضل العروض'],
        ], [], [
            'image_desktop' => $imageDesktop,
            'image_mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk();

        $response = $this->withHeaders(['lang' => 'ar'])->getJson(self::PREFIX . '/banners');
        $response->assertJsonPath('data.data.0.title', 'تخفيضات الصيف');

        $response = $this->withHeaders(['lang' => 'en'])->getJson(self::PREFIX . '/banners');
        $response->assertJsonPath('data.data.0.title', 'Summer Sale');
    }

    // =========================================================================
    // Response Structure
    // =========================================================================

    public function test_banner_resource_structure_on_show()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $banner = Banner::create([
            'title' => ['en' => 'Summer Sale'],
            'slug' => 'summer-sale',
            'description' => ['en' => 'Great deals'],
        ]);

        $response = $this->getJson(self::PREFIX . '/banners/' . $banner->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'title', 'slug', 'description', 'image', 'status',
            ],
        ]);
        $this->assertIsArray($response->json('data.image'));
    }

    // =========================================================================
    // Mass Assignment Protection
    // =========================================================================

    public function test_banner_mass_assignment_protection()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/banners', [
            'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
            'description' => ['en' => 'Best deals this summer', 'ar' => 'أفضل العروض هذا الصيف'],
            'id' => 99999,
        ], [], [
            'image_desktop' => $imageDesktop,
            'image_mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk();
        $this->assertDatabaseMissing('banners', ['id' => 99999]);
    }
}
