<?php

namespace Tests\Feature\Pages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ContentPagePublicContractTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';
    private const PUBLIC_INDEX = '/api/v1/general/content-pages';
    private const PUBLIC_SHOW = '/api/v1/general/content-pages/';

    private User $adminUser;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

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

        $this->adminUser = User::create([
            'name' => 'Admin',
            'email' => 'admin.public.contract@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000998',
            'is_active' => true,
        ]);
        $this->adminUser->givePermissionTo($permissions);
        $this->adminUser->assignRole(RoleEnum::EDITOR);

        Cache::flush();
    }

    private function createPage(array $overrides = []): ContentPage
    {
        $defaults = [
            'title' => ['en' => 'Home', 'ar' => 'الرئيسية'],
            'slug' => 'home',
            'is_active' => true,
        ];
        return ContentPage::create(array_merge($defaults, $overrides));
    }

    // =========================================================================
    // Store validation (regression: missing title must be 422, not 500)
    // =========================================================================

    public function test_create_content_page_without_title_returns_422(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/content-pages', []);

        $response->assertStatus(422);
        $this->assertDatabaseCount('content_pages', 0);
    }

    public function test_create_content_page_without_english_title_returns_422(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/content-pages', [
            'title' => ['ar' => 'الصفحة الرئيسية'],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('content_pages', 0);
    }

    public function test_create_content_page_with_valid_title_still_works(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/content-pages', [
            'title' => ['en' => 'New Page', 'ar' => 'صفحة جديدة'],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('content_pages', ['slug' => 'new-page']);
    }

    // =========================================================================
    // Public visibility (regression: inactive pages must not be public)
    // =========================================================================

    public function test_inactive_page_is_hidden_from_public_show(): void
    {
        $this->createPage(['slug' => 'hidden', 'is_active' => false]);

        $this->getJson(self::PUBLIC_SHOW . 'hidden')->assertStatus(404);
    }

    public function test_active_page_is_visible_publicly(): void
    {
        $this->createPage(['slug' => 'visible', 'is_active' => true]);

        $response = $this->getJson(self::PUBLIC_SHOW . 'visible');

        $response->assertOk();
        $response->assertJsonPath('data.slug', 'visible');
    }

    public function test_inactive_page_is_excluded_from_public_index(): void
    {
        $this->createPage(['title' => ['en' => 'Hidden Page', 'ar' => 'صفحة مخفية'], 'slug' => 'hidden', 'is_active' => false]);
        $this->createPage(['title' => ['en' => 'Visible Page', 'ar' => 'صفحة ظاهرة'], 'slug' => 'visible', 'is_active' => true]);

        $response = $this->getJson(self::PUBLIC_INDEX);

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertNotContains('hidden', $slugs);
        $this->assertContains('visible', $slugs);
    }

    public function test_inactive_page_hides_its_inactive_sections_publicly(): void
    {
        $page = $this->createPage(['slug' => 'home', 'is_active' => true]);

        $active = Section::create([
            'type' => 'banner',
            'title' => ['en' => 'Active Section', 'ar' => 'قسم نشط'],
            'endpoint' => 'general/banner',
            'order' => 1,
            'is_active' => true,
            'title_visible' => true,
        ]);
        $inactive = Section::create([
            'type' => 'banner',
            'title' => ['en' => 'Inactive Section', 'ar' => 'قسم غير نشط'],
            'endpoint' => 'general/banner',
            'order' => 2,
            'is_active' => false,
            'title_visible' => true,
        ]);
        $page->sections()->saveMany([$active, $inactive]);

        $response = $this->getJson(self::PUBLIC_SHOW . 'home');

        $response->assertOk();
        $sections = $response->json('data.sections') ?? [];
        $this->assertCount(1, $sections);
        $this->assertSame('Active Section', $sections[0]['title']);
    }
}
