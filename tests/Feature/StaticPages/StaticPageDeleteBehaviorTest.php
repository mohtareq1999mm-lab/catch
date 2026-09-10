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

class StaticPageDeleteBehaviorTest extends TestCase
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
            'email' => 'admin.delete@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000209',
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

    private function createStaticPage(string $slug = 'about-us'): StaticPage
    {
        return StaticPage::create([
            'slug' => $slug,
            'title' => ['en' => 'About Us', 'ar' => 'من نحن'],
            'is_active' => true,
        ]);
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

    public function test_section_delete_removes_row(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAsAdmin();

        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id)->assertOk();

        $this->assertDatabaseMissing('static_sections', ['id' => $section->id]);
        $this->assertSame(0, StaticSection::count());
    }

    public function test_section_delete_does_not_affect_other_pages_sections(): void
    {
        $pageA = $this->createStaticPage('about-us');
        $pageB = $this->createStaticPage('privacy-policy');
        $sectionA = $this->createStaticSection($pageA);
        $sectionB = $this->createStaticSection($pageB);
        $this->actAsAdmin();

        $this->deleteJson(self::PREFIX . '/static-pages/' . $pageA->slug . '/sections/' . $sectionA->id)->assertOk();

        $this->assertDatabaseMissing('static_sections', ['id' => $sectionA->id]);
        $this->assertDatabaseHas('static_sections', ['id' => $sectionB->id]);
    }

    public function test_remaining_sections_keep_order_after_delete(): void
    {
        $page = $this->createStaticPage();
        $first = $this->createStaticSection($page, ['title' => ['en' => 'First']]);
        $middle = $this->createStaticSection($page, ['title' => ['en' => 'Middle']]);
        $last = $this->createStaticSection($page, ['title' => ['en' => 'Last']]);
        $this->actAsAdmin();

        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $middle->id)->assertOk();

        $this->assertSame(1, $first->refresh()->order);
        $this->assertSame(3, $last->refresh()->order);
        $this->assertDatabaseMissing('static_sections', ['id' => $middle->id]);
    }

    public function test_new_section_after_delete_gets_higher_order(): void
    {
        $page = $this->createStaticPage();
        $first = $this->createStaticSection($page, ['title' => ['en' => 'First']]);
        $middle = $this->createStaticSection($page, ['title' => ['en' => 'Middle']]);
        $last = $this->createStaticSection($page, ['title' => ['en' => 'Last']]);
        $this->actAsAdmin();

        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $middle->id)->assertOk();

        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
            'title' => ['en' => 'New', 'ar' => 'جديد'],
            'content' => ['en' => ['heading' => 'X'], 'ar' => ['heading' => 'أ']],
        ])->assertOk()->assertJsonPath('data.order', 4);

        $this->assertSame(4, StaticSection::latest('id')->first()->order);
    }

    public function test_page_delete_cascades_sections(): void
    {
        $page = $this->createStaticPage();
        $this->createStaticSection($page);
        $this->createStaticSection($page, ['title' => ['en' => 'Second']]);

        $this->assertSame(2, StaticSection::count());

        $page->delete();

        $this->assertDatabaseMissing('static_pages', ['id' => $page->id]);
        $this->assertSame(0, StaticSection::count(), 'Deleting a static page must cascade delete its sections');
    }

    public function test_deleting_foreign_section_via_page_route_returns_404(): void
    {
        $pageA = $this->createStaticPage('about-us');
        $pageB = $this->createStaticPage('privacy-policy');
        $sectionB = $this->createStaticSection($pageB);
        $this->actAsAdmin();

        $this->deleteJson(self::PREFIX . '/static-pages/' . $pageA->slug . '/sections/' . $sectionB->id)
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('static_sections', ['id' => $sectionB->id]);
    }

    public function test_updating_foreign_section_via_page_route_returns_404(): void
    {
        $pageA = $this->createStaticPage('about-us');
        $pageB = $this->createStaticPage('privacy-policy');
        $sectionB = $this->createStaticSection($pageB);
        $this->actAsAdmin();

        $this->putJson(self::PREFIX . '/static-pages/' . $pageA->slug . '/sections/' . $sectionB->id, [
            'title' => ['en' => 'Hacked'],
        ])->assertNotFound();

        $this->assertSame('Our Story', $sectionB->refresh()->getTranslation('title', 'en'));
    }

    public function test_reorder_with_foreign_section_id_returns_404(): void
    {
        $pageA = $this->createStaticPage('about-us');
        $pageB = $this->createStaticPage('privacy-policy');
        $own = $this->createStaticSection($pageA);
        $foreign = $this->createStaticSection($pageB);
        $this->actAsAdmin();

        $this->postJson(self::PREFIX . '/static-pages/' . $pageA->slug . '/sections/reorder', [
            'sections' => [$foreign->id, $own->id],
        ])->assertNotFound();

        $this->assertSame(1, $own->refresh()->order);
        $this->assertSame(1, $foreign->refresh()->order);
    }
}