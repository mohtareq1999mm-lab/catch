<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategoryAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->adminUser = $this->createSuperAdminUser();

        Category::create([
            'name' => ['en' => 'Test Category'],
            'slug' => 'test-category']);
    }

    public function test_unauthenticated_user_cannot_create_category(): void
    {
        $response = $this->postJson(self::PREFIX . '/categories', [
            'name' => ['en' => 'New Category']]);

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_update_category(): void
    {
        $response = $this->putJson(self::PREFIX . '/categories/1', [
            'name' => ['en' => 'Updated']]);

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_delete_category(): void
    {
        $response = $this->deleteJson(self::PREFIX . '/categories/1');

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_toggle_featured(): void
    {
        $response = $this->putJson(self::PREFIX . '/categories/feature', [
            'id' => 1]);

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_list_categories(): void
    {
        $response = $this->getJson(self::PREFIX . '/categories');

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_view_category(): void
    {
        $response = $this->getJson(self::PREFIX . '/categories/1');

        $response->assertUnauthorized();
    }

    public function test_removed_featured_categories_endpoint_is_not_publicly_available(): void
    {
        // CAT-003: endpoint intentionally removed; must not resurrect implicitly.
        $response = $this->getJson(self::PREFIX . '/featured-categories');

        $response->assertNotFound();
    }

    public function test_authenticated_user_with_permissions_can_access_all_category_routes(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson(self::PREFIX . '/categories');
        $response->assertOk();

        $response = $this->getJson(self::PREFIX . '/categories/1');
        $response->assertOk();
    }

    private function createSuperAdminUser(): User
    {
        $permissions = [
            PermissionEnum::VIEW_CATEGORIES,
            PermissionEnum::CREATE_CATEGORY,
            PermissionEnum::UPDATE_CATEGORY,
            PermissionEnum::DELETE_CATEGORY];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => 'Super Admin']);

        foreach ($permissions as $perm) {
            $role->givePermissionTo($perm);
        }

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin']);

        $user->assignRole($role);

        foreach ($permissions as $perm) {
            $user->givePermissionTo($perm);
        }

        return $user;
    }
}
