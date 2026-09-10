<?php

namespace Tests\Feature\StaticPages;

use Database\Seeders\StaticPageSeeder;
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

class StaticPageFixedInvariantTest extends TestCase
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
            'email' => 'admin.fixed@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000206',
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

    public function test_no_store_endpoint_for_static_pages(): void
    {
        $this->actAsAdmin();

        $this->postJson(self::PREFIX . '/static-pages', [
            'slug' => 'custom',
            'title' => ['en' => 'Custom'],
        ])->assertStatus(405);
    }

    public function test_no_delete_endpoint_for_static_pages(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug)->assertStatus(405);
    }

    public function test_slug_cannot_be_changed_via_update(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, [
            'title' => ['en' => 'Renamed'],
            'slug' => 'hacked',
        ])->assertOk();

        $this->assertSame('about-us', $page->refresh()->slug);
    }

    public function test_seeder_is_idempotent(): void
    {
        (new StaticPageSeeder())->run();
        (new StaticPageSeeder())->run();

        $this->assertSame(3, StaticPage::count());
        $this->assertSame(3, StaticPage::distinct('slug')->count());
    }

    public function test_seeder_creates_three_fixed_pages(): void
    {
        (new StaticPageSeeder())->run();

        $this->assertSame(
            ['about-us', 'terms-and-conditions', 'privacy-policy'],
            StaticPage::orderBy('id')->pluck('slug')->all()
        );
    }

    public function test_seeder_does_not_overwrite_edited_title(): void
    {
        $page = $this->createStaticPage([
            'title' => ['en' => 'Custom About', 'ar' => 'مخصص'],
        ]);
        $this->createStaticPage(['slug' => 'terms-and-conditions']);
        $this->createStaticPage(['slug' => 'privacy-policy']);

        (new StaticPageSeeder())->run();

        $this->assertSame('Custom About', $page->refresh()->getTranslation('title', 'en'));
    }

    public function test_seeder_does_not_overwrite_deactivated_page(): void
    {
        $page = $this->createStaticPage(['is_active' => false]);
        $this->createStaticPage(['slug' => 'terms-and-conditions']);
        $this->createStaticPage(['slug' => 'privacy-policy']);

        (new StaticPageSeeder())->run();

        $this->assertFalse($page->refresh()->is_active);
    }

    public function test_seeder_does_not_create_sections(): void
    {
        (new StaticPageSeeder())->run();

        $this->assertSame(0, StaticSection::count());
    }

    public function test_seeder_does_not_delete_admin_sections(): void
    {
        $page = $this->createStaticPage();
        $page->staticSections()->create([
            'title' => ['en' => 'Admin Section', 'ar' => 'قسم'],
            'content' => ['en' => ['heading' => 'X']],
        ]);
        $this->createStaticPage(['slug' => 'terms-and-conditions']);
        $this->createStaticPage(['slug' => 'privacy-policy']);

        (new StaticPageSeeder())->run();

        $this->assertSame(1, StaticSection::count());
        $this->assertSame('Admin Section', StaticSection::first()->getTranslation('title', 'en'));
    }

    public function test_public_unknown_slug_returns_404(): void
    {
        $this->createStaticPage();

        $this->getJson('/api/v1/general/static-pages/does-not-exist')->assertNotFound();
    }

    public function test_public_index_only_returns_active_pages(): void
    {
        $this->createStaticPage(['slug' => 'terms-and-conditions']);
        $this->createStaticPage(['slug' => 'privacy-policy']);
        $this->createStaticPage(['slug' => 'hidden', 'is_active' => false]);

        $response = $this->getJson('/api/v1/general/static-pages')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $slugs = array_column($response->json('data'), 'slug');
        $this->assertNotContains('hidden', $slugs);
    }

    public function test_public_show_rejects_inactive_page(): void
    {
        $this->createStaticPage(['is_active' => false]);

        $this->getJson('/api/v1/general/static-pages/about-us')->assertNotFound();
    }
}