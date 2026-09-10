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

class CategoryRelationshipTest extends TestCase
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

    public function test_parent_child_relationship(): void
    {
        Sanctum::actingAs($this->adminUser);

        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent']);

        $child = Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id]);

        $this->assertTrue($parent->children->contains($child));
        $this->assertEquals($parent->id, $child->parent->id);
    }

    public function test_show_returns_parent(): void
    {
        Sanctum::actingAs($this->adminUser);

        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent']);

        $child = Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id]);

        $response = $this->getJson(self::PREFIX . '/categories/' . $child->id);
        $response->assertOk();
    }

    public function test_children_are_returned_with_parent(): void
    {
        Sanctum::actingAs($this->adminUser);

        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent']);

        $child1 = Category::create([
            'name' => ['en' => 'Child 1'],
            'slug' => 'child-1',
            'parent_id' => $parent->id]);

        $child2 = Category::create([
            'name' => ['en' => 'Child 2'],
            'slug' => 'child-2',
            'parent_id' => $parent->id]);

        $response = $this->getJson(self::PREFIX . '/categories/' . $parent->id);
        $response->assertOk();
    }

    public function test_deleting_parent_does_not_cascade_delete_children(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent']);

        $child = Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id]);

        $parent->delete();

        $child->refresh();
        $this->assertNotNull($child);
        $this->assertNull($child->parent);
    }

    public function test_setting_invalid_parent_returns_validation_error(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Test'],
            'slug' => 'test']);

        $response = $this->putJson(self::PREFIX . '/categories/' . $category->id, [
            'parent_id' => 99999]);

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
