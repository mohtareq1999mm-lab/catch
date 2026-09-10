<?php

namespace Tests\Feature\StaticPages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\StaticPage;
use Marvel\Database\Models\StaticSection;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaticPageValidationTest extends TestCase
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
            'email' => 'admin.validation@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000205',
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

    private function assertValidationFailure(TestResponse $response, string ...$keys): void
    {
        $response->assertStatus(422);
        $json = $response->json();

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $json, "Expected a validation error for key '{$key}'");
        }
    }

    public function test_store_section_requires_title(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
                'content' => ['en' => ['heading' => 'X']],
            ]),
            'title'
        );
    }

    public function test_store_section_requires_title_en(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
                'title' => ['ar' => 'قصتنا'],
                'content' => ['en' => ['heading' => 'X']],
            ]),
            'title.en'
        );
    }

    public function test_store_section_requires_content(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
                'title' => ['en' => 'Our Story'],
            ]),
            'content'
        );
    }

    public function test_store_section_rejects_top_level_list_content(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
                'title' => ['en' => 'Our Story'],
                'content' => ['Welcome', 'Hello'],
            ]),
            'content'
        );

        $this->assertSame(0, StaticSection::count());
    }

    public function test_store_section_rejects_non_array_content_en(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
                'title' => ['en' => 'Our Story'],
                'content' => ['en' => 'not-an-array', 'ar' => ['heading' => 'X']],
            ]),
            'content.en'
        );
    }

    public function test_store_section_title_locale_must_be_string(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
                'title' => ['en' => 12345],
                'content' => ['en' => ['heading' => 'X']],
            ]),
            'title.en'
        );
    }

    public function test_store_section_title_locale_exceeding_max_length_rejected(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
                'title' => ['en' => str_repeat('a', 256)],
                'content' => ['en' => ['heading' => 'X']],
            ]),
            'title.en'
        );
    }

    public function test_update_page_title_must_be_array(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, [
                'title' => 'Plain String',
            ]),
            'title'
        );
    }

    public function test_update_page_is_active_must_be_valid_value(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, [
                'is_active' => 'yes',
            ]),
            'is_active'
        );
    }

    public function test_update_section_rejects_list_content(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, [
                'content' => ['A', 'B'],
            ]),
            'content'
        );

        $section->refresh();
        $this->assertEquals(['en' => ['heading' => 'Welcome'], 'ar' => ['heading' => 'مرحبا']], $section->getTranslations('content'));
    }

    public function test_update_section_title_must_be_string(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, [
                'title' => ['en' => ['nested' => 'array']],
            ]),
            'title.en'
        );
    }

    public function test_reorder_requires_sections(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/reorder', []),
            'sections'
        );
    }

    public function test_reorder_rejects_nonexistent_section(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/reorder', [
                'sections' => [999999],
            ]),
            'sections.0'
        );
    }

    public function test_reorder_rejects_duplicate_ids(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/reorder', [
                'sections' => [$section->id, $section->id],
            ]),
            'sections.1'
        );
    }

    public function test_reorder_rejects_non_integer_id(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/reorder', [
                'sections' => ['abc'],
            ]),
            'sections.0'
        );
    }

    public function test_validation_failures_do_not_persist(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->assertValidationFailure(
            $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', [
                'title' => ['en' => 'Bad'],
            ]),
            'content'
        );

        $this->assertSame(0, StaticSection::count());

        $this->assertValidationFailure(
            $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, [
                'title' => 'Not an array',
            ]),
            'title'
        );

        $this->assertSame('About Us', $page->refresh()->getTranslation('title', 'en'));
    }
}