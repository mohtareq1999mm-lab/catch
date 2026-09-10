<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ContentPageSectionTypeApiTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    private User $adminUser;
    private User $viewUser;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

        $this->createPermissions();

        $this->viewUser = User::create([
            'name' => 'View User',
            'email' => 'view.cp@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000011',
            'is_active' => true,
        ]);
        $this->viewUser->givePermissionTo([
            PermissionEnum::VIEW_CONTENT_PAGES,
            PermissionEnum::VIEW_SECTIONS,
            PermissionEnum::VIEW_SECTION_TYPES,
        ]);
        $this->assignEditorRole($this->viewUser);

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin.cp@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000012',
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
        $this->assignEditorRole($this->adminUser);
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

    private function assignEditorRole(User $user): void
    {
        $user->assignRole(RoleEnum::EDITOR);
    }

    private function createSectionType(string $type = 'banner'): SectionType
    {
        return SectionType::create(['type' => $type]);
    }

    private function createContentPage(array $overrides = []): ContentPage
    {
        $defaults = [
            'title' => ['en' => 'Test Page', 'ar' => 'صفحة اختبار'],
            'slug' => 'test-page',
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
            'order' => 1,
            'is_active' => true,
            'title_visible' => true,
        ];
        return Section::create(array_merge($defaults, $overrides));
    }

    // =========================================================================
    // Authentication Tests
    // =========================================================================

    public function test_guest_gets_401_for_list_content_pages()
    {
        $this->getJson(self::PREFIX . '/content-pages')->assertStatus(401);
    }

    public function test_guest_gets_401_for_show_content_page()
    {
        $page = $this->createContentPage();
        $this->getJson(self::PREFIX . '/content-pages/' . $page->id)->assertStatus(401);
    }

    public function test_guest_gets_401_for_create_content_page()
    {
        $this->postJson(self::PREFIX . '/content-pages', [
            'title' => ['en' => 'New Page'],
        ])->assertStatus(401);
    }

    public function test_guest_gets_401_for_update_content_page()
    {
        $page = $this->createContentPage();
        $this->putJson(self::PREFIX . '/content-pages/' . $page->id, [
            'title' => ['en' => 'Updated'],
        ])->assertStatus(401);
    }

    public function test_guest_gets_401_for_delete_content_page()
    {
        $page = $this->createContentPage();
        $this->deleteJson(self::PREFIX . '/content-pages/' . $page->id)->assertStatus(401);
    }

    public function test_guest_gets_401_for_toggle_active()
    {
        $page = $this->createContentPage();
        $this->patchJson(self::PREFIX . '/content-pages/' . $page->id . '/toggle-active')->assertStatus(401);
    }

    public function test_guest_gets_401_for_attach_sections()
    {
        $page = $this->createContentPage();
        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', [
            'sections' => [],
        ])->assertStatus(401);
    }

    public function test_guest_gets_401_for_list_sections()
    {
        $this->getJson(self::PREFIX . '/sections')->assertStatus(401);
    }

    public function test_guest_gets_401_for_show_section()
    {
        $section = $this->createSection();
        $this->getJson(self::PREFIX . '/sections/' . $section->id)->assertStatus(401);
    }

    public function test_guest_gets_401_for_create_section()
    {
        $this->postJson(self::PREFIX . '/sections', [
            'type' => 'banner',
            'title' => ['en' => 'Test'],
        ])->assertStatus(401);
    }

    public function test_guest_gets_401_for_update_section()
    {
        $section = $this->createSection();
        $this->putJson(self::PREFIX . '/sections/' . $section->id, [
            'title' => ['en' => 'Updated'],
        ])->assertStatus(401);
    }

    public function test_guest_gets_401_for_delete_section()
    {
        $section = $this->createSection();
        $this->deleteJson(self::PREFIX . '/sections/' . $section->id)->assertStatus(401);
    }

    public function test_guest_gets_401_for_reorder_sections()
    {
        $this->postJson(self::PREFIX . '/sections/reorder', [
            'sections' => [1, 2],
        ])->assertStatus(401);
    }

    public function test_guest_gets_401_for_toggle_section_status()
    {
        $section = $this->createSection();
        $this->patchJson(self::PREFIX . '/sections/' . $section->id . '/toggle-active')->assertStatus(401);
    }

    public function test_guest_gets_401_for_list_section_types()
    {
        $this->getJson(self::PREFIX . '/section-types')->assertStatus(401);
    }

    public function test_guest_gets_401_for_create_section_type()
    {
        $this->postJson(self::PREFIX . '/section-types', [
            'type' => 'hero',
        ])->assertStatus(401);
    }

    public function test_guest_gets_401_for_update_section_type()
    {
        $st = $this->createSectionType();
        $this->putJson(self::PREFIX . '/section-types/' . $st->type, [
            'type' => 'hero',
        ])->assertStatus(401);
    }

    public function test_guest_gets_401_for_delete_section_type()
    {
        $st = $this->createSectionType();
        $this->deleteJson(self::PREFIX . '/section-types/' . $st->type)->assertStatus(401);
    }

    public function test_guest_gets_401_for_update_settings()
    {
        $st = $this->createSectionType();
        $this->postJson(self::PREFIX . '/section-types/' . $st->type . '/settings', [
            'front' => ['key' => 'val'],
        ])->assertStatus(401);
    }

    // =========================================================================
    // Authorization Tests
    // =========================================================================

    public function test_user_without_view_content_pages_gets_forbidden()
    {
        $user = User::create([
            'name' => 'No Perm User',
            'email' => 'noperm.cp@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000013',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson(self::PREFIX . '/content-pages')->assertStatus(403);
    }

    public function test_user_without_create_content_pages_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $this->postJson(self::PREFIX . '/content-pages', [
            'title' => ['en' => 'New Page'],
        ])->assertStatus(403);
    }

    public function test_user_without_update_content_pages_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $page = $this->createContentPage();
        $this->putJson(self::PREFIX . '/content-pages/' . $page->id, [
            'title' => ['en' => 'Updated'],
        ])->assertStatus(403);
    }

    public function test_user_without_delete_content_pages_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $page = $this->createContentPage();
        $this->deleteJson(self::PREFIX . '/content-pages/' . $page->id)->assertStatus(403);
    }

    public function test_user_without_update_content_pages_gets_forbidden_for_toggle_active()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $page = $this->createContentPage();
        $this->patchJson(self::PREFIX . '/content-pages/' . $page->id . '/toggle-active')->assertStatus(403);
    }

    public function test_user_without_update_content_pages_gets_forbidden_for_attach_sections()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $page = $this->createContentPage();
        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', [
            'sections' => [],
        ])->assertStatus(403);
    }

    public function test_user_without_view_sections_gets_forbidden()
    {
        $user = User::create([
            'name' => 'No Perm User',
            'email' => 'noperm.sec@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000014',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson(self::PREFIX . '/sections')->assertStatus(403);
    }

    public function test_user_without_create_sections_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $this->postJson(self::PREFIX . '/sections', [
            'type' => 'banner',
            'title' => ['en' => 'Test'],
        ])->assertStatus(403);
    }

    public function test_user_without_update_sections_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $section = $this->createSection();
        $this->putJson(self::PREFIX . '/sections/' . $section->id, [
            'title' => ['en' => 'Updated'],
        ])->assertStatus(403);
    }

    public function test_user_without_update_sections_gets_forbidden_for_reorder()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $this->postJson(self::PREFIX . '/sections/reorder', [
            'sections' => [1, 2],
        ])->assertStatus(403);
    }

    public function test_user_without_update_sections_gets_forbidden_for_toggle_status()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $section = $this->createSection();
        $this->patchJson(self::PREFIX . '/sections/' . $section->id . '/toggle-active')->assertStatus(403);
    }

    public function test_user_without_delete_sections_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $section = $this->createSection();
        $this->deleteJson(self::PREFIX . '/sections/' . $section->id)->assertStatus(403);
    }

    public function test_user_without_view_section_types_gets_forbidden()
    {
        $user = User::create([
            'name' => 'No Perm User',
            'email' => 'noperm.st@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000015',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson(self::PREFIX . '/section-types')->assertStatus(403);
    }

    public function test_user_without_create_section_types_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $this->postJson(self::PREFIX . '/section-types', [
            'type' => 'hero',
        ])->assertStatus(403);
    }

    public function test_user_without_update_section_types_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $st = $this->createSectionType();
        $this->putJson(self::PREFIX . '/section-types/' . $st->type, [
            'type' => 'hero',
        ])->assertStatus(403);
    }

    public function test_user_without_update_section_types_gets_forbidden_for_settings()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $st = $this->createSectionType();
        $this->postJson(self::PREFIX . '/section-types/' . $st->type . '/settings', [
            'front' => ['key' => 'val'],
        ])->assertStatus(403);
    }

    public function test_user_without_delete_section_types_gets_forbidden()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $st = $this->createSectionType();
        $this->deleteJson(self::PREFIX . '/section-types/' . $st->type)->assertStatus(403);
    }

    // =========================================================================
    // Content Pages — CRUD
    // =========================================================================

    public function test_authenticated_user_can_list_content_pages()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $this->createContentPage(['title' => ['en' => 'Page One'], 'slug' => 'page-one']);
        $this->createContentPage(['title' => ['en' => 'Page Two'], 'slug' => 'page-two']);

        $response = $this->getJson(self::PREFIX . '/content-pages');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data',
        ]);
    }

    public function test_list_content_pages_returns_empty_when_none_exist()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/content-pages');

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_authenticated_user_can_show_content_page()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $page = $this->createContentPage();

        $response = $this->getJson(self::PREFIX . '/content-pages/' . $page->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $page->id);
        $response->assertJsonPath('data.slug', 'test-page');
    }

    public function test_show_content_page_returns_404_for_nonexistent()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $this->getJson(self::PREFIX . '/content-pages/9999')->assertStatus(404);
    }

    public function test_admin_can_create_content_page()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/content-pages', [
            'title' => ['en' => 'New Page', 'ar' => 'صفحة جديدة'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('content_pages', ['slug' => 'new-page']);
    }

    public function test_create_content_page_returns_422_for_invalid_title()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/content-pages', [
            'title' => 'not-an-array',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_update_content_page()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $page = $this->createContentPage();

        $response = $this->putJson(self::PREFIX . '/content-pages/' . $page->id, [
            'is_active' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $page->refresh();
        $this->assertFalse($page->is_active);
    }

    public function test_update_content_page_returns_404_for_nonexistent()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->putJson(self::PREFIX . '/content-pages/9999', [
            'title' => ['en' => 'Ghost'],
        ])->assertStatus(404);
    }

    public function test_admin_can_toggle_active()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $page = $this->createContentPage(['is_active' => true]);

        $response = $this->patchJson(self::PREFIX . '/content-pages/' . $page->id . '/toggle-active');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.is_active', false);
    }

    public function test_admin_can_delete_content_page()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $page = $this->createContentPage();

        $response = $this->deleteJson(self::PREFIX . '/content-pages/' . $page->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('content_pages', ['id' => $page->id]);
    }

    public function test_delete_content_page_returns_404_for_nonexistent()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->deleteJson(self::PREFIX . '/content-pages/9999')->assertStatus(404);
    }

    // =========================================================================
    // Content Pages — Attach Sections
    // =========================================================================

    public function test_admin_can_attach_sections_to_content_page()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $page = $this->createContentPage();
        $sectionA = $this->createSection(['order' => 1]);
        $sectionB = $this->createSection(['order' => 2]);

        $response = $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', [
            'sections' => [$sectionA->id, $sectionB->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('sections', ['id' => $sectionA->id, 'content_page_id' => $page->id]);
        $this->assertDatabaseHas('sections', ['id' => $sectionB->id, 'content_page_id' => $page->id]);
    }

    public function test_attach_sections_with_empty_array_detaches_all()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $page = $this->createContentPage();
        $section = $this->createSection(['content_page_id' => $page->id]);

        $response = $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', [
            'sections' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $section->refresh();
        $this->assertNull($section->content_page_id);
    }

    public function test_attach_sections_returns_422_for_invalid_section_ids()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $page = $this->createContentPage();

        $response = $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', [
            'sections' => [9999],
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // Sections — CRUD
    // =========================================================================

    public function test_authenticated_user_can_list_sections()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $this->createSection(['order' => 1]);
        $this->createSection(['order' => 2]);

        $response = $this->getJson(self::PREFIX . '/sections');

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_list_sections_returns_empty_when_none_exist()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $response = $this->getJson(self::PREFIX . '/sections');

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_authenticated_user_can_show_section()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $section = $this->createSection();

        $response = $this->getJson(self::PREFIX . '/sections/' . $section->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $section->id);
    }

    public function test_show_section_returns_404_for_nonexistent()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $this->getJson(self::PREFIX . '/sections/9999')->assertStatus(404);
    }

    public function test_admin_can_create_section()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->createSectionType('banner');

        $response = $this->postJson(self::PREFIX . '/sections', [
            'type' => 'banner',
            'endpoint' => 'general/banner',
            'title' => ['en' => 'Hero Banner', 'ar' => 'بانر رئيسي'],
            'is_active' => true,
            'title_visible' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('sections', ['type' => 'banner']);
    }

    public function test_create_section_returns_422_for_missing_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/sections', [
            'title' => ['en' => 'No Type'],
        ]);

        $response->assertStatus(422);
    }

    public function test_create_section_returns_422_for_invalid_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/sections', [
            'type' => 'nonexistent-type',
            'title' => ['en' => 'Bad Type'],
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_update_section()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $section = $this->createSection();

        $response = $this->putJson(self::PREFIX . '/sections/' . $section->id, [
            'is_active' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $section->refresh();
        $this->assertFalse($section->is_active);
    }

    public function test_update_section_returns_404_for_nonexistent()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->putJson(self::PREFIX . '/sections/9999', [
            'title' => ['en' => 'Ghost'],
        ])->assertStatus(404);
    }

    public function test_admin_can_toggle_section_status()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $section = $this->createSection(['is_active' => true]);

        $response = $this->patchJson(self::PREFIX . '/sections/' . $section->id . '/toggle-active');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.is_active', false);
    }

    public function test_admin_can_delete_section()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $section = $this->createSection();

        $response = $this->deleteJson(self::PREFIX . '/sections/' . $section->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
    }

    public function test_delete_section_returns_404_for_nonexistent()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->deleteJson(self::PREFIX . '/sections/9999')->assertStatus(404);
    }

    // =========================================================================
    // Sections — Reorder
    // =========================================================================

    public function test_admin_can_reorder_sections()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $sectionA = $this->createSection(['order' => 1]);
        $sectionB = $this->createSection(['order' => 2]);

        $response = $this->postJson(self::PREFIX . '/sections/reorder', [
            'sections' => [$sectionB->id, $sectionA->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_reorder_returns_422_for_missing_sections()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/sections/reorder', []);

        $response->assertStatus(422);
    }

    public function test_reorder_returns_422_for_invalid_section_ids()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/sections/reorder', [
            'sections' => [9999],
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // Section Types — CRUD
    // =========================================================================

    public function test_authenticated_user_can_list_section_types()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $this->createSectionType('banner');
        $this->createSectionType('hero');

        $response = $this->getJson(self::PREFIX . '/section-types');

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_admin_can_create_section_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/section-types', [
            'type' => 'hero',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('section_types', ['type' => 'hero']);
    }

    public function test_create_section_type_returns_422_for_empty_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->postJson(self::PREFIX . '/section-types', ['type' => ''])->assertStatus(422);
    }

    public function test_create_section_type_returns_422_for_duplicate_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->createSectionType('hero');

        $response = $this->postJson(self::PREFIX . '/section-types', [
            'type' => 'hero',
        ]);

        $response->assertStatus(422);
    }

    public function test_authenticated_user_can_show_section_type()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $st = $this->createSectionType('banner');

        $response = $this->getJson(self::PREFIX . '/section-types/' . $st->type);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_admin_can_update_section_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $st = $this->createSectionType('banner');

        $response = $this->putJson(self::PREFIX . '/section-types/' . $st->type, [
            'type' => 'hero',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('section_types', ['type' => 'hero']);
        $this->assertDatabaseMissing('section_types', ['type' => 'banner']);
    }

    public function test_admin_can_delete_section_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $st = $this->createSectionType('banner');

        $response = $this->deleteJson(self::PREFIX . '/section-types/' . $st->type);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('section_types', ['type' => 'banner']);
    }

    // =========================================================================
    // Section Types — Settings
    // =========================================================================

    public function test_authenticated_user_can_get_type_settings()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $st = $this->createSectionType('banner');
        SectionTypeSetting::create([
            'section_type_id' => $st->id,
            'setting_key' => 'front',
            'value' => ['title' => 'Welcome'],
        ]);

        $response = $this->getJson(self::PREFIX . '/section-types/' . $st->type . '/settings');

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_admin_can_update_type_settings()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $st = $this->createSectionType('banner');

        $response = $this->postJson(self::PREFIX . '/section-types/' . $st->type . '/settings', [
            'front' => ['title' => 'Welcome'],
            'back' => ['slug' => 'welcome-slug'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('section_type_settings', [
            'section_type_id' => $st->id,
            'setting_key' => 'front',
        ]);
        $this->assertDatabaseHas('section_type_settings', [
            'section_type_id' => $st->id,
            'setting_key' => 'back',
        ]);
    }

    public function test_update_settings_returns_404_for_nonexistent_type()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/section-types/nonexistent/settings', [
            'front' => ['key' => 'val'],
        ]);

        $response->assertStatus(404);
    }

    // =========================================================================
    // Translation Flow
    // =========================================================================

    public function test_content_page_title_is_translatable()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/content-pages', [
            'title' => ['en' => 'English Page', 'ar' => 'صفحة عربية'],
        ]);
        $response->assertStatus(201);

        $response = $this->withHeaders(['lang' => 'ar'])->getJson(self::PREFIX . '/content-pages/' . $response->json('data.id'));
        $response->assertJsonPath('data.title', 'صفحة عربية');

        $response = $this->withHeaders(['lang' => 'en'])->getJson(self::PREFIX . '/content-pages/' . $response->json('data.id'));
        $response->assertJsonPath('data.title', 'English Page');
    }

    public function test_section_title_is_translatable()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->createSectionType('banner');
        $response = $this->postJson(self::PREFIX . '/sections', [
            'type' => 'banner',
            'endpoint' => 'general/banner',
            'title' => ['en' => 'English Title', 'ar' => 'عنوان عربي'],
        ]);
        $response->assertOk();

        $response = $this->withHeaders(['lang' => 'ar'])->getJson(self::PREFIX . '/sections/' . $response->json('data.id'));
        $response->assertJsonPath('data.title', 'عنوان عربي');

        $response = $this->withHeaders(['lang' => 'en'])->getJson(self::PREFIX . '/sections/' . $response->json('data.id'));
        $response->assertJsonPath('data.title', 'English Title');
    }

    // =========================================================================
    // Response Structure
    // =========================================================================

    public function test_content_page_resource_structure()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $page = $this->createContentPage();

        $response = $this->getJson(self::PREFIX . '/content-pages/' . $page->id);

        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'title', 'slug', 'is_active', 'sections',
            ],
        ]);
    }

    public function test_section_resource_structure()
    {
        Sanctum::actingAs($this->viewUser, ['*']);

        $section = $this->createSection();

        $response = $this->getJson(self::PREFIX . '/sections/' . $section->id);

        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'type', 'title', 'is_active', 'endpoint', 'order', 'setting',
            ],
        ]);
    }

    // =========================================================================
    // Mass Assignment Protection
    // =========================================================================

    public function test_content_page_mass_assignment_protection()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/content-pages', [
            'title' => ['en' => 'Mass Assign'],
            'id' => 99999,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseMissing('content_pages', ['id' => 99999]);
    }

    public function test_section_type_mass_assignment_protection()
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $response = $this->postJson(self::PREFIX . '/section-types', [
            'type' => 'test-type',
            'id' => 99999,
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('section_types', ['id' => 99999]);
    }
}
