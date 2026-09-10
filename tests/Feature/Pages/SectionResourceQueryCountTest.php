<?php

namespace Tests\Feature\Pages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\SectionType;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SectionResourceQueryCountTest extends TestCase
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
            'email' => 'admin.query@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000123',
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

    private function seedHomeWithMultipleSections(): ContentPage
    {
        foreach (['banners', 'products', 'brands'] as $type) {
            SectionType::create(['type' => $type]);
            \Marvel\Database\Models\SectionTypeSetting::create([
                'section_type_id' => SectionType::where('type', $type)->first()->id,
                'setting_key' => 'front',
                'value' => ['autoplay' => true],
            ]);
            \Marvel\Database\Models\SectionTypeSetting::create([
                'section_type_id' => SectionType::where('type', $type)->first()->id,
                'setting_key' => 'back',
                'value' => ['limit' => 10, 'order' => 'desc'],
            ]);
        }

        $page = ContentPage::create([
            'title' => ['en' => 'Home', 'ar' => 'الرئيسية'],
            'slug' => 'home',
            'is_active' => true,
        ]);

        foreach (range(1, 5) as $i) {
            $type = ['banners', 'products', 'brands'][$i % 3];
            $section = Section::create([
                'type' => $type,
                'title' => ['en' => "Section {$i}", 'ar' => 'قسم'],
                'endpoint' => 'general/' . $type,
                'is_active' => true,
                'title_visible' => true,
            ]);
            $page->sections()->save($section);
        }

        return $page;
    }

    private function countSelectQueriesFor(string $table): int
    {
        $queries = DB::getQueryLog();

        return collect($queries)
            ->filter(fn(array $q) => preg_match('/^select/i', $q['query']) === 1
                && preg_match('/\bfrom\s+["`]?' . preg_quote($table, '/') . '["`]?/i', $q['query']) === 1)
            ->count();
    }

    public function test_public_home_eager_loads_section_types_and_settings_once(): void
    {
        $this->seedHomeWithMultipleSections();

        DB::enableQueryLog();
        $response = $this->getJson(self::PUBLIC_HOME);
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertCount(5, $response->json('data.sections'));

        $this->assertSame(1, $this->countSelectQueriesFor('section_types'), 'Section types must be eager loaded, not queried per section');
        $this->assertSame(1, $this->countSelectQueriesFor('section_type_settings'), 'Section type settings must be eager loaded, not queried per section');
    }

    public function test_public_home_serves_populated_settings_from_eager_loaded_relations(): void
    {
        $this->seedHomeWithMultipleSections();

        $response = $this->getJson(self::PUBLIC_HOME);
        $response->assertOk();

        $sections = $response->json('data.sections');
        $first = $sections[0];

        $this->assertArrayHasKey('setting', $first);
        $this->assertArrayHasKey('front', $first['setting']);
        $this->assertArrayHasKey('back', $first['setting']);
        $this->assertSame(10, $first['setting']['back']['limit']);
        $this->assertSame(true, $first['setting']['front']['autoplay']);
    }

    public function test_public_home_cached_payload_matches_fresh_payload(): void
    {
        $this->seedHomeWithMultipleSections();

        $first = $this->getJson(self::PUBLIC_HOME);
        $first->assertOk();

        $second = $this->getJson(self::PUBLIC_HOME);
        $second->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
    }

    public function test_admin_sections_index_eager_loads_section_types_and_settings_once(): void
    {
        $this->seedHomeWithMultipleSections();

        Sanctum::actingAs($this->adminUser, ['*']);

        DB::enableQueryLog();
        $response = $this->getJson(self::PREFIX . '/sections');
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertCount(5, $response->json('data'));

        $this->assertSame(1, $this->countSelectQueriesFor('section_types'), 'Admin section list must eager load section types once');
        $this->assertSame(1, $this->countSelectQueriesFor('section_type_settings'), 'Admin section list must eager load settings once');
    }

    public function test_admin_content_page_show_eager_loads_section_types_and_settings_once(): void
    {
        $page = $this->seedHomeWithMultipleSections();

        Sanctum::actingAs($this->adminUser, ['*']);

        DB::enableQueryLog();
        $response = $this->getJson(self::PREFIX . '/content-pages/' . $page->id);
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertCount(5, $response->json('data.sections'));

        $this->assertSame(1, $this->countSelectQueriesFor('section_types'), 'Admin page show must eager load section types once');
        $this->assertSame(1, $this->countSelectQueriesFor('section_type_settings'), 'Admin page show must eager load settings once');
    }
}