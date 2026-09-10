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

class CategoryCrudTest extends TestCase
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

    public function test_can_create_category_via_model(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Electronics', 'ar' => 'إلكترونيات'],
            'slug' => 'electronics',
            'details' => 'All about electronics']);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'slug' => 'electronics',
            'is_featured' => false,
            'status' => true]);

        $this->assertTrue($category->getTranslation('name', 'en') === 'Electronics');
        $this->assertTrue($category->getTranslation('name', 'ar') === 'إلكترونيات');
    }

    public function test_can_list_categories(): void
    {
        Sanctum::actingAs($this->adminUser);

        Category::create(['name' => ['en' => 'Cat A'], 'slug' => 'cat-a']);
        Category::create(['name' => ['en' => 'Cat B'], 'slug' => 'cat-b']);

        $response = $this->getJson(self::PREFIX . '/categories');

        $response->assertOk();
        $response->assertJsonPath('data.total', 2);
        $response->assertJsonCount(2, 'data.data');
    }

    public function test_can_show_category(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Single Cat'],
            'slug' => 'single-cat',
            'details' => 'Detailed description']);

        $response = $this->getJson(self::PREFIX . '/categories/' . $category->id);

        $response->assertOk();
        // Current contract: show returns the localized {ar, en} object.
        $response->assertJsonPath('data.name', ['ar' => 'Single Cat', 'en' => 'Single Cat']);
        $response->assertJsonPath('data.details', 'Detailed description');
    }

    public function test_can_update_category(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Original Name'],
            'slug' => 'original-name']);

        $response = $this->putJson(self::PREFIX . '/categories/' . $category->id, [
            'name' => ['en' => 'Updated Name']]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Category updated successfully');

        $category->refresh();
        $this->assertEquals('Updated Name', $category->getTranslation('name', 'en'));
    }

    public function test_can_delete_category(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Delete Me'],
            'slug' => 'delete-me']);

        $response = $this->deleteJson(self::PREFIX . '/categories/' . $category->id);

        $response->assertOk();
        $response->assertJsonPath('message', 'Category deleted successfully');
        $this->assertSoftDeleted($category);
    }

    public function test_show_returns_404_for_nonexistent_category(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson(self::PREFIX . '/categories/99999');
        $response->assertNotFound();
    }

    public function test_delete_returns_404_for_nonexistent_category(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->deleteJson(self::PREFIX . '/categories/99999');
        $response->assertNotFound();
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
