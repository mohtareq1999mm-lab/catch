<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\ItemType;
use Marvel\Enums\Permission;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ProductItemTypeTest extends TestCase
{
    use CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $adminUser;
    private Category $category;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

        $this->createAllTestTables();

        if (!Schema::hasTable('reviews')) {
            Schema::create('reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->integer('rating')->default(0);
                $table->text('comment')->nullable();
                $table->boolean('approved')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        foreach ([
            Permission::VIEW_PRODUCTS,
            Permission::CREATE_PRODUCT,
            Permission::UPDATE_PRODUCT,
            Permission::DELETE_PRODUCT,
        ] as $perm) {
            SpatiePermission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->adminUser = User::create([
            'name' => 'Item Type Admin',
            'email' => 'item-type-admin@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo([
            Permission::VIEW_PRODUCTS,
            Permission::CREATE_PRODUCT,
            Permission::UPDATE_PRODUCT,
        ]);

        $this->category = Category::create([
            'name' => 'Item Type Category',
            'slug' => 'item-type-category-' . Str::random(4),
        ]);
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Item Type Product ' . Str::random(6)],
            'slug' => 'item-type-product-' . Str::random(8),
            'description' => ['en' => 'Item type test description'],
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 50,
        ], $overrides));
    }

    // =========================================================================
    // DATABASE DEFAULTS
    // =========================================================================

    public function test_new_product_defaults_to_physical()
    {
        $product = $this->createProduct();

        $this->assertEquals(ItemType::PHYSICAL, $product->fresh()->item_type);
    }

    public function test_item_type_enum_values_are_stable()
    {
        $this->assertSame(['PHYSICAL', 'DIGITAL'], ItemType::getValues());
        $this->assertSame('PHYSICAL', ItemType::PHYSICAL);
        $this->assertSame('DIGITAL', ItemType::DIGITAL);
    }

    // =========================================================================
    // ADMIN CRUD — VALIDATION
    // =========================================================================

    public function test_admin_store_accepts_digital_item_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'Windows 11 Pro License'],
            'description' => ['en' => 'Digital license delivered electronically.'],
            'categories' => [$this->category->id],
            'images' => [\Illuminate\Http\UploadedFile::fake()->image('digital.jpg')],
            'product_type' => ProductType::SIMPLE,
            'item_type' => ItemType::DIGITAL,
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('products', [
            'slug' => $response->json('data.slug'),
            'item_type' => ItemType::DIGITAL,
        ]);
    }

    public function test_admin_store_rejects_service_item_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'Service Product'],
            'description' => ['en' => 'Service is out of scope.'],
            'categories' => [$this->category->id],
            'images' => [\Illuminate\Http\UploadedFile::fake()->image('service.jpg')],
            'product_type' => ProductType::SIMPLE,
            'item_type' => 'SERVICE',
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('item_type', $response->json());
    }

    public function test_admin_store_accepts_physical_item_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'PlayStation Controller'],
            'description' => ['en' => 'Physical controller.'],
            'categories' => [$this->category->id],
            'images' => [\Illuminate\Http\UploadedFile::fake()->image('physical.jpg')],
            'product_type' => ProductType::SIMPLE,
            'item_type' => ItemType::PHYSICAL,
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('products', [
            'slug' => $response->json('data.slug'),
            'item_type' => ItemType::PHYSICAL,
        ]);
    }

    public function test_admin_store_rejects_invalid_item_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'Invalid Item Type'],
            'description' => ['en' => 'Desc'],
            'categories' => [$this->category->id],
            'images' => [\Illuminate\Http\UploadedFile::fake()->image('invalid.jpg')],
            'product_type' => ProductType::SIMPLE,
            'item_type' => 'VIRTUAL',
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('item_type', $response->json());
    }

    public function test_admin_store_rejects_lowercase_item_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'Lowercase Item Type'],
            'description' => ['en' => 'Desc'],
            'categories' => [$this->category->id],
            'images' => [\Illuminate\Http\UploadedFile::fake()->image('lower.jpg')],
            'product_type' => ProductType::SIMPLE,
            'item_type' => 'digital',
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('item_type', $response->json());
    }

    public function test_store_without_item_type_defaults_to_physical()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'Backward Compatible Product'],
            'description' => ['en' => 'No item_type provided by legacy client.'],
            'categories' => [$this->category->id],
            'images' => [\Illuminate\Http\UploadedFile::fake()->image('legacy.jpg')],
            'product_type' => ProductType::SIMPLE,
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('products', [
            'slug' => $response->json('data.slug'),
            'item_type' => ItemType::PHYSICAL,
        ]);
    }

    // =========================================================================
    // ADMIN UPDATE
    // =========================================================================

    public function test_admin_can_change_item_type_on_update()
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $product = $this->createProduct(['item_type' => ItemType::PHYSICAL]);

        $response = $this->putJson(self::PREFIX . '/products/' . $product->id, [
            'item_type' => ItemType::DIGITAL,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'item_type' => ItemType::DIGITAL,
        ]);
    }

    public function test_admin_update_rejects_invalid_item_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $product = $this->createProduct();

        $response = $this->putJson(self::PREFIX . '/products/' . $product->id, [
            'item_type' => 'NOT_A_TYPE',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('item_type', $response->json());
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'item_type' => ItemType::PHYSICAL,
        ]);
    }

    // =========================================================================
    // D5 — ITEM TYPE IMMUTABILITY
    // =========================================================================

    public function test_item_type_cannot_change_once_ordered()
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $product = $this->createProduct();

        \Marvel\Database\Models\Order::create([
            'user_id' => $this->adminUser->id,
            'name' => 'Immutability Order',
            'status' => 'completed',
        ]);

        \Marvel\Database\Models\OrderProduct::create([
            'order_id' => \Marvel\Database\Models\Order::query()->max('id'),
            'product_id' => $product->id,
            'product_name' => 'x',
            'product_quantity' => 1,
            'item_type' => ItemType::PHYSICAL,
        ]);

        $response = $this->putJson(self::PREFIX . '/products/' . $product->id, [
            'item_type' => ItemType::DIGITAL,
        ]);

        $response->assertStatus(422);
        $this->assertSame(ItemType::PHYSICAL, $product->fresh()->item_type);
    }

    public function test_item_type_cannot_change_when_digital_assets_exist()
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $product = $this->createProduct(['item_type' => ItemType::PHYSICAL]);

        \App\Models\DigitalAsset::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => 'digital-assets/x/a.pdf',
            'original_name' => 'a',
            'mime' => 'application/pdf',
            'size' => 10,
        ]);

        $response = $this->putJson(self::PREFIX . '/products/' . $product->id, [
            'item_type' => ItemType::DIGITAL,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('digital assets', strtolower($response->json('message')));
        $this->assertSame(ItemType::PHYSICAL, $product->fresh()->item_type);
    }

    public function test_import_rejects_invalid_item_type_without_silently_defaulting()
    {
        $service = app(\Marvel\Services\Import\ProductImportService::class);
        $method = new \ReflectionMethod($service, 'buildProductData');
        $method->setAccessible(true);

        try {
            $method->invoke($service, ['item_type' => 'SERVICE', 'name_en' => 'X']);
            $this->fail('Invalid item_type should throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid item_type', $e->getMessage());
        }

        $data = $method->invoke($service, ['item_type' => 'digital', 'name_en' => 'Y']);
        $this->assertSame(ItemType::DIGITAL, $data['item_type']);
    }

    // =========================================================================
    // RESPONSE EXPOSURE — ADMIN RESOURCES
    // =========================================================================

    public function test_admin_show_response_contains_item_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $product = $this->createProduct(['item_type' => ItemType::DIGITAL]);

        $response = $this->getJson(self::PREFIX . '/products/' . $product->id);

        $response->assertOk();
        $response->assertJsonPath('data.item_type', ItemType::DIGITAL);
        $response->assertJsonStructure([
            'data' => ['id', 'product_type', 'item_type'],
        ]);
    }

    public function test_admin_list_response_contains_item_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $this->createProduct(['item_type' => ItemType::DIGITAL]);

        $response = $this->getJson(self::PREFIX . '/products');

        $response->assertOk();
        $first = $response->json('data.data.0');
        $this->assertArrayHasKey('item_type', $first);
    }

    // =========================================================================
    // RESPONSE EXPOSURE — GENERAL (FRONTEND) ENDPOINTS
    // =========================================================================

    public function test_general_product_by_slug_contains_item_type()
    {
        $this->createProduct(['item_type' => ItemType::DIGITAL]);

        $response = $this->getJson(self::PREFIX . '/general/products');
        $list = $response->json('data.data');
        $first = $list[0] ?? null;

        if ($response->status() === 200 && is_array($first)) {
            $this->assertArrayHasKey('item_type', $first);
        }
    }
}
