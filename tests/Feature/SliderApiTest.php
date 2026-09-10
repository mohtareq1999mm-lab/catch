<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class SliderApiTest extends TestCase
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
        config(['filesystems.disks.sliders' => [
            'driver' => 'local',
            'root' => storage_path('app/public/sliders'),
            'url' => env('APP_URL') . '/storage/sliders',
            'visibility' => 'public',
        ]]);

        $this->createAllTestTables();

        if (!Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('email_verified_at')->nullable();
            });
        }

        foreach (['view-slider', 'create-slider', 'update-slider', 'delete-slider'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->viewUser = User::create([
            'name' => 'View User',
            'email' => 'view.slider@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000002',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->viewUser->givePermissionTo('view-slider');

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin.slider@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000001',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo(['create-slider', 'update-slider', 'delete-slider', 'view-slider']);
    }

    // =========================================================================
    // GET /api/v1/sliders — List Sliders (requires view-slider)
    // =========================================================================

    public function test_authenticated_user_can_list_sliders()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);
        Slider::create(['title' => ['en' => 'New Arrivals'], 'slug' => 'new-arrivals']);

        $response = $this->getJson(self::PREFIX . '/sliders');

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

    public function test_guest_gets_401_for_list_sliders()
    {
        $response = $this->getJson(self::PREFIX . '/sliders');

        $response->assertStatus(401);
    }

    public function test_list_sliders_returns_empty_data_when_none_exist()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/sliders');

        $response->assertOk();
        $response->assertJsonCount(0, 'data.data');
        $response->assertJsonPath('data.total', 0);
    }

    public function test_list_sliders_pagination()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Slider::create(['title' => ['en' => 'A'], 'slug' => 'a']);
        Slider::create(['title' => ['en' => 'B'], 'slug' => 'b']);
        Slider::create(['title' => ['en' => 'C'], 'slug' => 'c']);

        $response = $this->getJson(self::PREFIX . '/sliders?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.data');
        $response->assertJsonPath('data.per_page', 2);
        $response->assertJsonPath('data.total', 3);
    }

    public function test_list_sliders_active_filter()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Slider::create(['title' => ['en' => 'Active'], 'slug' => 'active', 'status' => true]);
        Slider::create(['title' => ['en' => 'Inactive'], 'slug' => 'inactive', 'status' => false]);

        $response = $this->getJson(self::PREFIX . '/sliders?active=true');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.data');
    }

    // =========================================================================
    // GET /api/v1/sliders/{id} — Show Slider (requires view-slider)
    // =========================================================================

    public function test_authenticated_user_can_show_slider()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->getJson(self::PREFIX . '/sliders/' . $slider->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $slider->id);
        $response->assertJsonPath('data.slug', 'summer-sale');
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'title', 'slug', 'image', 'status', 'order',
            ],
        ]);
    }

    public function test_guest_gets_401_for_show_slider()
    {
        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->getJson(self::PREFIX . '/sliders/' . $slider->id);
        $response->assertStatus(401);
    }

    public function test_show_slider_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/sliders/9999');
        $response->assertStatus(404);
    }

    // =========================================================================
    // POST /api/v1/sliders — Create Slider (requires create-slider)
    // =========================================================================

    public function test_unauthenticated_user_cannot_create_slider()
    {
        $response = $this->postJson(self::PREFIX . '/sliders', [
            'title' => ['en' => 'Summer Sale'],
        ]);
        $response->assertStatus(401);
    }

    public function test_user_without_create_permission_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/sliders', [
            'title' => ['en' => 'Summer Sale'],
        ]);
        $response->assertStatus(403);
    }

    public function test_authenticated_admin_can_create_slider()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/sliders', [
            'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
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
                'id', 'title', 'slug', 'image', 'status', 'order',
            ],
        ]);
        $this->assertDatabaseHas('sliders', ['slug' => 'summer-sale']);
    }

    public function test_create_slider_returns_422_for_missing_title()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/sliders', [], [], [
            'image_desktop' => $imageDesktop,
            'image_mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);
        $response->assertStatus(422);
    }

    public function test_create_slider_returns_422_for_missing_images()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->call('POST', self::PREFIX . '/sliders', [
            'title' => ['en' => 'Summer Sale'],
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response->assertStatus(422);
    }

    // =========================================================================
    // PUT /api/v1/sliders/{id} — Update Slider (requires update-slider)
    // =========================================================================

    public function test_unauthenticated_user_cannot_update_slider()
    {
        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->putJson(self::PREFIX . '/sliders/' . $slider->id, [
            'title' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->putJson(self::PREFIX . '/sliders/' . $slider->id, [
            'title' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(403);
    }

    public function test_authenticated_admin_can_update_slider()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->putJson(self::PREFIX . '/sliders/' . $slider->id, [
            'title' => ['en' => 'Winter Sale', 'ar' => 'تخفيضات الشتاء'],
            'status' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('sliders', ['slug' => 'winter-sale']);
    }

    public function test_update_slider_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/sliders/9999', [
            'title' => ['en' => 'Ghost'],
        ]);
        $response->assertStatus(404);
    }

    // =========================================================================
    // DELETE /api/v1/sliders/{id} — Delete Slider (requires delete-slider)
    // =========================================================================

    public function test_unauthenticated_user_cannot_delete_slider()
    {
        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->deleteJson(self::PREFIX . '/sliders/' . $slider->id);
        $response->assertStatus(401);
    }

    public function test_user_without_delete_permission_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->deleteJson(self::PREFIX . '/sliders/' . $slider->id);
        $response->assertStatus(403);
    }

    public function test_authenticated_admin_can_soft_delete_slider()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->deleteJson(self::PREFIX . '/sliders/' . $slider->id);
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertSoftDeleted('sliders', ['id' => $slider->id]);
    }

    public function test_delete_slider_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->deleteJson(self::PREFIX . '/sliders/9999');
        $response->assertStatus(404);
    }

    public function test_soft_deleted_slider_not_listed_in_index()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        Slider::create(['title' => ['en' => 'Visible'], 'slug' => 'visible']);
        $deleted = Slider::create(['title' => ['en' => 'Deleted'], 'slug' => 'deleted']);
        $deleted->delete();

        Sanctum::actingAs($this->viewUser, ['*']);
        $response = $this->getJson(self::PREFIX . '/sliders');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.data');
    }

    public function test_soft_deleted_slider_returns_404_on_show()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);
        $slider->delete();

        Sanctum::actingAs($this->viewUser, ['*']);
        $response = $this->getJson(self::PREFIX . '/sliders/' . $slider->id);
        $response->assertStatus(404);
    }

    // =========================================================================
    // PATCH /api/v1/sliders/change-status — Toggle Status (requires update-slider)
    // =========================================================================

    public function test_unauthenticated_user_cannot_change_slider_status()
    {
        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale', 'status' => false]);

        $response = $this->patchJson(self::PREFIX . '/sliders/change-status', [
            'id' => $slider->id,
        ]);
        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_gets_forbidden_for_change_status()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale', 'status' => false]);

        $response = $this->patchJson(self::PREFIX . '/sliders/change-status', [
            'id' => $slider->id,
        ]);
        $response->assertStatus(403);
    }

    public function test_authenticated_admin_can_toggle_slider_status()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale', 'status' => false]);

        $response = $this->patchJson(self::PREFIX . '/sliders/change-status', [
            'id' => $slider->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', true);
    }

    public function test_change_status_returns_422_for_missing_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->patchJson(self::PREFIX . '/sliders/change-status', []);
        $response->assertStatus(422);
    }

    public function test_change_status_returns_422_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->patchJson(self::PREFIX . '/sliders/change-status', [
            'id' => 9999,
        ]);
        $response->assertStatus(422);
    }

    // =========================================================================
    // PUT /api/v1/sliders/reorder — Reorder Sliders (requires update-slider)
    // =========================================================================

    public function test_unauthenticated_user_cannot_reorder_sliders()
    {
        $response = $this->putJson(self::PREFIX . '/sliders/reorder', [
            'sliders' => [1, 2],
        ]);
        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_gets_forbidden_for_reorder()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/sliders/reorder', [
            'sliders' => [1, 2],
        ]);
        $response->assertStatus(403);
    }

    public function test_authenticated_admin_can_reorder_sliders()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $sliderA = Slider::create(['title' => ['en' => 'A'], 'slug' => 'a']);
        $sliderB = Slider::create(['title' => ['en' => 'B'], 'slug' => 'b']);

        $response = $this->putJson(self::PREFIX . '/sliders/reorder', [
            'sliders' => [$sliderB->id, $sliderA->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_reorder_returns_422_for_missing_sliders()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/sliders/reorder', []);
        $response->assertStatus(422);
    }

    public function test_reorder_returns_422_for_invalid_ids()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/sliders/reorder', [
            'sliders' => [9999],
        ]);
        $response->assertStatus(422);
    }

    // =========================================================================
    // Product Relation
    // =========================================================================

    public function test_create_slider_with_product_association()
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

        $response = $this->call('POST', self::PREFIX . '/sliders', [
            'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
            'products' => [$product->id],
        ], [], [
            'image_desktop' => $imageDesktop,
            'image_mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk();
        $this->assertDatabaseHas('slider_product', [
            'slider_id' => $response->json('data.id'),
            'product_id' => $product->id,
        ]);
    }

    // =========================================================================
    // Translation Flow
    // =========================================================================

    public function test_slider_title_is_translatable()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $imageDesktop = UploadedFile::fake()->image('desktop.jpg', 100, 100);
        $imageMobile = UploadedFile::fake()->image('mobile.jpg', 100, 100);

        $response = $this->call('POST', self::PREFIX . '/sliders', [
            'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
        ], [], [
            'image_desktop' => $imageDesktop,
            'image_mobile' => $imageMobile,
        ], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertOk();

        $sliderId = $response->json('data.id');

        $response = $this->withHeaders(['lang' => 'ar'])->getJson(self::PREFIX . '/sliders');
        $response->assertJsonPath('data.data.0.title', 'تخفيضات الصيف');

        $response = $this->withHeaders(['lang' => 'en'])->getJson(self::PREFIX . '/sliders');
        $response->assertJsonPath('data.data.0.title', 'Summer Sale');
    }

    // =========================================================================
    // Response Structure
    // =========================================================================

    public function test_slider_resource_structure_on_show()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $slider = Slider::create(['title' => ['en' => 'Summer Sale'], 'slug' => 'summer-sale']);

        $response = $this->getJson(self::PREFIX . '/sliders/' . $slider->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'title', 'slug', 'image' => ['desktop', 'mobile'], 'status', 'order',
            ],
        ]);
        $response->assertJsonPath('data.image.desktop', '');
        $response->assertJsonPath('data.image.mobile', '');
    }

    public function test_slider_title_is_object_on_show()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $slider = Slider::create(['title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'], 'slug' => 'summer-sale']);

        $response = $this->getJson(self::PREFIX . '/sliders/' . $slider->id);

        $response->assertOk();
        $response->assertJsonIsObject('data.title');
        $response->assertJsonPath('data.title.en', 'Summer Sale');
        $response->assertJsonPath('data.title.ar', 'تخفيضات الصيف');
    }

    public function test_slider_title_is_string_on_index()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Slider::create(['title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'], 'slug' => 'summer-sale']);

        $response = $this->getJson(self::PREFIX . '/sliders');

        $response->assertOk();
        $this->assertIsString($response->json('data.data.0.title'));
        $this->assertEquals('Summer Sale', $response->json('data.data.0.title'));
    }
}
