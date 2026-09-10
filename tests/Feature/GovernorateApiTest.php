<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class GovernorateApiTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $adminUser;

    private User $viewUser;

    private Country $country;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

        config(['scout.driver' => 'null']);

        $this->createAllTestTables();

        foreach (['view-governorate', 'create-governorate', 'update-governorate', 'delete-governorate'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->country = Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر']]);

        $this->viewUser = User::create([
            'name' => 'View User',
            'email' => 'view.gov@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000002',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->viewUser->givePermissionTo('view-governorate');

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin.gov@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000001',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo(['create-governorate', 'update-governorate', 'delete-governorate', 'view-governorate']);
    }

    // =========================================================================
    // Authentication Tests
    // =========================================================================

    public function test_guest_gets_401_for_list_governorates()
    {
        $response = $this->getJson(self::PREFIX . '/governorates');
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_show_governorate()
    {
        $gov = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id]);
        $response = $this->getJson(self::PREFIX . '/governorates/' . $gov->id);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_create_governorate()
    {
        $response = $this->postJson(self::PREFIX . '/governorates', [
            'name' => ['en' => 'Cairo', 'ar' => 'القاهرة'],
            'country_id' => $this->country->id,
        ]);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_update_governorate()
    {
        $gov = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id]);
        $response = $this->putJson(self::PREFIX . '/governorates/' . $gov->id, [
            'name' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_delete_governorate()
    {
        $gov = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id]);
        $response = $this->deleteJson(self::PREFIX . '/governorates/' . $gov->id);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_bulk_status()
    {
        $gov = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id]);
        $response = $this->putJson(self::PREFIX . '/governorates/change-status', [
            'ids' => [$gov->id],
            'status' => 1,
        ]);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_toggle_fast_shipping()
    {
        $response = $this->putJson(self::PREFIX . '/governorates/9999/fast-shipping', [
            'is_fast_shipping_enabled' => true,
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
            'email' => 'noperm.gov@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000003',
            'is_active' => true,
            'type' => 'admin',
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(self::PREFIX . '/governorates');
        $response->assertStatus(403);
    }

    public function test_user_without_create_permission_gets_forbidden_for_store()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/governorates', [
            'name' => ['en' => 'Cairo', 'ar' => 'القاهرة'],
            'country_id' => $this->country->id,
        ]);
        $response->assertStatus(403);
    }

    public function test_user_without_update_permission_gets_forbidden_for_update()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $gov = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id]);

        $response = $this->putJson(self::PREFIX . '/governorates/' . $gov->id, [
            'name' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(403);
    }

    public function test_user_without_delete_permission_gets_forbidden_for_destroy()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $gov = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id]);

        $response = $this->deleteJson(self::PREFIX . '/governorates/' . $gov->id);
        $response->assertStatus(403);
    }

    // =========================================================================
    // GET /api/v1/governorates — List Governorates
    // =========================================================================

    public function test_authenticated_user_can_list_governorates()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id]);
        Governorate::create(['name' => ['en' => 'Giza', 'ar' => 'الجيزة'], 'country_id' => $this->country->id]);

        $response = $this->getJson(self::PREFIX . '/governorates');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['status', 'message', 'success', 'data']);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_governorates_returns_empty_when_none_exist()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/governorates');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // =========================================================================
    // GET /api/v1/governorates/{id} — Show Governorate
    // =========================================================================

    public function test_authenticated_user_can_show_governorate()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $gov = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id]);

        $response = $this->getJson(self::PREFIX . '/governorates/' . $gov->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $gov->id);
        $response->assertJsonPath('data.country_id', $this->country->id);
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'country_id', 'name', 'status', 'is_fast_shipping_enabled', 'created_at',
            ],
        ]);
    }

    public function test_show_governorate_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/governorates/9999');
        $response->assertStatus(404);
    }

    // =========================================================================
    // POST /api/v1/governorates — Create Governorate
    // =========================================================================

    public function test_authenticated_admin_can_create_governorate()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/governorates', [
            'name' => ['en' => 'Cairo', 'ar' => 'القاهرة'],
            'country_id' => $this->country->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['status', 'message', 'success', 'data' => [
            'id', 'country_id', 'name', 'status', 'is_fast_shipping_enabled', 'created_at',
        ]]);
        $this->assertDatabaseHas('governorates', ['country_id' => $this->country->id]);
    }

    public function test_create_governorate_returns_422_for_missing_country_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/governorates', [
            'name' => ['en' => 'Cairo', 'ar' => 'القاهرة'],
        ]);

        $response->assertStatus(422);
    }

    public function test_create_governorate_returns_422_for_nonexistent_country()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/governorates', [
            'name' => ['en' => 'Cairo', 'ar' => 'القاهرة'],
            'country_id' => 9999,
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // PUT /api/v1/governorates/{id} — Update Governorate
    // =========================================================================

    public function test_authenticated_admin_can_update_governorate()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $gov = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id]);

        $response = $this->putJson(self::PREFIX . '/governorates/' . $gov->id, [
            'status' => 0,
            'is_fast_shipping_enabled' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', false);
        $response->assertJsonPath('data.is_fast_shipping_enabled', false);
    }

    public function test_update_governorate_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/governorates/9999', [
            'status' => 0,
        ]);
        $response->assertStatus(404);
    }

    // =========================================================================
    // DELETE /api/v1/governorates/{id} — Delete Governorate
    // =========================================================================

    public function test_authenticated_admin_can_delete_governorate()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $gov = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id]);

        $response = $this->deleteJson(self::PREFIX . '/governorates/' . $gov->id);
        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_delete_governorate_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->deleteJson(self::PREFIX . '/governorates/9999');
        $response->assertStatus(404);
    }

    // =========================================================================
    // PUT /api/v1/governorates/change-status — Bulk Status
    // =========================================================================

    public function test_authenticated_admin_can_update_governorate_bulk_status()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $govA = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id, 'status' => false]);
        $govB = Governorate::create(['name' => ['en' => 'Giza', 'ar' => 'الجيزة'], 'country_id' => $this->country->id, 'status' => false]);

        $response = $this->putJson(self::PREFIX . '/governorates/change-status', [
            'ids' => [$govA->id, $govB->id],
            'status' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('governorates', ['id' => $govA->id, 'status' => 1]);
        $this->assertDatabaseHas('governorates', ['id' => $govB->id, 'status' => 1]);
    }

    public function test_governorate_bulk_status_returns_422_for_missing_ids()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/governorates/change-status', []);

        $response->assertStatus(422);
    }

    // =========================================================================
    // PUT /api/v1/governorates/{id}/fast-shipping — Toggle Fast Shipping
    // =========================================================================

    public function test_authenticated_admin_can_toggle_fast_shipping()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $gov = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id, 'is_fast_shipping_enabled' => false]);

        $response = $this->putJson(self::PREFIX . '/governorates/' . $gov->id . '/fast-shipping', [
            'is_fast_shipping_enabled' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.is_fast_shipping_enabled', true);
    }

    public function test_toggle_fast_shipping_returns_404_for_nonexistent_governorate()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/governorates/9999/fast-shipping', [
            'is_fast_shipping_enabled' => true,
        ]);
        $response->assertStatus(404);
    }

    // =========================================================================
    // Translation Flow
    // =========================================================================

    public function test_governorate_name_is_translatable()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->postJson(self::PREFIX . '/governorates', [
            'name' => ['en' => 'Cairo', 'ar' => 'القاهرة'],
            'country_id' => $this->country->id,
        ]);

        $response = $this->getJson(self::PREFIX . '/governorates');
        $response->assertOk();
        $this->assertNotNull($response->json('data.0.name'));
    }

    // =========================================================================
    // Response Structure
    // =========================================================================

    public function test_governorate_resource_structure_on_show()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $gov = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $this->country->id]);

        $response = $this->getJson(self::PREFIX . '/governorates/' . $gov->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'country_id', 'name', 'status', 'is_fast_shipping_enabled', 'created_at',
            ],
        ]);
    }
}
