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

class CategoryFeaturedTest extends TestCase
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
    }

    // CAT-003: the public /featured-categories endpoint was intentionally
    // removed (see CategoryRegressionTest::test_b3_featured_categories_is_public).

    public function test_toggle_featured_requires_update_permission(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Test'],
            'slug' => 'test']);

        $user = $this->createUserWithPermissions([PermissionEnum::VIEW_CATEGORIES]);
        Sanctum::actingAs($user);

        $response = $this->putJson(self::PREFIX . '/categories/feature', [
            'id' => $category->id]);
        $response->assertForbidden();
    }

    public function test_toggle_featured_works_with_update_permission(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Toggle Me'],
            'slug' => 'toggle-me',
            'is_featured' => false]);

        $response = $this->putJson(self::PREFIX . '/categories/feature', [
            'id' => $category->id]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Category feature toggled successfully');

        $category->refresh();
        $this->assertTrue($category->is_featured);
    }

    public function test_toggle_featured_twice_reverts(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Double Toggle'],
            'slug' => 'double-toggle',
            'is_featured' => false]);

        $this->putJson(self::PREFIX . '/categories/feature', ['id' => $category->id]);
        $category->refresh();
        $this->assertTrue($category->is_featured);

        $this->putJson(self::PREFIX . '/categories/feature', ['id' => $category->id]);
        $category->refresh();
        $this->assertFalse($category->is_featured);
    }

    public function test_toggle_featured_validates_id_required(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->putJson(self::PREFIX . '/categories/feature', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['id']);
    }

    public function test_toggle_featured_validates_id_exists(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->putJson(self::PREFIX . '/categories/feature', ['id' => 99999]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['id']);
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
