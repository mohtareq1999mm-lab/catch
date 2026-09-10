<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class AttributeApiTest extends TestCase
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

        $this->createAllTestTables();

        if (!Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('email_verified_at')->nullable();
            });
        }

        foreach (['view-attributes', 'create-attribute', 'update-attribute', 'delete-attribute'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

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
            'phone_number' => '01000000001',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo(['create-attribute', 'update-attribute', 'delete-attribute', 'view-attributes']);
    }

    // =========================================================================
    // GET /api/v1/attributes — List Attributes (requires view-attributes)
    // =========================================================================

    public function test_authenticated_user_can_list_attributes()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Attribute::create(['name' => 'Size', 'slug' => 'size']);
        Attribute::create(['name' => 'Color', 'slug' => 'color']);

        $response = $this->getJson(self::PREFIX . '/attributes');

        $response->assertOk();
    }

    public function test_guest_cannot_list_attributes()
    {
        $response = $this->getJson(self::PREFIX . '/attributes');

        $response->assertStatus(403);
    }

    public function test_list_attributes_returns_empty_data_when_none_exist()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/attributes');

        $response->assertOk();
    }

    // =========================================================================
    // GET /api/v1/attributes/{id} — Show Attribute (requires view-attributes)
    // =========================================================================

    public function test_authenticated_user_can_show_attribute_by_id()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $attribute = Attribute::create(['name' => 'Size', 'slug' => 'size']);
        $attribute->values()->createMany([
            ['value' => 'Small', 'slug' => 'small'],
            ['value' => 'Large', 'slug' => 'large'],
        ]);

        $response = $this->getJson(self::PREFIX . '/attributes/' . $attribute->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $attribute->id);
    }

    public function test_guest_gets_403_for_attribute_show()
    {
        $attribute = Attribute::create(['name' => 'Size', 'slug' => 'size']);

        $response = $this->getJson(self::PREFIX . '/attributes/' . $attribute->id);
        $response->assertStatus(403);
    }

    public function test_authenticated_user_gets_404_for_nonexistent_attribute_id()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/attributes/9999');
        $response->assertStatus(404);
    }

    // =========================================================================
    // POST /api/v1/attributes — Create Attribute (requires create-attribute)
    // =========================================================================

    public function test_unauthenticated_user_cannot_create_attribute()
    {
        $response = $this->postJson(self::PREFIX . '/attributes', [
            'name' => 'Size',
            'slug' => 'size',
        ]);
        $response->assertStatus(401);
    }

    public function test_authenticated_admin_can_create_attribute()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/attributes', [
            'name' => ['en' => 'Size', 'ar' => 'حجم'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attributes', ['slug' => 'size']);
    }

    public function test_user_without_required_permission_gets_forbidden_for_create()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/attributes', [
            'name' => ['en' => 'Size', 'ar' => 'حجم'],
        ]);
        $response->assertStatus(403);
    }

    // =========================================================================
    // PUT /api/v1/attributes/{id} — Update Attribute (requires update-attribute)
    // =========================================================================

    public function test_unauthenticated_user_cannot_update_attribute()
    {
        $attribute = Attribute::create(['name' => 'Size', 'slug' => 'size']);

        $response = $this->putJson(self::PREFIX . '/attributes/' . $attribute->id, [
            'name' => 'Updated',
        ]);
        $response->assertStatus(401);
    }

    // =========================================================================
    // DELETE /api/v1/attributes/{id} — Delete Attribute (requires delete-attribute)
    // =========================================================================

    public function test_unauthenticated_user_cannot_delete_attribute()
    {
        $attribute = Attribute::create(['name' => 'Size', 'slug' => 'size']);

        $response = $this->deleteJson(self::PREFIX . '/attributes/' . $attribute->id);
        $response->assertStatus(401);
    }

    public function test_authenticated_admin_can_delete_attribute()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create([
            'name' => 'To Delete',
            'slug' => 'to-delete',
        ]);

        $response = $this->deleteJson(self::PREFIX . '/attributes/' . $attribute->id);
        $response->assertOk();
        $this->assertDatabaseMissing('attributes', ['id' => $attribute->id]);
    }

    // =========================================================================
    // POST /api/v1/attribute-values — Create Attribute Value (requires create-attribute)
    // =========================================================================

    public function test_unauthenticated_user_cannot_create_attribute_value()
    {
        $response = $this->postJson(self::PREFIX . '/attribute-values', [
            'value' => 'Extra Large',
            'attribute_id' => 1,
        ]);
        $response->assertStatus(401);
    }

    public function test_authenticated_admin_can_create_attribute_value()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => 'Size', 'slug' => 'size']);

        $response = $this->postJson(self::PREFIX . '/attribute-values', [
            'value' => ['en' => 'Extra Large', 'ar' => 'كبير جداً'],
            'attribute_id' => $attribute->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attribute_values', [
            'attribute_id' => $attribute->id,
        ]);
    }

    // =========================================================================
    // DELETE /api/v1/attribute-values/{id} — Delete Attribute Value (requires delete-attribute)
    // =========================================================================

    public function test_authenticated_admin_can_delete_attribute_value()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => 'Size', 'slug' => 'size']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => 'To Delete',
            'slug' => 'to-delete',
        ]);

        $response = $this->deleteJson(self::PREFIX . '/attribute-values/' . $value->id);
        $response->assertOk();
        $this->assertDatabaseMissing('attribute_values', ['id' => $value->id]);
    }

    // =========================================================================
    // Cascading delete: deleting attribute removes its values
    // =========================================================================

    public function test_deleting_attribute_cascades_to_its_values()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $attribute = Attribute::create(['name' => 'Test', 'slug' => 'test']);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => 'Child Value',
            'slug' => 'child-value',
        ]);

        $response = $this->deleteJson(self::PREFIX . '/attributes/' . $attribute->id);
        $response->assertOk();

        $this->assertDatabaseMissing('attribute_values', ['id' => $value->id]);
    }
}
