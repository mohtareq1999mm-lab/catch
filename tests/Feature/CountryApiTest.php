<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class CountryApiTest extends TestCase
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

        foreach (['view-country', 'create-country', 'update-country', 'delete-country'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->viewUser = User::create([
            'name' => 'View User',
            'email' => 'view.country@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000002',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->viewUser->givePermissionTo('view-country');

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin.country@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000001',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo(['create-country', 'update-country', 'delete-country', 'view-country']);
    }

    public function test_guest_gets_401_for_list_countries()
    {
        $response = $this->getJson(self::PREFIX . '/countries');
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_show_country()
    {
        $country = Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر']]);
        $response = $this->getJson(self::PREFIX . '/countries/' . $country->id);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_create_country()
    {
        $response = $this->postJson(self::PREFIX . '/countries', [
            'name' => ['en' => 'Egypt', 'ar' => 'مصر'],
        ]);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_update_country()
    {
        $country = Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر']]);
        $response = $this->putJson(self::PREFIX . '/countries/' . $country->id, [
            'name' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(401);
    }

    public function test_guest_gets_401_for_delete_country()
    {
        $country = Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر']]);
        $response = $this->deleteJson(self::PREFIX . '/countries/' . $country->id);
        $response->assertStatus(401);
    }

    public function test_user_without_view_permission_gets_forbidden_for_index()
    {
        $user = User::create([
            'name' => 'No Perm User',
            'email' => 'noperm.country@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'phone_number' => '01000000003',
            'is_active' => true,
            'type' => 'admin',
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(self::PREFIX . '/countries');
        $response->assertStatus(403);
    }

    public function test_user_without_create_permission_gets_forbidden_for_store()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/countries', [
            'name' => ['en' => 'Egypt', 'ar' => 'مصر'],
        ]);
        $response->assertStatus(403);
    }

    public function test_user_without_update_permission_gets_forbidden_for_update()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $country = Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر']]);

        $response = $this->putJson(self::PREFIX . '/countries/' . $country->id, [
            'name' => ['en' => 'Updated'],
        ]);
        $response->assertStatus(403);
    }

    public function test_user_without_delete_permission_gets_forbidden_for_destroy()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $country = Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر']]);

        $response = $this->deleteJson(self::PREFIX . '/countries/' . $country->id);
        $response->assertStatus(403);
    }

    public function test_authenticated_user_can_list_countries()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر'], 'phone_code' => '+20']);
        Country::create(['name' => ['en' => 'USA', 'ar' => 'الولايات المتحدة'], 'phone_code' => '+1']);

        $response = $this->getJson(self::PREFIX . '/countries');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['status', 'message', 'success', 'data']);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_countries_returns_empty_when_none_exist()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/countries');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_authenticated_user_can_show_country()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $country = Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر'], 'phone_code' => '+20']);

        $response = $this->getJson(self::PREFIX . '/countries/' . $country->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $country->id);
        $response->assertJsonPath('data.phone_code', '+20');
        $response->assertJsonStructure(['status', 'message', 'success', 'data' => [
            'id', 'name', 'phone_code', 'status', 'created_at',
        ]]);
    }

    public function test_show_country_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/countries/9999');
        $response->assertStatus(404);
    }

    public function test_authenticated_admin_can_create_country()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/countries', [
            'name' => ['en' => 'Egypt', 'ar' => 'مصر'],
            'phone_code' => '+20',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.phone_code', '+20');
        $response->assertJsonStructure(['status', 'message', 'success', 'data' => [
            'id', 'name', 'phone_code', 'status', 'created_at',
        ]]);
        $this->assertDatabaseHas('countries', ['phone_code' => '+20']);
    }

    public function test_create_country_returns_422_for_missing_name()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/countries', []);

        $response->assertStatus(422);
    }

    public function test_authenticated_admin_can_update_country()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $country = Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر']]);

        $response = $this->putJson(self::PREFIX . '/countries/' . $country->id, [
            'phone_code' => '+2',
            'status' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.phone_code', '+2');
        $response->assertJsonPath('data.status', false);
    }

    public function test_update_country_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->putJson(self::PREFIX . '/countries/9999', [
            'phone_code' => '+2',
        ]);
        $response->assertStatus(404);
    }

    public function test_authenticated_admin_can_delete_country()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $country = Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر']]);

        $response = $this->deleteJson(self::PREFIX . '/countries/' . $country->id);
        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_delete_country_returns_404_for_nonexistent_id()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->deleteJson(self::PREFIX . '/countries/9999');
        $response->assertStatus(404);
    }

    public function test_country_resource_structure_on_show()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $country = Country::create(['name' => ['en' => 'Egypt', 'ar' => 'مصر'], 'phone_code' => '+20']);

        $response = $this->getJson(self::PREFIX . '/countries/' . $country->id);

        $response->assertOk();
        $response->assertJsonStructure(['status', 'message', 'success', 'data' => [
            'id', 'name', 'phone_code', 'status', 'created_at',
        ]]);
    }

    public function test_country_mass_assignment_protection()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/countries', [
            'name' => ['en' => 'Egypt', 'ar' => 'مصر'],
            'id' => 99999,
        ]);

        $response->assertCreated();
        $this->assertDatabaseMissing('countries', ['id' => 99999]);
    }
}
