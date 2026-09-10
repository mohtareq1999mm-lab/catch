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

class StaticPageCrudTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    private User $adminUser;

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
            'email' => 'admin.crud@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000202',
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

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
    }

    private function createStaticPage(array $overrides = []): StaticPage
    {
        $defaults = [
            'slug' => 'about-us',
            'title' => ['en' => 'About Us', 'ar' => 'من نحن'],
            'is_active' => true,
        ];
        return StaticPage::create(array_merge($defaults, $overrides));
    }

    private function createStaticSection(StaticPage $page, array $overrides = []): StaticSection
    {
        $defaults = [
            'static_page_id' => $page->id,
            'title' => ['en' => 'Our Story', 'ar' => 'قصتنا'],
            'content' => ['en' => ['heading' => 'Welcome'], 'ar' => ['heading' => 'مرحبا']],
        ];
        return StaticSection::create(array_merge($defaults, $overrides));
    }

    public function test_admin_can_list_static_pages(): void
    {
        $this->createStaticPage();
        $this->createStaticPage(['slug' => 'privacy-policy', 'title' => ['en' => 'Privacy', 'ar' => 'الخصوصية']]);
        $this->actAsAdmin();

        $this->getJson(self::PREFIX . '/static-pages')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_view_single_static_page(): void
    {
        $page = $this->createStaticPage();
        $this->createStaticSection($page);
        $this->actAsAdmin();

        $this->getJson(self::PREFIX . '/static-pages/' . $page->slug)
            ->assertOk()
            ->assertJsonPath('data.slug', 'about-us')
            ->assertJsonCount(1, 'data.sections');
    }

    public function test_admin_can_update_static_page_title(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, [
            'title' => ['en' => 'Updated About Us', 'ar' => 'من نحن محدث'],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Updated About Us');

        $this->assertSame('Updated About Us', $page->refresh()->getTranslation('title', 'en'));
    }

    public function test_admin_can_deactivate_static_page(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, ['is_active' => 0])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($page->refresh()->is_active);
    }

    public function test_admin_can_create_static_section(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
            'title' => ['en' => 'Our Story', 'ar' => 'قصتنا'],
            'content' => ['en' => ['heading' => 'Welcome'], 'ar' => ['heading' => 'مرحبا']],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.static_page_id', $page->id)
            ->assertJsonPath('data.order', 1);

        $this->assertSame(1, StaticSection::where('static_page_id', $page->id)->count());
    }

    public function test_admin_can_update_static_section(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAsAdmin();

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, [
            'title' => ['en' => 'Updated Story'],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Updated Story');

        $this->assertSame('Updated Story', $section->refresh()->getTranslation('title', 'en'));
    }

    public function test_admin_can_delete_static_section(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAsAdmin();

        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('static_sections', ['id' => $section->id]);
    }

    public function test_admin_can_reorder_static_sections(): void
    {
        $page = $this->createStaticPage();
        $first = $this->createStaticSection($page, ['title' => ['en' => 'First']]);
        $second = $this->createStaticSection($page, ['title' => ['en' => 'Second']]);
        $third = $this->createStaticSection($page, ['title' => ['en' => 'Third']]);
        $this->actAsAdmin();

        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/reorder', [
            'sections' => [$third->id, $first->id, $second->id],
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, $third->refresh()->order);
        $this->assertSame(2, $first->refresh()->order);
        $this->assertSame(3, $second->refresh()->order);
    }

    public function test_reorder_persists_new_order_in_database(): void
    {
        $page = $this->createStaticPage();
        $first = $this->createStaticSection($page, ['title' => ['en' => 'First']]);
        $second = $this->createStaticSection($page, ['title' => ['en' => 'Second']]);
        $this->actAsAdmin();

        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/reorder', [
            'sections' => [$second->id, $first->id],
        ])->assertOk();

        $rows = \Illuminate\Support\Facades\DB::table('static_sections')
            ->where('static_page_id', $page->id)
            ->orderBy('order')
            ->pluck('id')
            ->all();

        $this->assertEquals([$second->id, $first->id], $rows);
    }

    public function test_crud_flow_full_lifecycle(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $created = $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
            'title' => ['en' => 'Intro', 'ar' => 'مقدمة'],
            'content' => ['en' => ['text' => 'Hi'], 'ar' => ['text' => 'مرحبا']],
        ])->assertOk()->json('data');

        $updated = $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $created['id'], [
            'title' => ['en' => 'Intro Updated'],
        ])->assertOk()->json('data');

        $this->assertSame('Intro Updated', $updated['title']);

        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $created['id'])->assertOk();

        $this->assertDatabaseMissing('static_sections', ['id' => $created['id']]);
    }
}