<?php

namespace Tests\Feature\StaticPages;

use App\Enums\FrontendResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

class StaticPageCacheTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';
    private const PUBLIC_INDEX = '/api/v1/general/static-pages';
    private const TAG = 'static_pages';

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
            'email' => 'admin.cache@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000207',
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

    private function indexCacheKey(): string
    {
        return md5(url(self::PUBLIC_INDEX));
    }

    public function test_public_index_populates_cache_with_tag(): void
    {
        $this->createStaticPage();

        $this->getJson(self::PUBLIC_INDEX)->assertOk();

        $this->assertTrue(
            Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey())
        );
        $this->assertSame(self::TAG, FrontendResource::STATIC_PAGES->value);
    }

    public function test_public_show_populates_cache_with_tag(): void
    {
        $this->createStaticPage();

        $this->getJson(self::PUBLIC_INDEX . '/about-us')->assertOk();

        $key = md5(url(self::PUBLIC_INDEX . '/about-us'));
        $this->assertTrue(
            Cache::tags([FrontendResource::STATIC_PAGES->value])->has($key)
        );
    }

    public function test_second_index_call_served_from_cache(): void
    {
        $this->createStaticPage();

        $this->getJson(self::PUBLIC_INDEX)->assertOk();
        $this->getJson(self::PUBLIC_INDEX)->assertOk();

        $this->assertTrue(
            Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey())
        );
    }

    public function test_cache_stores_models_not_rendered_resources(): void
    {
        $this->createStaticPage();

        $this->getJson(self::PUBLIC_INDEX)->assertOk();

        $cached = Cache::tags([FrontendResource::STATIC_PAGES->value])->get($this->indexCacheKey());
        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertInstanceOf(StaticPage::class, $cached->first());
    }

    public function test_page_update_invalidates_cache(): void
    {
        $page = $this->createStaticPage();
        $this->getJson(self::PUBLIC_INDEX)->assertOk();
        $this->assertTrue(Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey()));

        $this->actAsAdmin();
        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, ['title' => ['en' => 'Changed']])->assertOk();

        $this->assertFalse(
            Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey()),
            'Updating a static page must invalidate the static_pages cache'
        );
    }

    public function test_section_creation_invalidates_cache(): void
    {
        $page = $this->createStaticPage();
        $this->getJson(self::PUBLIC_INDEX)->assertOk();
        $this->assertTrue(Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey()));

        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
            'title' => ['en' => 'New', 'ar' => 'جديد'],
            'content' => ['en' => ['heading' => 'X'], 'ar' => ['heading' => 'أ']],
        ])->assertOk();

        $this->assertFalse(
            Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey()),
            'Creating a static section must invalidate the static_pages cache'
        );
    }

    public function test_section_update_invalidates_cache(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->getJson(self::PUBLIC_INDEX)->assertOk();
        $this->assertTrue(Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey()));

        $this->actAsAdmin();
        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, [
            'title' => ['en' => 'Changed'],
        ])->assertOk();

        $this->assertFalse(
            Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey()),
            'Updating a static section must invalidate the static_pages cache'
        );
    }

    public function test_section_delete_invalidates_cache(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->getJson(self::PUBLIC_INDEX)->assertOk();
        $this->assertTrue(Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey()));

        $this->actAsAdmin();
        $this->deleteJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id)->assertOk();

        $this->assertFalse(
            Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey()),
            'Deleting a static section must invalidate the static_pages cache'
        );
    }

    public function test_section_reorder_invalidates_cache(): void
    {
        $page = $this->createStaticPage();
        $first = $this->createStaticSection($page, ['title' => ['en' => 'First']]);
        $second = $this->createStaticSection($page, ['title' => ['en' => 'Second']]);
        $this->getJson(self::PUBLIC_INDEX)->assertOk();
        $this->assertTrue(Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey()));

        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/reorder', [
            'sections' => [$second->id, $first->id],
        ])->assertOk();

        $this->assertFalse(
            Cache::tags([FrontendResource::STATIC_PAGES->value])->has($this->indexCacheKey()),
            'Reordering static sections must invalidate the static_pages cache'
        );
    }

    public function test_mutated_data_is_not_served_from_stale_cache(): void
    {
        $page = $this->createStaticPage();
        $this->createStaticSection($page, ['title' => ['en' => 'Original']]);

        $first = $this->getJson(self::PUBLIC_INDEX)->assertOk();
        $this->assertSame('Original', $first->json('data.0.sections.0.title'));

        $this->actAsAdmin();
        $section = StaticSection::first();
        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, [
            'title' => ['en' => 'Mutated'],
        ])->assertOk();

        $second = $this->getJson(self::PUBLIC_INDEX)->assertOk();
        $this->assertSame('Mutated', $second->json('data.0.sections.0.title'));
    }
}