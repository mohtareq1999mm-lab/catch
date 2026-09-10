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

class StaticPageJsonRoundTripTest extends TestCase
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
            'email' => 'admin.roundtrip@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000201',
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

    private function defaultSectionPayload(): array
    {
        return [
            'title' => ['en' => 'Our Story', 'ar' => 'قصتنا'],
            'content' => [
                'en' => ['heading' => 'Welcome', 'body' => 'Hello world'],
                'ar' => ['heading' => 'مرحبا', 'body' => 'أهلا بالعالم'],
            ],
        ];
    }

    public function test_static_page_title_round_trips_through_database(): void
    {
        $page = $this->createStaticPage();

        $this->assertSame('About Us', $page->getTranslation('title', 'en'));
        $this->assertSame('من نحن', $page->getTranslation('title', 'ar'));
        $this->assertSame(['en' => 'About Us', 'ar' => 'من نحن'], $page->getTranslations('title'));
    }

    public function test_static_section_title_round_trips_through_database(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);

        $this->assertSame('Our Story', $section->getTranslation('title', 'en'));
        $this->assertSame('قصتنا', $section->getTranslation('title', 'ar'));
    }

    public function test_static_section_content_round_trips_through_database(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);

        $expected = ['en' => ['heading' => 'Welcome'], 'ar' => ['heading' => 'مرحبا']];
        $this->assertSame($expected, $section->getTranslations('content'));

        $raw = json_decode(DB::table('static_sections')->where('id', $section->id)->value('content'), true);
        $this->assertEquals($expected, $raw);
    }

    public function test_static_section_content_nested_arrays_round_trip(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page, [
            'content' => [
                'en' => ['blocks' => [['type' => 'text', 'text' => 'A'], ['type' => 'quote', 'text' => 'B']]],
                'ar' => ['blocks' => [['type' => 'text', 'text' => 'أ']]],
            ],
        ]);

        $this->assertEquals(
            ['en' => ['blocks' => [['type' => 'text', 'text' => 'A'], ['type' => 'quote', 'text' => 'B']]], 'ar' => ['blocks' => [['type' => 'text', 'text' => 'أ']]]],
            $section->getTranslations('content')
        );
    }

    public function test_static_section_content_scalar_values_round_trip(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page, [
            'content' => ['en' => ['count' => 5, 'visible' => true, 'ratio' => 1.5], 'ar' => ['count' => 3]],
        ]);

        $content = $section->getTranslations('content');
        $this->assertSame(5, $content['en']['count']);
        $this->assertTrue($content['en']['visible']);
        $this->assertSame(1.5, $content['en']['ratio']);
    }

    public function test_static_section_content_single_locale_round_trip(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page, [
            'content' => ['en' => ['heading' => 'Only English']],
        ]);

        $this->assertEquals(['en' => ['heading' => 'Only English']], $section->getTranslations('content'));
    }

    public function test_static_section_content_nullable_round_trip(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page, ['content' => null]);

        $this->assertSame([], $section->getTranslations('content'), 'A null translation must be filtered out of the translatable map');
        $this->assertEquals(['en' => null], json_decode(DB::table('static_sections')->where('id', $section->id)->value('content'), true));
    }

    public function test_static_section_created_via_api_round_trips_to_database(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', $this->defaultSectionPayload())
            ->assertOk();

        $this->assertSame(1, StaticSection::count());

        $stored = StaticSection::first();
        $this->assertSame('Our Story', $stored->getTranslation('title', 'en'));
        $this->assertSame('قصتنا', $stored->getTranslation('title', 'ar'));
        $this->assertEquals(
            ['en' => ['heading' => 'Welcome', 'body' => 'Hello world'], 'ar' => ['heading' => 'مرحبا', 'body' => 'أهلا بالعالم']],
            $stored->getTranslations('content')
        );
    }

    public function test_static_section_created_via_api_response_matches_database(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $response = $this->postJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections', $this->defaultSectionPayload())
            ->assertOk();

        $stored = StaticSection::first();

        $this->assertEquals($stored->id, $response->json('data.id'));
        $this->assertEquals($stored->static_page_id, $response->json('data.static_page_id'));
        $this->assertSame('Our Story', $response->json('data.title'));
        $this->assertEquals($stored->getTranslations('content'), $response->json('data.content'));
        $this->assertSame((int) $stored->order, $response->json('data.order'));
    }

    public function test_static_page_updated_via_api_round_trips_to_database(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, [
            'title' => ['en' => 'Updated About', 'ar' => 'محدثة'],
            'is_active' => 1,
        ])->assertOk();

        $page->refresh();
        $this->assertSame('Updated About', $page->getTranslation('title', 'en'));
        $this->assertSame('محدثة', $page->getTranslation('title', 'ar'));
    }

    public function test_static_page_updated_via_api_preserves_other_locale(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, [
            'title' => ['en' => 'Updated About'],
        ])->assertOk();

        $page->refresh();
        $this->assertSame('Updated About', $page->getTranslation('title', 'en'));
        $this->assertSame('من نحن', $page->getTranslation('title', 'ar'));
    }

    public function test_static_section_updated_via_api_content_partial_preserves_other_locale(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAsAdmin();

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, [
            'content' => ['en' => ['heading' => 'New Heading']],
        ])->assertOk();

        $section->refresh();
        $this->assertEquals(
            ['en' => ['heading' => 'New Heading'], 'ar' => ['heading' => 'مرحبا']],
            $section->getTranslations('content')
        );
    }

    public function test_static_section_updated_via_api_title_partial_preserves_other_locale(): void
    {
        $page = $this->createStaticPage();
        $section = $this->createStaticSection($page);
        $this->actAsAdmin();

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug . '/sections/' . $section->id, [
            'title' => ['ar' => 'قصتنا الجديدة'],
        ])->assertOk();

        $section->refresh();
        $this->assertSame('Our Story', $section->getTranslation('title', 'en'));
        $this->assertSame('قصتنا الجديدة', $section->getTranslation('title', 'ar'));
    }

    public function test_static_section_order_round_trips_as_integer(): void
    {
        $page = $this->createStaticPage();
        $first = $this->createStaticSection($page, ['title' => ['en' => 'First']]);
        $second = $this->createStaticSection($page, ['title' => ['en' => 'Second']]);

        $this->assertSame(1, $first->order);
        $this->assertSame(2, $second->order);
        $this->assertIsInt(DB::table('static_sections')->where('id', $first->id)->value('order'));
    }

    public function test_static_page_is_active_round_trips_as_boolean(): void
    {
        $page = $this->createStaticPage(['is_active' => false]);

        $this->assertFalse($page->is_active);
        $this->assertFalse($this->createStaticPage(['slug' => 'privacy', 'is_active' => 0])->is_active);

        $this->actAsAdmin();
        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, ['is_active' => 1])->assertOk();
        $this->assertTrue($page->refresh()->is_active);
    }

    public function test_public_api_round_trip_returns_localized_title(): void
    {
        $this->createStaticPage();

        $response = $this->getJson(self::PUBLIC_INDEX)->assertOk();

        $about = collect($response->json('data'))->firstWhere('slug', 'about-us');
        $this->assertSame('About Us', $about['title']);
    }

    public function test_public_api_round_trip_returns_localized_title_in_arabic(): void
    {
        $this->createStaticPage();

        $response = $this->getJson(self::PUBLIC_INDEX, ['lang' => 'ar'])->assertOk();

        $about = collect($response->json('data'))->firstWhere('slug', 'about-us');
        $this->assertSame('من نحن', $about['title']);
    }

    public function test_public_api_round_trip_returns_full_content_map(): void
    {
        $page = $this->createStaticPage();
        $this->createStaticSection($page);

        $response = $this->getJson(self::PUBLIC_INDEX)->assertOk();

        $about = collect($response->json('data'))->firstWhere('slug', 'about-us');
        $this->assertEquals(['en' => ['heading' => 'Welcome'], 'ar' => ['heading' => 'مرحبا']], $about['sections'][0]['content']);
    }

    public function test_admin_show_round_trip_returns_sections_in_order(): void
    {
        $page = $this->createStaticPage();
        $first = $this->createStaticSection($page, ['title' => ['en' => 'First'], 'order' => 1]);
        $second = $this->createStaticSection($page, ['title' => ['en' => 'Second']]);
        $this->actAsAdmin();

        $response = $this->getJson(self::PREFIX . '/static-pages/' . $page->slug)->assertOk();

        $sections = $response->json('data.sections');
        $this->assertCount(2, $sections);
        $this->assertEquals([$first->id, $second->id], array_column($sections, 'id'));
        $this->assertSame([1, 2], array_column($sections, 'order'));
    }

    public function test_round_trip_preserves_page_slug_identity(): void
    {
        $page = $this->createStaticPage();
        $this->actAsAdmin();

        $this->putJson(self::PREFIX . '/static-pages/' . $page->slug, [
            'title' => ['en' => 'Renamed'],
            'slug' => 'hacked-slug',
        ])->assertOk();

        $page->refresh();
        $this->assertSame('about-us', $page->slug);
        $this->assertSame('Renamed', $page->getTranslation('title', 'en'));
    }
}