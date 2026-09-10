<?php

namespace Tests\Feature\StaticPages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\StaticPage;
use Marvel\Database\Models\StaticSection;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaticPageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    private User $adminUser;
    private User $viewUser;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

        $this->createPermissions();

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin.authz@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000203',
            'is_active' => true,
        ]);
        $this->adminUser->givePermissionTo([
            PermissionEnum::VIEW_STATIC_PAGES,
            PermissionEnum::UPDATE_STATIC_PAGES,
            PermissionEnum::CREATE_STATIC_SECTIONS,
            PermissionEnum::UPDATE_STATIC_SECTIONS,
            PermissionEnum::DELETE_STATIC_SECTIONS,
        ]);
        $this->adminUser->assignRole(RoleEnum::EDITOR);

        $this->viewUser = User::create([
            'name' => 'View User',
            'email' => 'view.authz@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000204',
            'is_active' => true,
        ]);

        Cache::flush();
    }

    private function createPermissions(): void
    {
        $permissions = [
            PermissionEnum::VIEW_STATIC_PAGES,
            PermissionEnum::UPDATE_STATIC_PAGES,
            PermissionEnum::CREATE_STATIC_SECTIONS,
            PermissionEnum::UPDATE_STATIC_SECTIONS,
            PermissionEnum::DELETE_STATIC_SECTIONS,
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        Role::firstOrCreate(['name' => RoleEnum::EDITOR, 'guard_name' => 'api', 'display_name' => ['en' => 'Editor', 'ar' => 'محرر']]);
        Role::firstOrCreate(['name' => RoleEnum::SUPER_ADMIN, 'guard_name' => 'api', 'display_name' => ['en' => 'Super Admin', 'ar' => 'مدير النظام']]);
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    private function createStaticPage(): StaticPage
    {
        return StaticPage::create([
            'slug' => 'about-us',
            'title' => ['en' => 'About Us', 'ar' => 'من نحن'],
            'is_active' => true,
        ]);
    }

    private function createStaticSection(StaticPage $page): StaticSection
    {
        return StaticSection::create([
            'static_page_id' => $page->id,
            'title' => ['en' => 'Our Story', 'ar' => 'قصتنا'],
            'content' => ['en' => ['heading' => 'Welcome'], 'ar' => ['heading' => 'مرحبا']],
        ]);
    }

    private function makeUserWith(string ...$permissions): User
    {
        $user = User::create([
            'name' => 'Permission User',
            'email' => uniqid('authz') . '@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '010000002' . random_int(1000000, 9999999),
            'is_active' => true,
        ]);
        $user->givePermissionTo($permissions);
        return $user;
    }

    public function test_guest_cannot_access_admin_index(): void
    {
        $this->getJson(self::PREFIX . '/static-pages')->assertUnauthorized();
    }

    public function test_guest_cannot_access_admin_show(): void
    {
        $page = $this->createStaticPage();
        $this->getJson(self::PREFIX . '/static-pages/' . $page->slug)->assertUnauthorized();
    }

    public function test_guest_cannot_update_page(): void
    {
        $page = $this->createStaticPage();
        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, ['title' => ['en' => 'X']])->assertUnauthorized();
    }

    public function test_guest_cannot_create_section(): void
    {
        $page = $this->createStaticPage();
        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
            'title' => ['en' => 'X'],
            'content' => ['en' => []],
        ])->assertUnauthorized();
    }

    public function test_guest_cannot_update_section(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, [
            'title' => ['en' => 'X'],
        ])->assertUnauthorized();
    }

    public function test_guest_cannot_delete_section(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id)->assertUnauthorized();
    }

    public function test_guest_cannot_reorder_sections(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/reorder', [
            'sections' => [$section->id],
        ])->assertUnauthorized();
    }

    public function test_user_without_permission_gets_403(): void
    {
        $page = $this->createStaticPage();
        $user = $this->makeUserWith();
        $this->actAs($user);

        $this->getJson(self::PREFIX . '/static-pages')->assertForbidden();
        $this->getJson(self::PREFIX . '/static-pages/' . $page->slug)->assertForbidden();
    }

    public function test_view_permission_allows_index_and_show_but_blocks_mutations(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAs($this->makeUserWith(PermissionEnum::VIEW_STATIC_PAGES));

        $this->getJson(self::PREFIX . '/static-pages')->assertOk();
        $this->getJson(self::PREFIX . '/static-pages/' . $page->slug)->assertOk();
        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, ['title' => ['en' => 'X']])->assertForbidden();
        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
            'title' => ['en' => 'X'],
            'content' => ['en' => []],
        ])->assertForbidden();
        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, ['title' => ['en' => 'X']])->assertForbidden();
        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/reorder', ['sections' => [$section->id]])->assertForbidden();
        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id)->assertForbidden();
    }

    public function test_update_page_permission_only_allows_update(): void
    {
        $page = $this->createStaticPage();
        $this->actAs($this->makeUserWith(PermissionEnum::UPDATE_STATIC_PAGES));

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, ['title' => ['en' => 'X']])->assertOk();
        $this->getJson(self::PREFIX . '/static-pages')->assertForbidden();
        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
            'title' => ['en' => 'X'],
            'content' => ['en' => []],
        ])->assertForbidden();
    }

    public function test_create_section_permission_only_allows_store(): void
    {
        $page = $this->createStaticPage();
        $this->actAs($this->makeUserWith(PermissionEnum::CREATE_STATIC_SECTIONS));

        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
            'title' => ['en' => 'New', 'ar' => 'جديد'],
            'content' => ['en' => ['heading' => 'Hi'], 'ar' => ['heading' => 'مرحبا']],
        ])->assertOk();

        $this->getJson(self::PREFIX . '/static-pages')->assertForbidden();
        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, ['title' => ['en' => 'X']])->assertForbidden();
    }

    public function test_update_section_permission_allows_update_and_reorder(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAs($this->makeUserWith(PermissionEnum::UPDATE_STATIC_SECTIONS));

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, ['title' => ['en' => 'Y']])->assertOk();
        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/reorder', ['sections' => [$section->id]])->assertOk();

        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id)->assertForbidden();
        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
            'title' => ['en' => 'X'],
            'content' => ['en' => []],
        ])->assertForbidden();
    }

    public function test_delete_section_permission_only_allows_delete(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAs($this->makeUserWith(PermissionEnum::DELETE_STATIC_SECTIONS));

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, ['title' => ['en' => 'X']])->assertForbidden();
        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id)->assertOk();
    }

    public function test_full_permissions_allows_all_admin_operations(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAs($this->adminUser);

        $this->getJson(self::PREFIX . '/static-pages')->assertOk();
        $this->getJson(self::PREFIX . '/static-pages/' . $page->slug)->assertOk();
        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, ['title' => ['en' => 'Z']])->assertOk();
        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
            'title' => ['en' => 'New', 'ar' => 'جديد'],
            'content' => ['en' => ['heading' => 'Hi'], 'ar' => ['heading' => 'مرحبا']],
        ])->assertOk();
        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, ['title' => ['en' => 'W']])->assertOk();
        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/reorder', ['sections' => [$section->id]])->assertOk();
        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id)->assertOk();
    }

    public function test_public_endpoints_are_accessible_without_authentication(): void
    {
        $this->createStaticPage();

        $this->getJson('/api/v1/general/static-pages')->assertOk();
        $this->getJson('/api/v1/general/static-pages/about-us')->assertOk();
    }
}