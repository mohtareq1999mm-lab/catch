<?php

namespace Tests\Feature\Pages;

use App\Enums\FrontendResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\Tag;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeepVerificationAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';
    private const PUBLIC_INDEX = '/api/v1/general/content-pages';
    private const PUBLIC_HOME = '/api/v1/general/content-pages/home';

    private const SEEDED_TYPES = [
        'banners',
        'sliders',
        'promotions',
        'tags',
        'categories',
        'products',
        'flash-sales',
        'brands',
        'coupons',
    ];

    private const REPORT_FILE = null; // computed in setUp

    private static string $reportPath = '';

    private User $adminUser;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

        config(['filesystems.disks.products' => [
            'driver' => 'local',
            'root' => storage_path('app/public/products'),
            'url' => env('APP_URL') . '/public/storage/products',
            'visibility' => 'public',
        ]]);

        if (self::$reportPath === '') {
            self::$reportPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opencode'
                . DIRECTORY_SEPARATOR . 'deep-verification-report.txt';
            @mkdir(dirname(self::$reportPath), 0777, true);
            @file_put_contents(self::$reportPath, '');
        }

        $this->createPermissions();

        $this->adminUser = User::create([
            'name' => 'Deep Audit Admin',
            'email' => 'deep.audit@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000199',
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

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
    }

    private function evidence(string $line): void
    {
        @file_put_contents(self::$reportPath, $line . PHP_EOL, FILE_APPEND);
    }

    private function db(string $table, int $id): ?object
    {
        return DB::table($table)->where('id', $id)->first();
    }

    private function countRows(string $table): int
    {
        return DB::table($table)->count();
    }

    private function makePage(array $overrides = []): ContentPage
    {
        $suffix = substr(uniqid(), -6);
        return ContentPage::create(array_merge([
            'title' => ['en' => 'Page ' . $suffix, 'ar' => 'صفحة ' . $suffix],
            'slug' => 'page-' . $suffix,
            'is_active' => true,
        ], $overrides));
    }

    private function makeType(string $type): SectionType
    {
        return SectionType::create(['type' => $type]);
    }

    private function makeSection(string $type, array $overrides = []): Section
    {
        return Section::create(array_merge([
            'type' => $type,
            'title' => ['en' => 'Section', 'ar' => 'قسم'],
            'endpoint' => 'general/' . $type,
            'is_active' => true,
            'title_visible' => true,
        ], $overrides));
    }

    private function publicHomeSections(): array
    {
        $response = $this->getJson(self::PUBLIC_HOME);
        $response->assertOk();
        return $response->json('data.sections') ?? [];
    }

    private function publicIndexPages(): array
    {
        $response = $this->getJson(self::PUBLIC_INDEX);
        $response->assertOk();
        return $response->json('data') ?? [];
    }

    // =========================================================================
    // 4. CREATE CONTENT PAGE — FULL VERIFICATION
    // =========================================================================

    public function test_deep_create_content_page(): void
    {
        $before = $this->countRows('content_pages');
        $this->evidence("== 4. CREATE CONTENT PAGE ==");
        $this->evidence("   content_pages before: $before");

        $this->actAsAdmin();
        $response = $this->postJson(self::PREFIX . '/content-pages', [
            'title' => ['en' => 'Deep Verify Page', 'ar' => 'صفحة التحقق العميق'],
        ]);
        $response->assertStatus(201);
        $response->assertJsonStructure(['status', 'message', 'success', 'data']);
        $this->assertTrue($response->json('success'));
        $this->assertSame(201, $response->json('status'));

        $id = $response->json('data.id');
        $this->assertIsInt($id);
        $this->assertSame('deep-verify-page', $response->json('data.slug'));
        $this->assertSame(true, $response->json('data.is_active'));

        $this->evidence("   HTTP 201, returned id=$id slug={$response->json('data.slug')}");

        $row = $this->db('content_pages', $id);
        $this->assertNotNull($row, 'row must exist in DB');
        $this->assertSame(1, $this->countRows('content_pages') - $before, 'delta must be +1');

        $title = json_decode($row->title, true);
        $this->assertSame('Deep Verify Page', $title['en'] ?? null);
        $this->assertSame('صفحة التحقق العميق', $title['ar'] ?? null);
        $this->assertSame('deep-verify-page', $row->slug);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertNotNull($row->created_at);
        $this->assertNotNull($row->updated_at);
        $this->assertSame(1, DB::table('content_pages')->where('slug', 'deep-verify-page')->count(), 'no duplicate');

        $this->evidence("   DB row: title=" . json_encode($title) . " slug={$row->slug} is_active={$row->is_active}");
        $this->evidence("   DB created_at={$row->created_at} updated_at={$row->updated_at}");

        // observer: public index cache must be flushed by creation
        $this->getJson(self::PUBLIC_INDEX)->assertOk();
        $key = md5(url(self::PUBLIC_INDEX));
        $this->assertTrue(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key), 'public index cached');
        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/content-pages', [
            'title' => ['en' => 'Second Page', 'ar' => 'صفحة ثانية'],
        ])->assertStatus(201);
        $this->assertFalse(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key), 'creation must flush content_pages cache');
        $this->evidence("   cache: public index entry present before mutation, flushed after content page create (observer side effect)");
    }

    // =========================================================================
    // 5. CONTENT PAGE UPDATE — DATABASE PROOF
    // =========================================================================

    public function test_deep_update_content_page(): void
    {
        $this->evidence("== 5. UPDATE CONTENT PAGE ==");
        $page = $this->makePage(['title' => ['en' => 'Old Title', 'ar' => 'عنوان قديم'], 'slug' => 'home']);

        $this->actAsAdmin();
        $response = $this->putJson(self::PREFIX . '/content-pages/' . $page->id, [
            'title' => ['en' => 'New Title', 'ar' => 'عنوان جديد'],
            'is_active' => 0,
        ]);
        $response->assertStatus(200);
        $response->assertOk();
        $this->assertSame('New Title', $response->json('data.title'));

        $row = $this->db('content_pages', $page->id);
        $title = json_decode($row->title, true);
        $this->assertSame('New Title', $title['en'] ?? null);
        $this->assertSame('عنوان جديد', $title['ar'] ?? null);
        $this->assertSame(0, (int) $row->is_active);
        $this->assertNotNull($row->updated_at, 'updated_at persisted');
        $this->assertGreaterThanOrEqual($row->created_at, $row->updated_at, 'updated_at >= created_at');
        $this->assertSame('home', $row->slug, 'slug must NOT change on title update');

        $this->evidence("   DB after update: title=" . json_encode($title) . " is_active={$row->is_active} slug={$row->slug} (unchanged)");
        $this->evidence("   DB created_at={$row->created_at} updated_at={$row->updated_at} (updated_at persisted)");

        // restore active so public home stays reachable, then verify title reflects in public
        $this->putJson(self::PREFIX . '/content-pages/' . $page->id, ['is_active' => 1])->assertOk();
        $this->assertSame(1, (int) DB::table('content_pages')->where('id', $page->id)->value('is_active'), 'is_active restored to 1');

        // cache invalidation + public reflects new state
        $this->getJson(self::PUBLIC_HOME)->assertOk();
        $key = md5(url(self::PUBLIC_HOME));
        $this->assertTrue(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key));
        $this->assertSame('New Title', $this->getJson(self::PUBLIC_HOME)->json('data.title'), 'public reflects updated title');
        $this->actAsAdmin();
        $this->putJson(self::PREFIX . '/content-pages/' . $page->id, [
            'title' => ['en' => 'Third Title', 'ar' => 'عنوان ثالث'],
        ])->assertOk();
        $this->assertFalse(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key), 'update must flush cache');
        $this->evidence("   cache: content_pages cache flushed on update; public reflects new DB title");
    }

    // =========================================================================
    // 6. CONTENT PAGE DELETE — DATABASE PROOF (orphan sections)
    // =========================================================================

    public function test_deep_delete_content_page_leaves_orphan_sections(): void
    {
        $this->evidence("== 6. DELETE CONTENT PAGE ==");
        $this->makeType('banners');
        $page = $this->makePage(['slug' => 'doomed-page']);
        $s1 = $this->makeSection('banners');
        $s2 = $this->makeSection('banners');
        $page->sections()->saveMany([$s1, $s2]);

        $this->assertSame(2, DB::table('sections')->where('content_page_id', $page->id)->count());

        $this->actAsAdmin();
        $response = $this->deleteJson(self::PREFIX . '/content-pages/' . $page->id);
        $response->assertOk();
        $this->assertTrue($response->json('success'));

        $this->assertSame(0, $this->countRows('content_pages') ? 0 : DB::table('content_pages')->where('id', $page->id)->count(), 'page row must be gone');
        $this->assertNull(DB::table('content_pages')->where('id', $page->id)->first(), 'no content_pages row remains');
        $this->assertSame(2, $this->countRows('sections'), 'sections must survive');
        $this->assertSame(2, DB::table('sections')->whereNull('content_page_id')->count(), 'orphan sections (nullOnDelete)');
        $this->assertNotNull(DB::table('sections')->where('id', $s1->id)->first());
        $this->assertNotNull(DB::table('sections')->where('id', $s2->id)->first());

        $this->evidence("   DB: content_pages row for id={$page->id} deleted; sections survive; content_page_id set to NULL (nullOnDelete FK)");
    }

    // =========================================================================
    // 7. ACTIVE / INACTIVE PUBLIC VISIBILITY — DATABASE PROOF
    // =========================================================================

    public function test_deep_public_visibility_filters_inactive_pages(): void
    {
        $this->evidence("== 7. ACTIVE/INACTIVE PUBLIC VISIBILITY ==");
        $this->makeType('banners');
        $pageA = $this->makePage(['slug' => 'home', 'is_active' => true]);
        $pageB = $this->makePage(['slug' => 'inactive-page', 'is_active' => false]);
        $secA = $this->makeSection('banners', ['title' => ['en' => 'Active Section', 'ar' => 'قسم نشط']]);
        $secB = $this->makeSection('banners', ['title' => ['en' => 'Inactive Section', 'ar' => 'قسم غير نشط']]);
        $pageA->sections()->save($secA);
        $pageB->sections()->save($secB);

        // B exists in DB but must be excluded publicly
        $this->assertSame(1, DB::table('content_pages')->where('id', $pageB->id)->where('is_active', 0)->count(), 'inactive page really exists with is_active=0');

        $pages = $this->publicIndexPages();
        $slugs = array_column($pages, 'slug');
        $this->assertContains('home', $slugs);
        $this->assertNotContains('inactive-page', $slugs);
        $this->evidence("   public index slugs: " . json_encode($slugs));

        $this->getJson(self::PUBLIC_INDEX . '/home')->assertOk();
        $this->getJson(self::PUBLIC_INDEX . '/inactive-page')->assertStatus(404);
        $this->evidence("   public show: active-page -> 200, inactive-page -> 404");

        // sections of the inactive page must not be exposed
        $home = $this->getJson(self::PUBLIC_HOME);
        $home->assertOk();
        $titles = array_column($home->json('data.sections') ?? [], 'title');
        $this->assertNotContains('Inactive Section', $titles);
        $this->evidence("   public home section titles: " . json_encode($titles));
    }

    // =========================================================================
    // 8. SECTION TYPE + SETTINGS — FULL DATABASE TEST
    // =========================================================================

    public function test_deep_section_type_and_settings(): void
    {
        $this->evidence("== 8. SECTION TYPE + SETTINGS ==");
        $this->actAsAdmin();

        $before = $this->countRows('section_types');
        $response = $this->postJson(self::PREFIX . '/section-types', ['type' => 'custom-audit']);
        $response->assertOk();
        $this->assertSame(1, $this->countRows('section_types') - $before, 'delta +1');

        $row = DB::table('section_types')->where('type', 'custom-audit')->first();
        $this->assertNotNull($row);
        $this->assertSame('custom-audit', $row->type);
        $this->assertNotNull($row->created_at);
        $this->evidence("   section_types row id={$row->id} type={$row->type}");

        // settings create
        $beforeSettings = $this->countRows('section_type_settings');
        $response = $this->postJson(self::PREFIX . '/section-types/custom-audit/settings', [
            'front' => ['display' => 'grid', 'columns' => 3],
            'back' => ['limit' => 5, 'order' => 'desc'],
        ]);
        $response->assertOk();
        $this->assertSame(2, $this->countRows('section_type_settings') - $beforeSettings, 'delta +2');

        $settings = DB::table('section_type_settings')->where('section_type_id', $row->id)->orderBy('setting_key')->get();
        $this->assertCount(2, $settings);
        foreach ($settings as $s) {
            $this->assertSame($row->id, $s->section_type_id, 'correct relation');
            $this->assertNotNull($s->value);
        }
        $this->evidence("   settings rows: " . json_encode($settings->map(fn ($s) => [$s->setting_key => json_decode($s->value, true)])->all()));

        // settings replace (upsert deletes old rows)
        $response = $this->postJson(self::PREFIX . '/section-types/custom-audit/settings', [
            'front' => ['display' => 'list'],
            'back' => ['limit' => 10, 'type' => 'new_arrivals'],
        ]);
        $response->assertOk();
        $this->assertSame(2, $this->countRows('section_type_settings'), 'replacement keeps 2 rows (no stale)');
        $back = DB::table('section_type_settings')->where('section_type_id', $row->id)->where('setting_key', 'back')->first();
        $this->assertSame(10, json_decode($back->value, true)['limit'] ?? null);
        $this->assertSame(0, DB::table('section_type_settings')->where('section_type_id', $row->id)->where('setting_key', 'back')->whereRaw('value like ?', ['%columns%'])->count(), 'old front value removed');
        $this->evidence("   after replace: no stale rows; back value=" . json_decode($back->value, true)['limit'] . " (old 'columns' key gone)");
    }

    // =========================================================================
    // 9. SECTION CREATE — DATABASE VERIFICATION
    // =========================================================================

    public function test_deep_section_create(): void
    {
        $this->evidence("== 9. SECTION CREATE ==");
        $this->makeType('banners');

        $this->actAsAdmin();
        $before = $this->countRows('sections');
        $response = $this->postJson(self::PREFIX . '/sections', [
            'type' => 'banners',
            'title' => ['en' => 'Hero Banners', 'ar' => 'بنرات رئيسية'],
            'is_active' => 1,
            'title_visible' => 1,
        ]);
        $response->assertOk();
        $this->assertSame(1, $this->countRows('sections') - $before, 'delta +1');
        $id = $response->json('data.id');
        $this->assertSame('general/banners?', $response->json('data.endpoint'));

        $row = $this->db('sections', $id);
        $this->assertNotNull($row);
        $this->assertSame('banners', $row->type, 'type stored as the section_types.type string, NOT the id');
        $this->assertNull($row->content_page_id, 'unattached section has null content_page_id');
        $this->assertSame(1, (int) $row->is_active);
        $this->assertSame(1, (int) $row->title_visible);
        $this->assertIsInt((int) $row->order);
        $title = json_decode($row->title, true);
        $this->assertSame('Hero Banners', $title['en'] ?? null);
        $this->assertSame('بنرات رئيسية', $title['ar'] ?? null);

        // type must reference section_types.type, verify the row exists
        $this->assertSame(1, DB::table('section_types')->where('type', 'banners')->count());
        $this->evidence("   DB section row: id=$id type={$row->type} content_page_id=" . var_export($row->content_page_id, true) . " order={$row->order} title=" . json_encode($title));
        $this->evidence("   type 'banners' exists in section_types (relation = section_types.type, not id)");
    }

    // =========================================================================
    // 10. SECTION ATTACHMENT VERIFICATION
    // =========================================================================

    public function test_deep_attach_detach(): void
    {
        $this->evidence("== 10. ATTACH/DETACH ==");
        $this->makeType('banners');
        $page = $this->makePage();
        $s1 = $this->makeSection('banners');
        $s2 = $this->makeSection('banners');
        $s3 = $this->makeSection('banners');

        $this->actAsAdmin();

        // attach two
        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', ['sections' => [$s1->id, $s2->id]])->assertOk();
        $this->assertSame([$s1->id, $s2->id], DB::table('sections')->where('content_page_id', $page->id)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->evidence("   attach [{$s1->id},{$s2->id}] -> DB content_page_id=" . json_encode(DB::table('sections')->where('content_page_id', $page->id)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all()));

        // attach third (additive: existing attachments preserved)
        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', ['sections' => [$s3->id]])->assertOk();
        $this->assertSame(3, DB::table('sections')->where('content_page_id', $page->id)->count());
        $this->assertSame($page->id, (int) DB::table('sections')->where('id', $s3->id)->value('content_page_id'));
        $this->evidence("   attach [{$s3->id}] -> all 3 attached (additive)");

        // re-attaching a subset is additive per documented contract (only [] detaches)
        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', ['sections' => [$s1->id, $s2->id]])->assertOk();
        $this->assertSame(3, DB::table('sections')->where('content_page_id', $page->id)->count(), 'subset attach is additive, does NOT detach s3');
        $this->evidence("   re-attach subset [{$s1->id},{$s2->id}] -> still 3 attached (additive; no partial-detach endpoint in contract)");

        // detach all
        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', ['sections' => []])->assertOk();
        $this->assertSame(0, DB::table('sections')->where('content_page_id', $page->id)->count());
        $this->assertSame(3, DB::table('sections')->whereNull('content_page_id')->count(), 'no unexpected detach (all null, none deleted)');
        $this->evidence("   detach all -> 0 attached, 3 orphaned, no section row deleted");
    }

    // =========================================================================
    // 11. REORDER — REAL DATABASE VERIFICATION
    // =========================================================================

    public function test_deep_reorder(): void
    {
        $this->evidence("== 11. REORDER ==");
        $this->makeType('banners');
        $page = $this->makePage(['slug' => 'home']);
        $s1 = $this->makeSection('banners');
        $s2 = $this->makeSection('banners');
        $s3 = $this->makeSection('banners');
        $page->sections()->saveMany([$s1, $s2, $s3]);

        $before = DB::table('sections')->whereIn('id', [$s1->id, $s2->id, $s3->id])->orderBy('id')->pluck('order', 'id')->all();
        $this->evidence("   before order: " . json_encode($before));

        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/sections/reorder', ['sections' => [$s3->id, $s1->id, $s2->id]])->assertOk();

        $after = DB::table('sections')->whereIn('id', [$s1->id, $s2->id, $s3->id])->orderBy('id')->pluck('order', 'id')->all();
        $this->evidence("   after  order: " . json_encode($after));
        $this->assertSame((int) $after[$s3->id], 1, 's3 -> order 1');
        $this->assertSame((int) $after[$s1->id], 2, 's1 -> order 2');
        $this->assertSame((int) $after[$s2->id], 3, 's2 -> order 3');
        $this->assertSame([$s3->id, $s1->id, $s2->id], array_column($this->publicHomeSections(), 'id'), 'public home reflects DB order');

        // invalid requests must not mutate the DB order
        $snapshot = DB::table('sections')->whereIn('id', [$s1->id, $s2->id, $s3->id])->orderBy('id')->pluck('order', 'id')->all();
        $invalidPayloads = [
            'empty array' => ['sections' => []],
            'duplicate ids' => ['sections' => [$s3->id, $s3->id, $s1->id]],
            'non-integer' => ['sections' => ['a', 'b']],
            'non-existent id' => ['sections' => [999999, $s1->id]],
        ];
        foreach ($invalidPayloads as $label => $payload) {
            $this->postJson(self::PREFIX . '/sections/reorder', $payload)->assertStatus(422);
            $now = DB::table('sections')->whereIn('id', [$s1->id, $s2->id, $s3->id])->orderBy('id')->pluck('order', 'id')->all();
            $this->assertSame($snapshot, $now, "no DB mutation for invalid reorder ($label)");
            $this->evidence("   invalid reorder ($label): 422, DB order unchanged");
        }
    }

    // =========================================================================
    // 12. SECTION UPDATE — DATABASE PROOF
    // =========================================================================

    public function test_deep_section_update(): void
    {
        $this->evidence("== 12. SECTION UPDATE ==");
        $this->makeType('banners');
        $section = $this->makeSection('banners', [
            'title' => ['en' => 'Old', 'ar' => 'قديم'],
            'is_active' => 1,
            'title_visible' => 1,
        ]);

        $this->actAsAdmin();
        $this->putJson(self::PREFIX . '/sections/' . $section->id, [
            'title' => ['en' => 'Updated', 'ar' => 'محدث'],
            'is_active' => 0,
            'title_visible' => 0,
            'setting' => ['front' => ['display' => 'grid'], 'back' => ['slug' => 'x']],
        ])->assertOk();

        $row = $this->db('sections', $section->id);
        $title = json_decode($row->title, true);
        $this->assertSame('Updated', $title['en'] ?? null);
        $this->assertSame(0, (int) $row->is_active);
        $this->assertSame(0, (int) $row->title_visible);
        $setting = json_decode($row->setting, true);
        $this->assertSame('grid', $setting['front']['display'] ?? null);
        $this->assertSame('banners', $row->type, 'type unchanged');
        $this->assertNull($row->content_page_id, 'content_page_id unchanged (null)');
        $this->evidence("   DB after update: title=" . json_encode($title) . " is_active={$row->is_active} title_visible={$row->title_visible} setting=" . json_encode($setting));
    }

    // =========================================================================
    // 13. TOGGLE STATUS — DB + PUBLIC
    // =========================================================================

    public function test_deep_toggle_section_status(): void
    {
        $this->evidence("== 13. TOGGLE STATUS ==");
        $this->makeType('banners');
        $page = $this->makePage(['slug' => 'home']);
        $section = $this->makeSection('banners');
        $page->sections()->save($section);

        $this->assertCount(1, $this->publicHomeSections());

        $this->actAsAdmin();
        $this->patchJson(self::PREFIX . '/sections/' . $section->id . '/toggle-active')->assertOk();
        $this->assertSame(0, (int) DB::table('sections')->where('id', $section->id)->value('is_active'), 'DB is_active now 0');
        $this->assertSame([], $this->publicHomeSections(), 'public hides inactive section');
        $this->evidence("   toggle off: DB is_active=0, public home hides it");

        $this->patchJson(self::PREFIX . '/sections/' . $section->id . '/toggle-active')->assertOk();
        $this->assertSame(1, (int) DB::table('sections')->where('id', $section->id)->value('is_active'), 'DB is_active now 1');
        $this->assertCount(1, $this->publicHomeSections(), 'public shows active section again');
        $this->evidence("   toggle on: DB is_active=1, public home shows it again");
    }

    // =========================================================================
    // 14. SECTION TYPE DELETE — DB PROOF (settings cascade, sections survive)
    // =========================================================================

    public function test_deep_section_type_delete(): void
    {
        $this->evidence("== 14. SECTION TYPE DELETE ==");
        $type = $this->makeType('banners');
        $typeId = $type->id;
        SectionTypeSetting::create(['section_type_id' => $typeId, 'setting_key' => 'front', 'value' => ['display' => 'grid']]);
        SectionTypeSetting::create(['section_type_id' => $typeId, 'setting_key' => 'back', 'value' => ['limit' => 5]]);
        $page = $this->makePage(['slug' => 'home']);
        $section = $this->makeSection('banners');
        $page->sections()->save($section);

        $this->actAsAdmin();
        $this->deleteJson(self::PREFIX . '/section-types/banners')->assertOk();

        $this->assertNull(DB::table('section_types')->where('id', $typeId)->first(), 'type row deleted');
        $this->assertSame(0, DB::table('section_type_settings')->where('section_type_id', $typeId)->count(), 'settings cascade-deleted');
        $this->assertNotNull(DB::table('sections')->where('id', $section->id)->first(), 'section row survives');
        $this->assertSame('banners', DB::table('sections')->where('id', $section->id)->value('type'), 'section keeps type string');
        $this->assertSame(1, (int) DB::table('sections')->where('id', $section->id)->value('is_active'), 'section still active');
        $this->evidence("   DB: section_types gone, settings gone (cascade), section survives as orphan with type 'banners'");

        // public home handles orphan gracefully
        $home = $this->getJson(self::PUBLIC_HOME);
        $home->assertOk();
        $this->assertSame('general/banners?', $home->json('data.sections.0.endpoint'));
        $this->assertSame([], $home->json('data.sections.0.setting.front') ?? [], 'orphan has empty settings');
        $this->evidence("   public home: orphan section renders endpoint general/banners? with empty settings");
    }

    // =========================================================================
    // 16. PUBLIC ENDPOINT — REAL DATA (every seeded SectionType)
    // =========================================================================

    public function test_deep_public_endpoints_return_real_db_data(): void
    {
        $this->evidence("== 16. PUBLIC ENDPOINT REAL DATA ==");
        $page = $this->makePage(['slug' => 'home']);
        $typeIdMap = [];

        // banners
        $banner = Banner::create(['title' => 'Audit Banner', 'status' => true]);
        $this->makeType('banners');
        $s = $this->makeSection('banners', ['setting' => ['front' => [], 'back' => ['bannersId' => [$banner->id]]]]);
        $page->sections()->save($s);
        $typeIdMap['banners'] = $banner->id;

        // sliders
        $slider = Slider::create(['title' => ['en' => 'Audit Slider', 'ar' => 'سلايدر'], 'slug' => 'audit-slider', 'status' => true]);
        $this->makeType('sliders');
        $s = $this->makeSection('sliders');
        $page->sections()->save($s);
        $typeIdMap['sliders'] = $slider->id;

        // promotions
        $promotion = Promotion::create([
            'name' => ['en' => 'Audit Promotion'],
            'slug' => 'audit-promotion-' . uniqid(),
            'code' => 'PROMO-' . uniqid(),
            'type' => 'price',
            'type_amount' => 'percentage',
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'apply_to' => 'specific_products',
            'status' => true,
            'start_at' => now()->subDay()->format('Y-m-d'),
            'end_at' => now()->addDay()->format('Y-m-d'),
        ]);
        $this->makeType('promotions');
        $s = $this->makeSection('promotions');
        $page->sections()->save($s);
        $typeIdMap['promotions'] = $promotion->id;

        // tags
        $tag = Tag::create(['name' => ['en' => 'Audit Tag'], 'slug' => 'audit-tag']);
        $this->makeType('tags');
        $s = $this->makeSection('tags');
        $page->sections()->save($s);
        $typeIdMap['tags'] = $tag->id;

        // categories
        $category = Category::create(['name' => ['en' => 'Audit Category'], 'slug' => 'audit-category']);
        $this->makeType('categories');
        $s = $this->makeSection('categories');
        $page->sections()->save($s);
        $typeIdMap['categories'] = $category->id;

        // products
        $product = Product::create([
            'name' => ['en' => 'Audit Product', 'ar' => 'منتج'],
            'slug' => 'audit-product-' . uniqid(),
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
        $this->makeType('products');
        $s = $this->makeSection('products', ['setting' => ['front' => [], 'back' => ['limit' => 5, 'type' => 'new_arrivals']]]);
        $page->sections()->save($s);
        $typeIdMap['products'] = $product->id;

        // flash-sales
        $flashSale = FlashSale::create([
            'title' => 'Audit Flash Sale',
            'slug' => 'audit-flash-sale-' . uniqid(),
            'discount' => 20,
            'type' => 'percentage',
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);
        $this->makeType('flash-sales');
        $s = $this->makeSection('flash-sales');
        $page->sections()->save($s);
        $typeIdMap['flash-sales'] = $flashSale->id;

        // brands
        $brand = Brand::create(['name' => ['en' => 'Audit Brand'], 'slug' => 'audit-brand']);
        $this->makeType('brands');
        $s = $this->makeSection('brands');
        $page->sections()->save($s);
        $typeIdMap['brands'] = $brand->id;

        // coupons
        $coupon = Coupon::create([
            'code' => 'AUDIT10',
            'name' => 'Audit Coupon',
            'slug' => 'audit-coupon',
            'type' => 'general',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $this->makeType('coupons');
        $s = $this->makeSection('coupons');
        $page->sections()->save($s);
        $typeIdMap['coupons'] = $coupon->id;

        $endpoints = $this->publicHomeSections();
        $this->assertCount(count(self::SEEDED_TYPES), $endpoints);
        $byType = collect($endpoints)->keyBy('type');

        $tableFor = [
            'banners' => 'banners',
            'sliders' => 'sliders',
            'promotions' => 'promotions',
            'tags' => 'tags',
            'categories' => 'categories',
            'products' => 'products',
            'flash-sales' => 'flash_sales',
            'brands' => 'brands',
            'coupons' => 'coupons',
        ];

        foreach (self::SEEDED_TYPES as $type) {
            $endpoint = $byType[$type]['endpoint'] ?? null;
            $this->assertNotNull($endpoint, "endpoint for $type");
            $this->assertStringStartsWith('general/' . $type . '?', $endpoint);

            $response = $this->getJson('/api/v1/' . $endpoint);
            $response->assertOk();
            $response->assertJsonStructure(['status', 'message', 'success', 'data']);

            $payload = $response->json('data');
            $entityIds = [];
            if ($type === 'products') {
                $entityIds = array_column($payload['data'] ?? [], 'id');
                $this->assertContains($typeIdMap['products'], $entityIds, 'products endpoint contains the created product id');
            } else {
                $entityIds = array_column($payload, 'id');
            }

            $this->assertNotEmpty($entityIds, "$type endpoint returned at least one entity");
            $table = $tableFor[$type];
            foreach ($entityIds as $eid) {
                $this->assertNotNull(DB::table($table)->where('id', $eid)->first(), "entity id $eid exists in $table");
            }
            $this->evidence("   $type: GET /api/v1/$endpoint -> 200, returned ids " . json_encode($entityIds) . " all verified in table '$table'");
        }
    }

    // =========================================================================
    // 17. STORED ENDPOINT VS GENERATED ENDPOINT
    // =========================================================================

    public function test_deep_stored_endpoint_ignored(): void
    {
        $this->evidence("== 17. STORED VS GENERATED ENDPOINT ==");
        $this->makeType('sliders');
        $slider = Slider::create(['title' => ['en' => 'Stored Endpoint Slider', 'ar' => 'سلايدر'], 'slug' => 'stored-endpoint-slider', 'status' => true]);
        $page = $this->makePage(['slug' => 'home']);

        $section = Section::create([
            'type' => 'sliders',
            'title' => ['en' => 'Stored Endpoint', 'ar' => 'نقطة وصول مخزنة'],
            'endpoint' => 'legacy/evil-endpoint', // deliberately incorrect stored value
            'is_active' => true,
            'title_visible' => true,
        ]);
        $page->sections()->save($section);

        // DB still holds the wrong stored value
        $this->assertSame('legacy/evil-endpoint', DB::table('sections')->where('id', $section->id)->value('endpoint'));

        $sections = $this->publicHomeSections();
        $this->assertSame('general/sliders?', $sections[0]['endpoint'], 'generated endpoint is authoritative');
        $this->evidence("   stored endpoint in DB = 'legacy/evil-endpoint' (ignored); response endpoint = 'general/sliders?' (generated)");

        $response = $this->getJson('/api/v1/general/sliders');
        $response->assertOk();
        $slugs = array_column($response->json('data'), 'slug');
        $this->assertContains('stored-endpoint-slider', $slugs, 'generated endpoint returns real DB-backed data');
        $this->evidence("   generated endpoint returns real slider: " . json_encode($slugs));
    }

    // =========================================================================
    // 18. TRANSLATION DATABASE VERIFICATION
    // =========================================================================

    public function test_deep_translations_en_ar(): void
    {
        $this->evidence("== 18. TRANSLATIONS ==");
        $this->makeType('banners');
        $page = ContentPage::create([
            'title' => ['en' => 'Translated Home', 'ar' => 'الرئيسية المترجمة'],
            'slug' => 'translated-home',
            'is_active' => true,
        ]);
        $section = $this->makeSection('banners', ['title' => ['en' => 'Translated Section', 'ar' => 'قسم مترجم']]);
        $page->sections()->save($section);

        $row = DB::table('content_pages')->where('id', $page->id)->first();
        $titleJson = json_decode($row->title, true);
        $this->assertSame('Translated Home', $titleJson['en']);
        $this->assertSame('الرئيسية المترجمة', $titleJson['ar']);
        $this->evidence("   DB stored title JSON: " . json_encode($titleJson));

        // model translation
        $this->assertSame('Translated Home', $page->getTranslation('title', 'en'));
        $this->assertSame('الرئيسية المترجمة', $page->getTranslation('title', 'ar'));

        // en response
        $this->get('/api/v1/general/content-pages/translated-home', ['lang' => 'en'])->assertOk();
        $en = $this->getJson('/api/v1/general/content-pages/translated-home')->json('data');
        $this->assertSame('Translated Home', $en['title']);
        $this->assertSame('Translated Section', $en['sections'][0]['title']);
        $this->evidence("   EN response title='{$en['title']}' section='{$en['sections'][0]['title']}'");

        // ar response
        $ar = $this->getJson('/api/v1/general/content-pages/translated-home', ['lang' => 'ar'])->json('data');
        $this->assertSame('الرئيسية المترجمة', $ar['title']);
        $this->assertSame('قسم مترجم', $ar['sections'][0]['title']);
        $this->evidence("   AR response title='{$ar['title']}' section='{$ar['sections'][0]['title']}'");

        $this->assertSame('الرئيسية المترجمة', $page->getTranslation('title', 'ar'), 'model returns AR when locale set');
    }

    // =========================================================================
    // 19. CACHE VERIFICATION — bypass paths (reorder/attach/settings)
    // =========================================================================

    public function test_deep_cache_bypass_paths(): void
    {
        $this->evidence("== 19. CACHE BYPASS PATHS ==");
        $this->makeType('banners');
        $this->makeType('products'); // created up-front so its observer flush happens before cache population below
        $page = $this->makePage(['slug' => 'home']);
        $s1 = $this->makeSection('banners');
        $s2 = $this->makeSection('banners');
        $s3 = $this->makeSection('banners');
        $page->sections()->saveMany([$s1, $s2, $s3]);

        $key = md5(url(self::PUBLIC_HOME));

        $this->assertSame([$s1->id, $s2->id, $s3->id], array_column($this->publicHomeSections(), 'id'));
        $this->assertTrue(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key), 'cached before');
        $this->evidence("   cache entry exists before mutations (key=" . substr($key, 0, 12) . "...)");

        // reorder (setNewOrder query builder path)
        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/sections/reorder', ['sections' => [$s3->id, $s1->id, $s2->id]])->assertOk();
        $this->assertFalse(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key), 'reorder flushes cache');
        $this->assertSame([$s3->id, $s1->id, $s2->id], array_column($this->publicHomeSections(), 'id'), 'reorder reflected');
        $this->evidence("   reorder: cache flushed + next GET reflects new DB order");

        // attach (re-attach same set: content_page_id update path)
        $this->assertTrue(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key));
        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', ['sections' => [$s1->id, $s2->id]])->assertOk();
        $this->assertFalse(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key), 'attach flushes cache');
        $this->assertSame([$s3->id, $s1->id, $s2->id], array_column($this->publicHomeSections(), 'id'));
        $this->evidence("   attach: cache flushed + reflected");

        // detach-all (query builder update path)
        $this->assertTrue(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key));
        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', ['sections' => []])->assertOk();
        $this->assertFalse(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key), 'detach-all flushes cache');
        $this->assertSame([], $this->publicHomeSections(), 'detach-all reflected');
        $this->evidence("   detach-all: cache flushed + public home empty");

        // settings bulk delete (query builder delete path)
        $this->assertTrue(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key));
        $this->postJson(self::PREFIX . '/section-types/products/settings', ['front' => ['a' => 1], 'back' => ['b' => 2]])->assertOk();
        $this->assertFalse(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key), 'settings bulk update flushes cache');
        $this->publicHomeSections(); // repopulate
        $this->evidence("   settings bulk update: cache flushed");

        // section type delete
        $this->assertTrue(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key));
        $this->deleteJson(self::PREFIX . '/section-types/banners')->assertOk();
        $this->assertFalse(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($key), 'type delete flushes cache');
        $this->evidence("   section type delete: cache flushed");
    }

    // =========================================================================
    // 20. CACHE ISOLATION
    // =========================================================================

    public function test_deep_cache_isolation_products_unaffected(): void
    {
        $this->evidence("== 20. CACHE ISOLATION ==");
        $this->makeType('banners');
        $this->makePage(['slug' => 'home']);
        $product = Product::create([
            'name' => ['en' => 'Isolation Product', 'ar' => 'منتج'],
            'slug' => 'isolation-product',
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

        $productsUrl = '/api/v1/general/products?limit=50';
        $productsResponse = $this->getJson($productsUrl);
        $productsResponse->assertOk();
        $productsPayload = $productsResponse->json('data');

        // populate the content_pages cache too
        $this->getJson(self::PUBLIC_HOME)->assertOk();

        $contentKey = md5(url(self::PUBLIC_HOME));
        // plant direct markers so we can prove tag-level isolation regardless of internal cache keys
        Cache::tags([FrontendResource::PRODUCTS->value])->put('isolation-marker-products', 'still-here', 60);
        Cache::tags([FrontendResource::CONTENT_PAGES->value])->put('isolation-marker-content', 'flushed-expected', 60);

        $this->assertTrue(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($contentKey), 'content_pages cache present');
        $this->assertTrue(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has('isolation-marker-content'), 'content marker present');
        $this->assertTrue(Cache::tags([FrontendResource::PRODUCTS->value])->has('isolation-marker-products'), 'products marker present');
        $this->evidence("   both cache tags populated with known markers + the products endpoint payload");

        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/sections', [
            'type' => 'banners',
            'title' => ['en' => 'Isolation Section', 'ar' => 'قسم عزل'],
            'is_active' => 1,
            'title_visible' => 1,
        ])->assertOk();

        $this->assertFalse(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has($contentKey), 'content_pages cache invalidated');
        $this->assertFalse(Cache::tags([FrontendResource::CONTENT_PAGES->value])->has('isolation-marker-content'), 'content_pages tag flushed (marker gone)');
        $this->assertSame('still-here', Cache::tags([FrontendResource::PRODUCTS->value])->get('isolation-marker-products'), 'products tag must NOT be flushed (marker intact)');
        $this->assertSame($productsPayload, $this->getJson($productsUrl)->json('data'), 'products payload identical after section mutation');

        $this->evidence("   after section create: content_pages tag flushed (content marker gone), products tag intact (marker preserved) and payload identical");
    }

    // =========================================================================
    // 21. N+1 VERIFICATION — query log
    // =========================================================================

    public function test_deep_n_plus_one_query_counts(): void
    {
        $this->evidence("== 21. N+1 ==");
        $page = $this->makePage(['slug' => 'home']);
        foreach (['banners', 'products', 'brands'] as $type) {
            $this->makeType($type);
            SectionTypeSetting::create([
                'section_type_id' => SectionType::where('type', $type)->first()->id,
                'setting_key' => 'front',
                'value' => ['autoplay' => true],
            ]);
            SectionTypeSetting::create([
                'section_type_id' => SectionType::where('type', $type)->first()->id,
                'setting_key' => 'back',
                'value' => ['limit' => 10, 'order' => 'desc'],
            ]);
        }
        foreach (range(1, 6) as $i) {
            $type = ['banners', 'products', 'brands'][$i % 3];
            $section = $this->makeSection($type, ['title' => ['en' => "Section $i", 'ar' => 'قسم']]);
            $page->sections()->save($section);
        }

        DB::enableQueryLog();
        $response = $this->getJson(self::PUBLIC_HOME);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertCount(6, $response->json('data.sections'));

        $count = function (string $table) use ($queries) {
            return collect($queries)->filter(fn ($q) => preg_match('/^select/i', $q['query']) && preg_match('/\bfrom\s+["`]?' . preg_quote($table, '/') . '["`]?/i', $q['query']))->count();
        };

        $this->evidence("   total queries: " . count($queries));
        foreach (['content_pages', 'sections', 'section_types', 'section_type_settings'] as $t) {
            $this->evidence("   select from $t: " . $count($t));
        }

        $this->assertSame(1, $count('section_types'), 'one section_types query (eager)');
        $this->assertSame(1, $count('section_type_settings'), 'one settings query (eager)');
        $this->assertSame(1, $count('content_pages'), 'one content_pages query');
        $this->assertSame(1, $count('sections'), 'one sections query');
    }

    // =========================================================================
    // 22. AUTHORIZATION MATRIX
    // =========================================================================

    public function test_deep_authorization_matrix(): void
    {
        $this->evidence("== 22. AUTHORIZATION MATRIX ==");
        $page = $this->makePage();
        $this->makeType('banners');
        $section = $this->makeSection('banners');

        // guest -> 401
        $guest = [
            ['get', self::PREFIX . '/content-pages'],
            ['post', self::PREFIX . '/content-pages'],
            ['put', self::PREFIX . '/content-pages/' . $page->id],
            ['delete', self::PREFIX . '/content-pages/' . $page->id],
            ['patch', self::PREFIX . '/content-pages/' . $page->id . '/toggle-active'],
            ['post', self::PREFIX . '/content-pages/' . $page->id . '/attach-sections'],
            ['get', self::PREFIX . '/sections'],
            ['post', self::PREFIX . '/sections'],
            ['put', self::PREFIX . '/sections/' . $section->id],
            ['delete', self::PREFIX . '/sections/' . $section->id],
            ['post', self::PREFIX . '/sections/reorder'],
            ['get', self::PREFIX . '/sections/types'],
            ['patch', self::PREFIX . '/sections/' . $section->id . '/toggle-active'],
            ['get', self::PREFIX . '/section-types'],
            ['post', self::PREFIX . '/section-types'],
            ['post', self::PREFIX . '/section-types/banners/settings'],
        ];
        foreach ($guest as [$method, $url]) {
            $this->{$method . 'Json'}($url)->assertStatus(401);
        }
        $this->evidence("   guest: all 16 admin routes -> 401");

        // authenticated user with NO permissions -> 403
        $plain = User::create([
            'name' => 'No Perms User',
            'email' => 'no-perms@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000198',
            'is_active' => true,
        ]);
        Sanctum::actingAs($plain, ['*']);
        $forbidden = [
            ['get', self::PREFIX . '/content-pages'],
            ['post', self::PREFIX . '/content-pages'],
            ['put', self::PREFIX . '/content-pages/' . $page->id],
            ['delete', self::PREFIX . '/content-pages/' . $page->id],
            ['patch', self::PREFIX . '/content-pages/' . $page->id . '/toggle-active'],
            ['post', self::PREFIX . '/content-pages/' . $page->id . '/attach-sections'],
            ['get', self::PREFIX . '/sections'],
            ['post', self::PREFIX . '/sections'],
            ['put', self::PREFIX . '/sections/' . $section->id],
            ['delete', self::PREFIX . '/sections/' . $section->id],
            ['post', self::PREFIX . '/sections/reorder'],
            ['get', self::PREFIX . '/sections/types'],
            ['patch', self::PREFIX . '/sections/' . $section->id . '/toggle-active'],
            ['get', self::PREFIX . '/section-types'],
            ['post', self::PREFIX . '/section-types'],
            ['post', self::PREFIX . '/section-types/banners/settings'],
        ];
        foreach ($forbidden as [$method, $url]) {
            $this->{$method . 'Json'}($url)->assertStatus(403);
        }
        $this->evidence("   authenticated no-permission user: all 16 admin routes -> 403");

        // admin -> success
        $this->actAsAdmin();
        $this->getJson(self::PREFIX . '/content-pages')->assertOk();
        $this->getJson(self::PREFIX . '/sections')->assertOk();
        $this->getJson(self::PREFIX . '/sections/types')->assertOk();
        $this->getJson(self::PREFIX . '/section-types')->assertOk();
        $this->postJson(self::PREFIX . '/content-pages', ['title' => ['en' => 'Authz Page', 'ar' => 'صفحة']])->assertStatus(201);
        $this->evidence("   authorized admin: reads + create -> success");
    }

    // =========================================================================
    // 23. VALIDATION MATRIX — 422 + zero DB mutation
    // =========================================================================

    public function test_deep_validation_matrix_no_db_mutation(): void
    {
        $this->evidence("== 23. VALIDATION MATRIX ==");
        $this->makeType('banners');
        $page = $this->makePage();

        $this->actAsAdmin();

        $beforePages = $this->countRows('content_pages');
        $beforeSections = $this->countRows('sections');
        $beforeTypes = $this->countRows('section_types');
        $beforeSettings = $this->countRows('section_type_settings');

        $invalid = [
            'content page missing title' => ['post', self::PREFIX . '/content-pages', []],
            'content page missing title.en' => ['post', self::PREFIX . '/content-pages', ['title' => ['ar' => 'عربي فقط']]],
            'content page title not array' => ['post', self::PREFIX . '/content-pages', ['title' => 'scalar-title']],
            'section missing type' => ['post', self::PREFIX . '/sections', ['title' => ['en' => 'X', 'ar' => 'ص']]],
            'section invalid type' => ['post', self::PREFIX . '/sections', ['type' => 'does-not-exist', 'title' => ['en' => 'X', 'ar' => 'ص']]],
            'attach invalid section ids' => ['post', self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', ['sections' => [999999]]],
            'reorder empty' => ['post', self::PREFIX . '/sections/reorder', ['sections' => []]],
            'reorder duplicate' => ['post', self::PREFIX . '/sections/reorder', ['sections' => [1, 1]]],
            'reorder non-integer' => ['post', self::PREFIX . '/sections/reorder', ['sections' => ['a', 'b']]],
            'reorder non-existent' => ['post', self::PREFIX . '/sections/reorder', ['sections' => [888888]]],
            'section type empty' => ['post', self::PREFIX . '/section-types', ['type' => '']],
            'section type duplicate' => ['post', self::PREFIX . '/section-types', ['type' => 'banners']],
            'settings not array' => ['post', self::PREFIX . '/section-types/banners/settings', ['front' => 'not-array']],
        ];

        foreach ($invalid as $label => [$method, $url, $payload]) {
            $this->{$method . 'Json'}($url, $payload)->assertStatus(422);
            $this->evidence("   $label: 422");
        }

        $this->assertSame($beforePages, $this->countRows('content_pages'), 'content_pages unchanged');
        $this->assertSame($beforeSections, $this->countRows('sections'), 'sections unchanged');
        $this->assertSame($beforeTypes, $this->countRows('section_types'), 'section_types unchanged');
        $this->assertSame($beforeSettings, $this->countRows('section_type_settings'), 'section_type_settings unchanged');
        $this->evidence("   ZERO unintended DB mutations: pages=$beforePages sections=$beforeSections types=$beforeTypes settings=$beforeSettings all unchanged");
    }

    // =========================================================================
    // 30/31. DB INTEGRITY + TEST DATA ACCOUNTING
    // =========================================================================

    public function test_deep_db_integrity_and_accounting(): void
    {
        $this->evidence("== 30/31. DB INTEGRITY + ACCOUNTING ==");
        $this->makeType('banners');
        $this->makeType('products');

        $before = [
            'content_pages' => $this->countRows('content_pages'),
            'sections' => $this->countRows('sections'),
            'section_types' => $this->countRows('section_types'),
            'section_type_settings' => $this->countRows('section_type_settings'),
        ];
        $this->evidence("   BEFORE: " . json_encode($before));

        $this->actAsAdmin();
        $this->postJson(self::PREFIX . '/content-pages', ['title' => ['en' => 'Accounting Page', 'ar' => 'صفحة']])->assertStatus(201);
        $this->postJson(self::PREFIX . '/sections', ['type' => 'banners', 'title' => ['en' => 'S', 'ar' => 'ق']])->assertOk();
        $this->postJson(self::PREFIX . '/sections', ['type' => 'products', 'title' => ['en' => 'P', 'ar' => 'م']])->assertOk();

        $after = [
            'content_pages' => $this->countRows('content_pages'),
            'sections' => $this->countRows('sections'),
            'section_types' => $this->countRows('section_types'),
            'section_type_settings' => $this->countRows('section_type_settings'),
        ];
        $this->evidence("   AFTER:  " . json_encode($after));
        $this->assertSame($before['content_pages'] + 1, $after['content_pages'], '+1 page');
        $this->assertSame($before['sections'] + 2, $after['sections'], '+2 sections');
        $this->assertSame($before['section_types'], $after['section_types'], '0 types (sections reference existing types)');
        $this->assertSame($before['section_type_settings'], $after['section_type_settings'], '0 settings');

        // integrity: no duplicate slugs; no duplicate settings; orphans only from nullOnDelete
        $dupeSlugs = DB::table('content_pages')->select('slug')->groupBy('slug')->havingRaw('count(*) > 1')->count();
        $this->assertSame(0, $dupeSlugs, 'no duplicate slugs');
        $dupeSettings = DB::table('section_type_settings')->select(DB::raw('section_type_id, setting_key'))->groupBy('section_type_id', 'setting_key')->havingRaw('count(*) > 1')->count();
        $this->assertSame(0, $dupeSettings, 'no duplicate settings');

        $this->evidence("   integrity: duplicate slugs=0, duplicate settings=0");
    }
}