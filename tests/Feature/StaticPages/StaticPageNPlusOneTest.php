<?php

namespace Tests\Feature\StaticPages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\StaticPage;
use Marvel\Database\Models\StaticSection;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaticPageNPlusOneTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';
    private const PUBLIC_INDEX = '/api/v1/general/static-pages';

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
            'email' => 'admin.n1@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000208',
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

    private function seedPagesAndSections(int $pages, int $sectionsPerPage): void
    {
        foreach (range(1, $pages) as $i) {
            $page = StaticPage::create([
                'slug' => 'page-' . $i,
                'title' => ['en' => 'Page ' . $i, 'ar' => 'صفحة'],
                'is_active' => true,
            ]);
            foreach (range(1, $sectionsPerPage) as $j) {
                StaticSection::create([
                    'static_page_id' => $page->id,
                    'title' => ['en' => "Section {$i}-{$j}", 'ar' => 'قسم'],
                    'content' => ['en' => ['heading' => 'X'], 'ar' => ['heading' => 'أ']],
                ]);
            }
        }
    }

    private function countQueriesTouching(string $table): int
    {
        return count(array_filter(DB::getQueryLog(), fn (array $q) => str_contains($q['query'], $table)));
    }

    public function test_public_index_query_count_is_constant_regardless_of_sections(): void
    {
        $this->seedPagesAndSections(3, 5);

        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(self::PUBLIC_INDEX)->assertOk();

        $log = DB::getQueryLog();

        $this->assertSame(1, $this->countQueriesTouching('"static_pages"'), 'Pages must be fetched with a single query');
        $this->assertSame(1, $this->countQueriesTouching('static_sections'), 'Sections must be eager loaded with a single query, not one per page');
    }

    public function test_public_show_query_count_is_constant(): void
    {
        $this->seedPagesAndSections(3, 5);

        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(self::PUBLIC_INDEX . '/page-1')->assertOk();

        $this->assertSame(1, $this->countQueriesTouching('static_sections'), 'Show must eager load sections in a single query');
    }

    public function test_admin_index_query_count_is_constant(): void
    {
        $this->seedPagesAndSections(3, 5);
        $this->actAsAdmin();

        $this->getJson(self::PREFIX . '/static-pages')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(self::PREFIX . '/static-pages')->assertOk();

        $this->assertSame(1, $this->countQueriesTouching('static_sections'), 'Admin index must eager load sections in a single query');
    }

    public function test_repeated_index_requests_are_served_from_cache_with_zero_queries(): void
    {
        $this->seedPagesAndSections(3, 5);

        $this->getJson(self::PUBLIC_INDEX)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(self::PUBLIC_INDEX)->assertOk();

        $this->assertCount(0, DB::getQueryLog(), 'Cached public responses must not hit the database');
    }
}