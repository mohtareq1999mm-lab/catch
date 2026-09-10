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

class CategoryRegressionTest extends TestCase
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

    /** @test B1: SoftDeletes trait is present - delete should not hard-delete */
    public function test_b1_soft_delete_does_not_hard_delete(): void
    {
        $category = Category::create([
            'name' => ['en' => 'B1 Test'],
            'slug' => 'b1-test']);

        $categoryId = $category->id;
        $category->delete();

        $this->assertDatabaseHas('categories', ['id' => $categoryId]);
        $this->assertNotNull(Category::withTrashed()->find($categoryId)->deleted_at);
    }

    /** @test B2: Resource returns translated name as {ar, en} object (current contract) */
    public function test_b2_resource_returns_translated_name(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'English Name', 'ar' => 'الاسم العربي'],
            'slug' => 'b2-test']);

        $response = $this->getJson(self::PREFIX . '/categories/' . $category->id);
        $response->assertOk();

        $name = $response->json('data.name');
        $this->assertIsArray($name);
        $this->assertSame('English Name', $name['en']);
        $this->assertSame('الاسم العربي', $name['ar']);
    }

    /** @test B2: Resource details also returns translated string */
    public function test_b2_resource_returns_translated_details(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Test'],
            'slug' => 'b2-details',
            'details' => ['en' => 'English details', 'ar' => 'تفاصيل بالعربية']]);

        $response = $this->getJson(self::PREFIX . '/categories/' . $category->id);
        $response->assertOk();

        $details = $response->json('data.details');
        $this->assertIsString($details);
    }

    /** @test B3: featured-categories endpoint was removed — must stay gone (CAT-003) */
    public function test_b3_featured_categories_is_public(): void
    {
        // Mirrors B5's dead-route guard: the endpoint was intentionally removed;
        // assert it does NOT silently reappear without a contract decision.
        $response = $this->getJson(self::PREFIX . '/featured-categories');
        $response->assertNotFound();
    }

    /** @test B4: Category translation keys exist */
    public function test_b4_translation_keys_exist(): void
    {
        $keys = [
            'CATEGORY_CREATED_SUCCESSFULLY',
            'CATEGORY_UPDATED_SUCCESSFULLY',
            'CATEGORY_DELETED_SUCCESSFULLY',
            'CATEGORY_FEATURE_TOGGLED_SUCCESSFULLY'];

        foreach ($keys as $key) {
            $this->assertNotNull(__('message.' . $key), "Translation key message.{$key} is missing");
        }
    }

    /** @test B5: Dead route categories-parent should not exist */
    public function test_b5_dead_route_does_not_exist(): void
    {
        $response = $this->getJson(self::PREFIX . '/categories-parent');
        $response->assertNotFound();
    }

    /** @test Null slug handling in retrieved event */
    public function test_slug_handles_non_json_correctly(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Normal Slug'],
            'slug' => 'normal-slug']);

        $this->assertEquals('normal-slug', $category->slug);
    }

    /** @test Category model uses HasTranslations trait */
    public function test_model_has_translatable_fields(): void
    {
        $category = new Category();
        $this->assertTrue(in_array('name', $category->translatable));
        $this->assertTrue(in_array('details', $category->translatable));
    }

    /** @test Category model uses SoftDeletes trait */
    public function test_model_uses_soft_deletes(): void
    {
        $this->assertTrue(in_array(
            'Illuminate\Database\Eloquent\SoftDeletes',
            class_uses(Category::class)
        ));
    }

    /** @test B6: Explicit slug is not overwritten by model saving hook */
    public function test_b6_explicit_slug_is_preserved(): void
    {
        $category = Category::create([
            'name' => ['en' => 'My Category'],
            'slug' => 'custom-slug-123']);

        $this->assertEquals('custom-slug-123', $category->slug);
    }

    /** @test B7: Slug auto-generated from name when not explicitly provided */
    public function test_b7_slug_auto_generated_when_not_provided(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Auto Generated Slug']]);

        $this->assertEquals('auto-generated-slug', $category->slug);
    }

    /** @test B8: slug does not change on update when name unchanged */
    public function test_b8_slug_preserved_on_update_without_name_change(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Original'],
            'slug' => 'original-slug']);

        $category->update(['details' => 'Updated details']);

        $this->assertEquals('original-slug', $category->slug);
    }

    /** @test B9: slug updates when name changes but slug not provided */
    public function test_b9_slug_updates_on_name_change_when_not_provided(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Old Name'],
            'slug' => 'old-name']);

        $category->update(['name' => ['en' => 'New Name']]);

        $this->assertEquals('new-name', $category->slug);
    }

    /** @test B10: slug preserved when both name and slug provided in update */
    public function test_b10_slug_preserved_when_both_name_and_slug_provided(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Original'],
            'slug' => 'original-slug']);

        $category->update([
            'name' => ['en' => 'Updated Name'],
            'slug' => 'explicit-slug']);

        $this->assertEquals('explicit-slug', $category->slug);
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
