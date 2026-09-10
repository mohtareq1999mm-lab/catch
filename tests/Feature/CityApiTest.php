<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\City;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class CityApiTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $adminUser;

    private User $viewUser;

    private Governorate $governorate;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

        config(['scout.driver' => 'null']);

        $this->createAllTestTables();

        foreach (['view-city', 'create-city', 'update-city', 'delete-city'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $country = Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر']]);
        $this->governorate = Governorate::create(['name' => ['en' => 'Cairo', 'ar' => 'القاهرة'], 'country_id' => $country->id]);

        $this->viewUser = User::create([
            'name' => 'View User',
            'email' => 'view.city@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000002',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->viewUser->givePermissionTo('view-city');

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin.city@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000001',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo(['create-city', 'update-city', 'delete-city', 'view-city']);
    }

    // =========================================================================
    // Authentication Tests
    // =========================================================================

    public function test_guest_gets_401_for_list_cities()
    {
        $response = $this->getJson(self::PREFIX . '/cities');
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_show_city()
    {
        $city = City::create(['name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'], 'governorate_id' => $this->governorate->id]);
        $response = $this->getJson(self::PREFIX . '/cities/' . $city->id);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_create_city()
    {
        $response = $this->postJson(self::PREFIX . '/cities', [
            'name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'],
            'governorate_id' => $this->governorate->id,
        ]);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_update_city()
    {
        $city = City::create(['name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'], 'governorate_id' => $this->governorate->id]);
        $response = $this->putJson(self::PREFIX . '/cities/' . $city->id, [
            'name' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_delete_city()
    {
        $city = City::create(['name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'], 'governorate_id' => $this->governorate->id]);
        $response = $this->deleteJson(self::PREFIX . '/cities/' . $city->id);
        $response->assertStatus(401);
    }

    // =========================================================================
    // Authorization Tests
    // =========================================================================

    public function test_user_without_view_permission_gets_forbidden_for_index()
    {
        $user = User::create([
            'name' => 'No Perm User',
            'email' => 'noperm.city@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000003',
            'is_active' => true,
            'type' => 'admin',
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(self::PREFIX . '/cities');
        $response->assertStatus(403);
    }

    public function test_user_without_create_permission_gets_forbidden_for_store()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/cities', [
            'name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'],
            'governorate_id' => $this->governorate->id,
        ]);
        $response->assertStatus(403);
    }

    public function test_user_without_update_permission_gets_forbidden_for_update()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $city = City::create(['name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'], 'governorate_id' => $this->governorate->id]);

        $response = $this->putJson(self::PREFIX . '/cities/' . $city->id, [
            'name' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(403);
    }

    public function test_user_without_delete_permission_gets_forbidden_for_destroy()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $city = City::create(['name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'], 'governorate_id' => $this->governorate->id]);

        $response = $this->deleteJson(self::PREFIX . '/cities/' . $city->id);
        $response->assertStatus(403);
    }

    // =========================================================================
    // GET /api/v1/cities — List Cities
    // =========================================================================

    public function test_authenticated_user_can_list_cities()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        City::create(['name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'], 'governorate_id' => $this->governorate->id]);
        City::create(['name' => ['en' => 'Maadi', 'ar' => 'المعادي'], 'governorate_id' => $this->governorate->id]);

        $response = $this->getJson(self::PREFIX . '/cities');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['status', 'message', 'success', 'data']);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_cities_returns_empty_when_none_exist()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/cities');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // =========================================================================
    // GET /api/v1/cities/{id} — Show City
    // =========================================================================

    public function test_authenticated_user_can_show_city()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $city = City::create(['name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'], 'governorate_id' => $this->governorate->id]);

        $response = $this->getJson(self::PREFIX . '/cities/' . $city->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $city->id);
        $response->assertJsonPath('data.governorate_id', $this->governorate->id);
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'governorate_id', 'name', 'created_at',
            ],
        ]);
    }

    public function test_show_city_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/cities/9999');
        $response->assertStatus(404);
    }

    // =========================================================================
    // POST /api/v1/cities — Create City
    // =========================================================================

    public function test_authenticated_admin_can_create_city()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/cities', [
            'name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'],
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.governorate_id', $this->governorate->id);
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'governorate_id', 'name', 'created_at',
            ],
        ]);
        $this->assertDatabaseHas('cities', ['governorate_id' => $this->governorate->id]);
    }

    public function test_create_city_returns_422_for_missing_name()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/cities', [
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_create_city_returns_422_for_missing_governorate_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/cities', [
            'name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'],
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // PUT /api/v1/cities/{id} — Update City
    // =========================================================================

    public function test_authenticated_admin_can_update_city()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $city = City::create(['name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'], 'governorate_id' => $this->governorate->id]);

        $response = $this->putJson(self::PREFIX . '/cities/' . $city->id, [
            'name' => ['en' => 'New Cairo', 'ar' => 'القاهرة الجديدة'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.governorate_id', $this->governorate->id);
    }

    public function test_update_city_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/cities/9999', [
            'name' => ['en' => 'Ghost'],
        ]);
        $response->assertStatus(404);
    }

    // =========================================================================
    // DELETE /api/v1/cities/{id} — Delete City
    // =========================================================================

    public function test_authenticated_admin_can_delete_city()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $city = City::create(['name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'], 'governorate_id' => $this->governorate->id]);

        $response = $this->deleteJson(self::PREFIX . '/cities/' . $city->id);
        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_delete_city_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->deleteJson(self::PREFIX . '/cities/9999');
        $response->assertStatus(404);
    }

    // =========================================================================
    // Translation Flow
    // =========================================================================

    public function test_city_name_is_translatable()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->postJson(self::PREFIX . '/cities', [
            'name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'],
            'governorate_id' => $this->governorate->id,
        ]);

        $response = $this->getJson(self::PREFIX . '/cities');
        $response->assertOk();
        $this->assertNotNull($response->json('data.0.name'));
    }

    // =========================================================================
    // Response Structure
    // =========================================================================

    public function test_city_resource_structure_on_show()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $city = City::create(['name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'], 'governorate_id' => $this->governorate->id]);

        $response = $this->getJson(self::PREFIX . '/cities/' . $city->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'governorate_id', 'name', 'created_at',
            ],
        ]);
    }

    // =========================================================================
    // Mass Assignment Protection
    // =========================================================================

    public function test_city_mass_assignment_protection()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/cities', [
            'name' => ['en' => 'Nasr City', 'ar' => 'مدينة نصر'],
            'governorate_id' => $this->governorate->id,
            'id' => 99999,
        ]);

        $response->assertCreated();
        $this->assertDatabaseMissing('cities', ['id' => 99999]);
    }
}
