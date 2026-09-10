<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategoryResourceTest extends TestCase
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

    public function test_index_returns_paginated_response(): void
    {
        Sanctum::actingAs($this->adminUser);

        Category::create(['name' => ['en' => 'Cat 1'], 'slug' => 'cat-1']);
        Category::create(['name' => ['en' => 'Cat 2'], 'slug' => 'cat-2']);

        $response = $this->getJson(self::PREFIX . '/categories?limit=1');

        $response->assertOk();
        $response->assertJsonStructure([
            'message',
            'status',
            'data' => [
                'data',
                'current_page',
                'from',
                'to',
                'last_page',
                'per_page',
                'total']]);
        $response->assertJsonPath('data.per_page', 1);
        $response->assertJsonPath('data.total', 2);
    }

    public function test_index_response_contains_expected_fields(): void
    {
        Sanctum::actingAs($this->adminUser);

        Category::create(['name' => ['en' => 'Test'], 'slug' => 'test']);

        $response = $this->getJson(self::PREFIX . '/categories');

        $category = $response->json('data.data.0');
        $this->assertArrayHasKey('id', $category);
        $this->assertArrayHasKey('name', $category);
        $this->assertArrayHasKey('slug', $category);
        $this->assertArrayHasKey('parent_id', $category);
        $this->assertArrayHasKey('level', $category);
        $this->assertArrayHasKey('image', $category);
        $this->assertArrayHasKey('is_featured', $category);
        $this->assertArrayHasKey('products_count', $category);
        $this->assertArrayHasKey('status', $category);
    }

    public function test_index_response_omits_details_and_children(): void
    {
        Sanctum::actingAs($this->adminUser);

        Category::create(['name' => ['en' => 'Test'], 'slug' => 'test', 'details' => 'Secret']);

        $response = $this->getJson(self::PREFIX . '/categories');

        $category = $response->json('data.data.0');
        $this->assertArrayNotHasKey('details', $category);
    }

    public function test_show_response_includes_details(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Detailed'],
            'slug' => 'detailed',
            'details' => 'Full description here']);

        $response = $this->getJson(self::PREFIX . '/categories/' . $category->id);

        $response->assertOk();
        $response->assertJsonPath('data.details', 'Full description here');
    }

    public function test_show_response_includes_children_when_loaded(): void
    {
        Sanctum::actingAs($this->adminUser);

        $parent = Category::create(['name' => ['en' => 'Parent'], 'slug' => 'parent']);
        Category::create(['name' => ['en' => 'Child'], 'slug' => 'child', 'parent_id' => $parent->id]);

        $response = $this->getJson(self::PREFIX . '/categories/' . $parent->id);

        $response->assertOk();
    }

    public function test_resource_types_in_response(): void
    {
        Sanctum::actingAs($this->adminUser);

        Category::create([
            'name' => ['en' => 'Test'],
            'slug' => 'test',
            'is_featured' => true,
            'status' => true]);

        $response = $this->getJson(self::PREFIX . '/categories');
        $category = $response->json('data.data.0');

        $this->assertIsInt($category['id']);
        $this->assertIsString($category['name']);
        $this->assertIsString($category['slug']);
        $this->assertIsBool($category['status']);
        $this->assertIsBool($category['is_featured']);
        $this->assertIsInt($category['products_count']);
    }

    // CAT-003: featured-categories endpoint removed — coverage intentionally
    // dropped; guarded by RegressionTest B3 dead-route assertion.

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
