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

class CategoryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->category = Category::create([
            'name' => ['en' => 'Test Category'],
            'slug' => 'test-category']);
    }

    public function test_user_with_view_only_can_index_and_show(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::VIEW_CATEGORIES]);
        Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/categories')->assertOk();
        $this->getJson(self::PREFIX . '/categories/' . $this->category->id)->assertOk();
    }

    public function test_user_with_view_only_cannot_create(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::VIEW_CATEGORIES]);
        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/categories', [
            'name' => ['en' => 'New Cat']]);
        $response->assertForbidden();
    }

    public function test_user_with_view_only_cannot_update(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::VIEW_CATEGORIES]);
        Sanctum::actingAs($user);

        $response = $this->putJson(self::PREFIX . '/categories/' . $this->category->id, [
            'name' => ['en' => 'Updated']]);
        $response->assertForbidden();
    }

    public function test_user_with_view_only_cannot_delete(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::VIEW_CATEGORIES]);
        Sanctum::actingAs($user);

        $response = $this->deleteJson(self::PREFIX . '/categories/' . $this->category->id);
        $response->assertForbidden();
    }

    public function test_user_with_view_only_cannot_toggle_featured(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::VIEW_CATEGORIES]);
        Sanctum::actingAs($user);

        $response = $this->putJson(self::PREFIX . '/categories/feature', [
            'id' => $this->category->id]);
        $response->assertForbidden();
    }

    public function test_user_with_create_only_can_create(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::CREATE_CATEGORY]);
        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/categories', [
            'name' => ['en' => 'New Category']]);

        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_user_with_create_only_cannot_index(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::CREATE_CATEGORY]);
        Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/categories')->assertForbidden();
    }

    public function test_user_with_update_only_can_update(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::UPDATE_CATEGORY]);
        Sanctum::actingAs($user);

        $response = $this->putJson(self::PREFIX . '/categories/' . $this->category->id, [
            'name' => ['en' => 'Updated Name']]);
        $response->assertOk();
    }

    public function test_user_with_update_only_cannot_delete(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::UPDATE_CATEGORY]);
        Sanctum::actingAs($user);

        $this->deleteJson(self::PREFIX . '/categories/' . $this->category->id)->assertForbidden();
    }

    public function test_user_with_delete_only_can_delete(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::DELETE_CATEGORY]);
        Sanctum::actingAs($user);

        $this->deleteJson(self::PREFIX . '/categories/' . $this->category->id)->assertOk();
    }

    public function test_user_with_no_category_permissions_gets_forbidden(): void
    {
        $user = $this->createUserWithPermissions([PermissionEnum::VIEW_USERS]);
        Sanctum::actingAs($user);

        $this->getJson(self::PREFIX . '/categories')->assertForbidden();
        $this->getJson(self::PREFIX . '/categories/' . $this->category->id)->assertForbidden();
    }

    private function createUserWithPermissions(array $permissions): User
    {
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $user = User::create([
            'name' => 'Test User',
            'email' => 'testuser@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin']);

        foreach ($permissions as $perm) {
            $user->givePermissionTo($perm);
        }

        return $user;
    }
}
