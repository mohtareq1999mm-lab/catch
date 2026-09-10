<?php

namespace Tests\Feature\Pages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;
use App\Enums\FrontendResource;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SectionCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';
    private const PUBLIC_HOME = '/api/v1/general/content-pages/home';

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
            'phone_number' => '01000000121',
            'is_active' => true,
        ]);
        $this->adminUser->givePermissionTo([
            PermissionEnum::VIEW_CONTENT_PAGES,
            PermissionEnum::CREATE_CONTENT_PAGES,
            PermissionEnum::UPDATE_CONTENT_PAGES,
            PermissionEnum::DELETE_CONTENT_PAGES,
            PermissionEnum::VIEW_SECTIONS,
            PermissionEnum::CREATE_SECTIONS,
            PermissionEnum::UPDATE_SECTIONS,
            PermissionEnum::DELETE_SECTIONS,
            PermissionEnum::VIEW_SECTION_TYPES,
            PermissionEnum::CREATE_SECTION_TYPES,
            PermissionEnum::UPDATE_SECTION_TYPES,
            PermissionEnum::DELETE_SECTION_TYPES,
        ]);
        $this->adminUser->assignRole(RoleEnum::EDITOR);

        Cache::flush();
    }

    private function createPermissions(): void
    {
        $permissions = [
            PermissionEnum::VIEW_CONTENT_PAGES,
            PermissionEnum::CREATE_CONTENT_PAGES,
            PermissionEnum::UPDATE_CONTENT_PAGES,
            PermissionEnum::DELETE_CONTENT_PAGES,
            PermissionEnum::VIEW_SECTIONS,
            PermissionEnum::CREATE_SECTIONS,
            PermissionEnum::UPDATE_SECTIONS,
            PermissionEnum::DELETE_SECTIONS,
            PermissionEnum::VIEW_SECTION_TYPES,
            PermissionEnum::CREATE_SECTION_TYPES,
            PermissionEnum::UPDATE_SECTION_TYPES,
            PermissionEnum::DELETE_SECTION_TYPES,
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        Role::firstOrCreate(['name' => RoleEnum::EDITOR, 'guard_name' => 'api', 'display_name' => ['en' => 'Editor', 'ar' => 'محرر']]);
        Role::firstOrCreate(['name' => RoleEnum::SUPER_ADMIN, 'guard_name' => 'api', 'display_name' => ['en' => 'Super Admin', 'ar' => 'مدير النظام']]);
    }

    private function createSectionType(string $type = 'banner'): SectionType
    {
        return SectionType::create(['type' => $type]);
    }

    private function createContentPage(array $overrides = []): ContentPage
    {
        $defaults = [
            'title' => ['en' => 'Home', 'ar' => 'الرئيسية'],
            'slug' => 'home',
            'is_active' => true,
        ];
        return ContentPage::create(array_merge($defaults, $overrides));
    }

    private function createSection(array $overrides = []): Section
    {
        $defaults = [
            'type' => 'banner',
            'title' => ['en' => 'Test Section', 'ar' => 'قسم اختبار'],
            'endpoint' => 'general/banner',
            'is_active' => true,
            'title_visible' => true,
        ];
        return Section::create(array_merge($defaults, $overrides));
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
    }

    private function getPublicHomeSections(): array
    {
        $response = $this->getJson(self::PUBLIC_HOME);
        $response->assertOk();
        return $response->json('data.sections') ?? [];
    }

    public function test_section_created_via_api_invalidates_public_home_cache(): void
    {
        $this->createSectionType('banner');
        $this->createContentPage();

        $this->assertSame([], $this->getPublicHomeSections());
        $key = md5(url(self::PUBLIC_HOME));
        $this->assertTrue(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key));

        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/sections', [
            'type' => 'banner',
            'title' => ['en' => 'Hero Banner', 'ar' => 'بنر رئيسي'],
            'is_active' => 1,
            'title_visible' => 1,
        ])->assertOk();

        $this->assertFalse(
            Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key),
            'Creating a section must flush the content_pages cache'
        );
    }

    public function test_section_updated_via_api_invalidates_public_home_cache(): void
    {
        $this->createSectionType('banner');
        $page = $this->createContentPage();
        $section = $this->createSection(['title' => ['en' => 'Old Title', 'ar' => 'عنوان قديم']]);
        $page->sections()->save($section);

        $sections = $this->getPublicHomeSections();
        $this->assertSame('Old Title', $sections[0]['title']);

        $this->actAsAdmin();
        $this->putJson(self::PREFIX . '/sections/' . $section->id, [
            'title' => ['en' => 'New Title', 'ar' => 'عنوان جديد'],
        ])->assertOk();

        $sections = $this->getPublicHomeSections();
        $this->assertSame('New Title', $sections[0]['title']);
    }

    public function test_section_deleted_via_api_invalidates_public_home_cache(): void
    {
        $this->createSectionType('banner');
        $page = $this->createContentPage();
        $section = $this->createSection();
        $page->sections()->save($section);

        $this->assertCount(1, $this->getPublicHomeSections());

        $this->actAsAdmin();
        $this->deleteJson(self::PREFIX . '/sections/' . $section->id)->assertOk();

        $this->assertSame([], $this->getPublicHomeSections());
    }

    public function test_attach_sections_via_api_invalidates_public_home_cache(): void
    {
        $this->createSectionType('banner');
        $page = $this->createContentPage();
        $first = $this->createSection();
        $second = $this->createSection();

        $this->assertSame([], $this->getPublicHomeSections());

        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', [
            'sections' => [$first->id, $second->id],
        ])->assertOk();

        $sections = $this->getPublicHomeSections();
        $this->assertCount(2, $sections);
    }

    public function test_detach_all_sections_via_api_invalidates_public_home_cache(): void
    {
        $this->createSectionType('banner');
        $page = $this->createContentPage();
        $section = $this->createSection();
        $page->sections()->save($section);

        $this->assertCount(1, $this->getPublicHomeSections());

        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', [
            'sections' => [],
        ])->assertOk();

        $this->assertSame([], $this->getPublicHomeSections());
        $this->assertNull($section->fresh()->content_page_id);
    }

    public function test_update_settings_via_api_invalidates_public_home_cache(): void
    {
        $this->createSectionType('products');
        $page = $this->createContentPage();
        $section = $this->createSection(['type' => 'products']);
        $page->sections()->save($section);

        $sections = $this->getPublicHomeSections();
        $this->assertSame('general/products?', $sections[0]['endpoint']);

        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/section-types/products/settings', [
            'front' => ['columns_count' => 5],
            'back' => ['limit' => 5, 'type' => 'best_product_sales'],
        ])->assertOk();

        $sections = $this->getPublicHomeSections();
        $this->assertStringContainsString('type=best_product_sales', $sections[0]['endpoint']);
        $this->assertSame(5, $sections[0]['setting']['back']['limit']);
    }

    public function test_reorder_via_api_invalidates_public_home_cache_and_order_is_reflected(): void
    {
        $this->createSectionType('banner');
        $page = $this->createContentPage();
        $first = $this->createSection();
        $second = $this->createSection();
        $third = $this->createSection();
        $page->sections()->saveMany([$first, $second, $third]);

        $sections = $this->getPublicHomeSections();
        $this->assertSame([$first->id, $second->id, $third->id], array_column($sections, 'id'));

        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/sections/reorder', [
            'sections' => [$third->id, $first->id, $second->id],
        ])->assertOk();

        $sections = $this->getPublicHomeSections();
        $this->assertSame([$third->id, $first->id, $second->id], array_column($sections, 'id'));
    }

    public function test_section_type_setting_created_via_model_invalidates_public_home_cache(): void
    {
        $type = $this->createSectionType('products');
        $page = $this->createContentPage();
        $section = $this->createSection(['type' => 'products']);
        $page->sections()->save($section);

        $sections = $this->getPublicHomeSections();
        $this->assertSame('general/products?', $sections[0]['endpoint']);

        SectionTypeSetting::create([
            'section_type_id' => $type->id,
            'setting_key' => 'back',
            'value' => ['limit' => 10, 'type' => 'new_arrivals'],
        ]);

        $sections = $this->getPublicHomeSections();
        $this->assertStringContainsString('type=new_arrivals', $sections[0]['endpoint']);
    }

    public function test_section_created_via_model_invalidates_public_home_cache(): void
    {
        $this->createSectionType('banner');
        $this->createContentPage();

        $this->assertSame([], $this->getPublicHomeSections());
        $key = md5(url(self::PUBLIC_HOME));
        $this->assertTrue(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key));

        $this->createSection();

        $this->assertFalse(
            Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key),
            'Creating a section via the model must flush the content_pages cache'
        );
    }

    public function test_section_mutation_does_not_flush_products_cache(): void
    {
        $this->createSectionType('banner');
        $this->createContentPage();

        config(['filesystems.disks.products' => [
            'driver' => 'local',
            'root' => storage_path('app/public/products'),
            'url' => env('APP_URL') . '/public/storage/products',
            'visibility' => 'public',
        ]]);

        \Marvel\Database\Models\Product::create([
            'name' => ['en' => 'Cache Product', 'ar' => 'منتج'],
            'slug' => 'cache-product',
            'price' => 10.0,
            'status' => 'publish',
            'in_stock' => true,
            'stock_quantity' => 5,
            'reserved_quantity' => 0,
            'product_type' => 'simple',
            'has_discount' => false,
            'has_flash_sale' => false,
            'is_fast_shipping_available' => false,
        ]);

        $first = $this->getJson('/api/v1/general/products');
        $first->assertOk();

        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/sections', [
            'type' => 'banner',
            'title' => ['en' => 'New Section', 'ar' => 'قسم جديد'],
            'is_active' => 1,
            'title_visible' => 1,
        ])->assertOk();

        $second = $this->getJson('/api/v1/general/products');
        $second->assertOk();
        $this->assertSame($first->json('data'), $second->json('data'));
    }

    public function test_content_page_updated_via_api_invalidates_public_home_cache(): void
    {
        $page = $this->createContentPage(['title' => ['en' => 'Old Page Title', 'ar' => 'عنوان قديم']]);

        $sections = $this->getPublicHomeSections();
        $this->assertSame([], $sections);

        $this->actAsAdmin();
        $this->putJson(self::PREFIX . '/content-pages/' . $page->id, [
            'title' => ['en' => 'New Page Title', 'ar' => 'عنوان جديد'],
        ])->assertOk();

        $response = $this->getJson(self::PUBLIC_HOME);
        $response->assertOk();
        $this->assertSame('New Page Title', $response->json('data.title'));
    }

    public function test_content_page_deleted_via_api_invalidates_public_home_cache(): void
    {
        $this->createContentPage(['slug' => 'home']);

        $this->getJson(self::PUBLIC_HOME)->assertOk();

        $this->actAsAdmin();
        $page = ContentPage::where('slug', 'home')->firstOrFail();
        $this->deleteJson(self::PREFIX . '/content-pages/' . $page->id)->assertOk();

        $this->getJson(self::PUBLIC_HOME)->assertStatus(404);
    }

    public function test_content_page_toggled_inactive_is_hidden_from_public(): void
    {
        $this->createContentPage(['slug' => 'home']);

        $this->getJson(self::PUBLIC_HOME)->assertOk();

        $this->actAsAdmin();
        $page = ContentPage::where('slug', 'home')->firstOrFail();
        $this->patchJson(self::PREFIX . '/content-pages/' . $page->id . '/toggle-active')->assertOk();

        $this->getJson(self::PUBLIC_HOME)->assertStatus(404);
    }

    public function test_content_page_created_via_api_appears_in_public_index(): void
    {
        $this->actAsAdmin();

        $this->postJson(self::PREFIX . '/content-pages', [
            'title' => ['en' => 'Brand New Page', 'ar' => 'صفحة جديدة'],
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/general/content-pages');
        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('brand-new-page', $slugs);
    }

    public function test_put_reorder_is_no_longer_the_reorder_method(): void
    {
        $this->createSectionType('banner');
        $this->createContentPage();

        $this->actAsAdmin();
        $this->putJson(self::PREFIX . '/sections/reorder', [
            'sections' => [],
        ])->assertStatus(404);
    }
}