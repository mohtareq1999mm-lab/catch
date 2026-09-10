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

class CategoryValidationTest extends TestCase
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

    public function test_create_returns_422_without_name(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson(self::PREFIX . '/categories', []);

        $response->assertStatus(422);
    }

    public function test_update_accepts_partial_data(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Original'],
            'slug' => 'original']);

        $response = $this->putJson(self::PREFIX . '/categories/' . $category->id, [
            'details' => 'Just updating details']);

        $response->assertOk();
    }

    public function test_update_rejects_invalid_parent_id(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Test'],
            'slug' => 'test']);

        $response = $this->putJson(self::PREFIX . '/categories/' . $category->id, [
            'parent_id' => 99999]);

        $response->assertStatus(422);
    }

    public function test_update_rejects_non_array_name(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Test'],
            'slug' => 'test']);

        $response = $this->putJson(self::PREFIX . '/categories/' . $category->id, [
            'name' => 'Not an array']);

        $response->assertStatus(422);
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
