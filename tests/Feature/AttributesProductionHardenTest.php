<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeProduct;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class AttributesProductionHardenTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $adminUser;
    private User $viewUser;
    private User $noPermUser;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

        $this->createAllTestTables();

        if (!Schema::hasTable('attribute_product')) {
            Schema::create('attribute_product', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('attribute_value_id');
                $table->unsignedBigInteger('product_variant_id');
                $table->foreign('attribute_value_id')->references('id')->on('attribute_values')->cascadeOnDelete();
                $table->foreign('product_variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('email_verified_at')->nullable();
            });
        }

        foreach (['view-attributes', 'create-attribute', 'update-attribute', 'delete-attribute'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->noPermUser = User::create([
            'name' => 'No Perm User',
            'email' => 'noperm@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000001',
            'is_active' => true,
            'type' => 'admin',
        ]);

        $this->viewUser = User::create([
            'name' => 'View User',
            'email' => 'view@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000002',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->viewUser->givePermissionTo('view-attributes');

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000003',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo(['create-attribute', 'update-attribute', 'delete-attribute', 'view-attributes']);
    }

    // =========================================================================
    // AUTHENTICATION — all endpoints require auth or permission middleware
    // =========================================================================

    public function test_guest_cannot_list_attributes()
    {
        $response = $this->getJson(self::PREFIX . '/attributes');
        $this->assertContains($response->status(), [401, 403]);
    }

    public function test_guest_cannot_show_attribute()
    {
        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $response = $this->getJson(self::PREFIX . '/attributes/' . $attribute->id);
        $this->assertContains($response->status(), [401, 403]);
    }

    public function test_guest_cannot_create_attribute()
    {
        $response = $this->postJson(self::PREFIX . '/attributes', [
            'name' => ['en' => 'Size', 'ar' => 'حجم'],
        ]);
        $this->assertEquals(401, $response->status());
    }

    public function test_guest_cannot_update_attribute()
    {
        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $response = $this->putJson(self::PREFIX . '/attributes/' . $attribute->id, [
            'name' => ['en' => 'Updated', 'ar' => 'مُحّدث'],
        ]);
        $this->assertEquals(401, $response->status());
    }

    public function test_guest_cannot_delete_attribute()
    {
        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $response = $this->deleteJson(self::PREFIX . '/attributes/' . $attribute->id);
        $this->assertEquals(401, $response->status());
    }

    public function test_view_only_user_cannot_create()
    {
        Sanctum::actingAs($this->viewUser, ['*']);
        $response = $this->postJson(self::PREFIX . '/attributes', [
            'name' => ['en' => 'Size', 'ar' => 'حجم'],
        ]);
        $response->assertStatus(403);
    }

    public function test_view_only_user_cannot_update()
    {
        Sanctum::actingAs($this->viewUser, ['*']);
        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $response = $this->putJson(self::PREFIX . '/attributes/' . $attribute->id, [
            'name' => ['en' => 'Updated', 'ar' => 'مُحّدث'],
        ]);
        $response->assertStatus(403);
    }

    public function test_view_only_user_cannot_delete()
    {
        Sanctum::actingAs($this->viewUser, ['*']);
        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $response = $this->deleteJson(self::PREFIX . '/attributes/' . $attribute->id);
        $response->assertStatus(403);
    }

    // =========================================================================
    // ATTRIBUTE CRUD
    // =========================================================================

    public function test_admin_can_create_attribute_without_values()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/attributes', [
            'name' => ['en' => 'Size', 'ar' => 'حجم'],
        ]);

        $response->assertCreated();
        $this->assertNotEmpty($response['data']['id']);
    }

    public function test_admin_can_create_attribute_with_values()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/attributes', [
            'name' => ['en' => 'Size', 'ar' => 'حجم'],
            'values' => [
                ['value' => ['en' => 'Small', 'ar' => 'صغير']],
                ['value' => ['en' => 'Large', 'ar' => 'كبير']],
            ],
        ]);

        $response->assertCreated();
        $this->assertNotEmpty($response['data']['id']);
        $this->assertDatabaseHas('attribute_values', ['attribute_id' => $response['data']['id']]);
    }

    public function test_admin_can_show_attribute_by_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $attribute->values()->createMany([
            ['value' => ['en' => 'Small', 'ar' => 'صغير'], 'slug' => 'small'],
            ['value' => ['en' => 'Large', 'ar' => 'كبير'], 'slug' => 'large'],
        ]);

        $response = $this->getJson(self::PREFIX . '/attributes/' . $attribute->id);

        $response->assertOk();
        $this->assertEquals($attribute->id, $response['data']['id']);
        $this->assertCount(2, $response['data']['values']);
    }

    public function test_admin_can_show_attribute_by_slug()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Color', 'ar' => 'لون'], 'slug' => 'color']);

        $response = $this->getJson(self::PREFIX . '/attributes/color');

        $response->assertOk();
        $this->assertEquals($attribute->id, $response['data']['id']);
    }

    public function test_show_nonexistent_attribute_returns_404()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/attributes/99999');
        $response->assertStatus(404);
    }

    public function test_admin_can_update_attribute_name_without_touching_values()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => ['en' => 'Small', 'ar' => 'صغير'],
            'slug' => 'small',
        ]);

        $response = $this->putJson(self::PREFIX . '/attributes/' . $attribute->id, [
            'name' => ['en' => 'Dimensions', 'ar' => 'أبعاد'],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('attribute_values', ['id' => $value->id, 'attribute_id' => $attribute->id]);
    }

    public function test_update_attribute_with_values_replaces_values()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $oldValue = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => ['en' => 'Small', 'ar' => 'صغير'],
            'slug' => 'small',
        ]);

        $response = $this->putJson(self::PREFIX . '/attributes/' . $attribute->id, [
            'name' => ['en' => 'Size', 'ar' => 'حجم'],
            'values' => [
                ['value' => ['en' => 'Large', 'ar' => 'كبير']],
                ['value' => ['en' => 'Medium', 'ar' => 'متوسط']],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('attribute_values', ['id' => $oldValue->id]);
    }

    public function test_admin_can_delete_attribute()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);

        $response = $this->deleteJson(self::PREFIX . '/attributes/' . $attribute->id);
        $response->assertOk();

        $this->assertDatabaseMissing('attributes', ['id' => $attribute->id]);
    }

    public function test_delete_nonexistent_attribute_returns_404()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->deleteJson(self::PREFIX . '/attributes/99999');
        $response->assertStatus(404);
    }

    // =========================================================================
    // ATTRIBUTE VALUE CRUD
    // =========================================================================

    public function test_admin_can_create_attribute_value()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);

        $response = $this->postJson(self::PREFIX . '/attribute-values', [
            'value' => ['en' => 'Extra Large', 'ar' => 'كبير جداً'],
            'attribute_id' => $attribute->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attribute_values', [
            'attribute_id' => $attribute->id,
        ]);
    }

    public function test_admin_can_show_attribute_value()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => ['en' => 'Small', 'ar' => 'صغير'],
            'slug' => 'small',
        ]);

        $response = $this->getJson(self::PREFIX . '/attribute-values/' . $value->id);

        $response->assertOk();
        $this->assertEquals($value->id, $response['data']['id']);
    }

    public function test_admin_can_update_attribute_value()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => ['en' => 'Small', 'ar' => 'صغير'],
            'slug' => 'small',
        ]);

        $response = $this->putJson(self::PREFIX . '/attribute-values/' . $value->id, [
            'value' => ['en' => 'Medium', 'ar' => 'متوسط'],
            'attribute_id' => $attribute->id,
        ]);

        $response->assertOk();
    }

    public function test_update_nonexistent_value_returns_error()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);

        $response = $this->putJson(self::PREFIX . '/attribute-values/99999', [
            'value' => ['en' => 'Medium', 'ar' => 'متوسط'],
            'attribute_id' => $attribute->id,
        ]);

        $this->assertContains($response->status(), [400, 404]);
    }

    public function test_admin_can_delete_attribute_value()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => ['en' => 'To Delete', 'ar' => 'للحذف'],
            'slug' => 'to-delete',
        ]);

        $response = $this->deleteJson(self::PREFIX . '/attribute-values/' . $value->id);
        $response->assertOk();

        $this->assertDatabaseMissing('attribute_values', ['id' => $value->id]);
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    public function test_create_attribute_requires_name()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/attributes', []);
        $response->assertStatus(422);
    }

    public function test_create_attribute_value_requires_valid_attribute_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/attribute-values', [
            'value' => ['en' => 'Test', 'ar' => 'اختبار'],
            'attribute_id' => 99999,
        ]);
        $response->assertStatus(422);
    }

    public function test_create_attribute_value_requires_value_array()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);

        $response = $this->postJson(self::PREFIX . '/attribute-values', [
            'value' => 'plain string',
            'attribute_id' => $attribute->id,
        ]);
        $response->assertStatus(422);
    }

    // =========================================================================
    // RESOURCE STRUCTURE
    // =========================================================================

    public function test_attribute_list_response_structure()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);

        $response = $this->getJson(self::PREFIX . '/attributes');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'data' => [
                    '*' => ['id', 'name', 'slug'],
                ],
            ],
        ]);
    }

    public function test_attribute_show_response_structure()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $attribute->values()->createMany([
            ['value' => ['en' => 'Small', 'ar' => 'صغير'], 'slug' => 'small'],
        ]);

        $response = $this->getJson(self::PREFIX . '/attributes/' . $attribute->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id', 'name', 'slug', 'values' => [
                    '*' => ['id', 'value', 'slug'],
                ],
            ],
        ]);
    }

    public function test_attribute_value_show_response_structure()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => ['en' => 'Small', 'ar' => 'صغير'],
            'slug' => 'small',
        ]);

        $response = $this->getJson(self::PREFIX . '/attribute-values/' . $value->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id', 'value', 'slug', 'attribute',
            ],
        ]);
    }

    // =========================================================================
    // CASCADE BEHAVIOR
    // =========================================================================

    public function test_deleting_attribute_cascades_to_values()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Test', 'ar' => 'اختبار'], 'slug' => 'test']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => ['en' => 'Child', 'ar' => 'طفل'],
            'slug' => 'child',
        ]);

        $this->deleteJson(self::PREFIX . '/attributes/' . $attribute->id);

        $this->assertDatabaseMissing('attribute_values', ['id' => $value->id]);
    }

    public function test_deleting_attribute_value_cascades_to_pivot()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Test', 'ar' => 'اختبار'], 'slug' => 'test']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => ['en' => 'Child', 'ar' => 'طفل'],
            'slug' => 'child',
        ]);

        $product = Product::create([
            'name' => ['en' => 'Test Product', 'ar' => 'منتج اختبار'],
            'slug' => 'test-product',
            'sku' => 'TST-001',
            'price' => 100,
            'product_type' => 'variable',
            'status' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-TEST',
            'price' => 100,
            'stock_quantity' => 10,
        ]);

        AttributeProduct::create([
            'product_variant_id' => $variant->id,
            'attribute_value_id' => $value->id,
        ]);

        $this->assertDatabaseHas('attribute_product', ['attribute_value_id' => $value->id]);

        $this->deleteJson(self::PREFIX . '/attribute-values/' . $value->id);

        $this->assertDatabaseMissing('attribute_product', ['attribute_value_id' => $value->id]);
    }

    // =========================================================================
    // BUG-A REGRESSION: Update attribute preserves existing product associations
    // =========================================================================

    public function test_update_attribute_preserves_unaffected_values()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => ['en' => 'Size', 'ar' => 'حجم'], 'slug' => 'size']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => ['en' => 'Small', 'ar' => 'صغير'],
            'slug' => 'small',
        ]);

        $product = Product::create([
            'name' => ['en' => 'Test Product', 'ar' => 'منتج اختبار'],
            'slug' => 'test-product',
            'sku' => 'TST-002',
            'price' => 100,
            'product_type' => 'variable',
            'status' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-TEST-002',
            'price' => 100,
            'stock_quantity' => 10,
        ]);

        AttributeProduct::create([
            'product_variant_id' => $variant->id,
            'attribute_value_id' => $value->id,
        ]);

        $this->putJson(self::PREFIX . '/attributes/' . $attribute->id, [
            'name' => ['en' => 'Size', 'ar' => 'حجم'],
            'values' => [
                ['value' => ['en' => 'Small', 'ar' => 'صغير']],
                ['value' => ['en' => 'Large', 'ar' => 'كبير']],
            ],
        ]);

        $this->assertDatabaseHas('attribute_values', ['id' => $value->id]);
        $this->assertDatabaseHas('attribute_product', [
            'product_variant_id' => $variant->id,
            'attribute_value_id' => $value->id,
        ]);
    }

    // =========================================================================
    // PAGINATION
    // =========================================================================

    public function test_list_attributes_paginates()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        foreach (['Size', 'Color', 'Material', 'Weight'] as $name) {
            Attribute::create(['name' => ['en' => $name, 'ar' => $name], 'slug' => strtolower($name)]);
        }

        $response = $this->getJson(self::PREFIX . '/attributes?limit=2');

        $response->assertOk();
        $this->assertCount(2, $response['data']['data']);
    }
}
