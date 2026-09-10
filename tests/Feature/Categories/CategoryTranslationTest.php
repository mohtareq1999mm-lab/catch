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

class CategoryTranslationTest extends TestCase
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

    public function test_create_category_with_multiple_translations(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Electronics', 'ar' => 'إلكترونيات'],
            'slug' => 'electronics',
            'details' => ['en' => 'Electronic items', 'ar' => 'الأجهزة الإلكترونية']]);

        $this->assertEquals('Electronics', $category->getTranslation('name', 'en'));
        $this->assertEquals('إلكترونيات', $category->getTranslation('name', 'ar'));
        $this->assertEquals('Electronic items', $category->getTranslation('details', 'en'));
        $this->assertEquals('الأجهزة الإلكترونية', $category->getTranslation('details', 'ar'));
    }

    public function test_resource_returns_translated_name_not_raw_json(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'English Name', 'ar' => 'اسم عربي'],
            'slug' => 'translated-cat']);

        $response = $this->getJson(self::PREFIX . '/categories/' . $category->id);

        $response->assertOk();
        $nameValue = $response->json('data.name');
        $this->assertIsArray($nameValue);
        $this->assertEquals('English Name', $nameValue['en']);
        $this->assertEquals('اسم عربي', $nameValue['ar']);
    }

    public function test_show_returns_details_in_current_locale(): void
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create([
            'name' => ['en' => 'Test', 'ar' => 'اختبار'],
            'slug' => 'test-details',
            'details' => ['en' => 'English details', 'ar' => 'تفاصيل بالعربية']]);

        $response = $this->withHeaders(['lang' => 'ar'])->getJson(self::PREFIX . '/categories/' . $category->id);

        $response->assertOk();
        $response->assertJsonPath('data.details', 'تفاصيل بالعربية');
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
