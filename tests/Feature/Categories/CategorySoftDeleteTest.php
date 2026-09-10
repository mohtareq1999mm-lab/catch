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

class CategorySoftDeleteTest extends TestCase
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

    public function test_category_uses_soft_deletes(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Delete Me'],
            'slug' => 'delete-me']);

        $category->delete();

        $this->assertSoftDeleted($category);
        $this->assertNotNull($category->deleted_at);
    }

    public function test_deleted_category_not_in_index(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Gone'],
            'slug' => 'gone']);

        $category->delete();

        $response = $this->getJson(self::PREFIX . '/categories');
        $response->assertOk();

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertNotContains($category->id, $ids->toArray());
    }

    public function test_show_returns_404_for_soft_deleted_category(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Ghost'],
            'slug' => 'ghost']);

        $category->delete();

        $response = $this->getJson(self::PREFIX . '/categories/' . $category->id);
        $response->assertNotFound();
    }

    public function test_force_delete_removes_permanently(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Permanent Delete'],
            'slug' => 'permanent-delete']);

        $categoryId = $category->id;
        $category->forceDelete();

        $this->assertDatabaseMissing('categories', ['id' => $categoryId]);
    }

    public function test_multiple_soft_deletes(): void
    {
        Category::create(['name' => ['en' => 'Cat 1'], 'slug' => 'cat-1']);
        Category::create(['name' => ['en' => 'Cat 2'], 'slug' => 'cat-2']);
        Category::create(['name' => ['en' => 'Cat 3'], 'slug' => 'cat-3']);

        Category::whereIn('slug', ['cat-1', 'cat-3'])->delete();

        $this->assertSoftDeleted(Category::where('slug', 'cat-1')->withTrashed()->first());
        $this->assertNotSoftDeleted(Category::where('slug', 'cat-2')->first());
        $this->assertSoftDeleted(Category::where('slug', 'cat-3')->withTrashed()->first());
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
