<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Hero;
use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as MarvelPermission;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class HeroApiTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    protected User $admin;
    protected User $customer;

    public function createApplication()
    {
        if ((getenv('DB_DATABASE') ?: 'catch') === 'catch') {
            putenv('DB_DATABASE=catch_verify');
            $_ENV['DB_DATABASE'] = 'catch_verify';
            $_SERVER['DB_DATABASE'] = 'catch_verify';
        }

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAllTestTables();
        Storage::fake('heroes');
        Cache::flush();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            MarvelPermission::VIEW_HERO,
            MarvelPermission::CREATE_HERO,
            MarvelPermission::UPDATE_HERO,
            MarvelPermission::DELETE_HERO,
            MarvelPermission::VIEW_SECTIONS,
            MarvelPermission::CREATE_SECTIONS,
            MarvelPermission::UPDATE_SECTIONS,
            MarvelPermission::DELETE_SECTIONS,
            MarvelPermission::VIEW_SECTION_TYPES,
            MarvelPermission::UPDATE_SECTION_TYPES,
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->admin = User::create([
            'name' => 'Hero Admin',
            'email' => 'hero-admin-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->admin->givePermissionTo($permissions);

        $this->customer = User::create([
            'name' => 'Customer User',
            'email' => 'hero-customer-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        // Ensure baseline clean state for Hero records in transaction
        Hero::query()->delete();

        $heroSectionType = SectionType::firstOrCreate(['type' => 'hero']);
        SectionTypeSetting::updateOrCreate(
            ['section_type_id' => $heroSectionType->id, 'setting_key' => 'front'],
            ['value' => ['autoplay' => true, 'loop' => true, 'muted' => true]]
        );
        SectionTypeSetting::updateOrCreate(
            ['section_type_id' => $heroSectionType->id, 'setting_key' => 'back'],
            ['value' => []]
        );
    }

    public function test_admin_can_create_image_hero_and_video_hero_with_mutual_exclusion(): void
    {
        // 1. Create first Hero (image, active = true)
        $imageFile = UploadedFile::fake()->image('hero-banner.jpg', 1200, 600);

        $res1 = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/hero', [
            'type' => 'image',
            'description' => [
                'en' => 'Welcome to Meem Store',
                'ar' => 'مرحباً بكم في متجر ميم',
            ],
            'is_active' => true,
            'media' => $imageFile,
        ]);

        $res1->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.description.en', 'Welcome to Meem Store')
            ->assertJsonPath('data.description.ar', 'مرحباً بكم في متجر ميم');

        $hero1Id = $res1->json('data.id');
        $this->assertNotNull($res1->json('data.media.url'));

        // 2. Create second Hero (video, active = true) -> must automatically deactivate Hero #1
        $videoFile = UploadedFile::fake()->create('promo.mp4', 1024, 'video/mp4');

        $res2 = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/hero', [
            'type' => 'video',
            'description' => [
                'en' => 'Watch our new collection',
                'ar' => 'شاهد مجموعتنا الجديدة',
            ],
            'is_active' => true,
            'media' => $videoFile,
        ]);

        $res2->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.is_active', true);

        $hero2Id = $res2->json('data.id');

        // Verify in database: only Hero #2 is active, Hero #1 is deactivated
        $this->assertFalse((bool) Hero::find($hero1Id)->is_active);
        $this->assertTrue((bool) Hero::find($hero2Id)->is_active);
        $this->assertSame(1, Hero::where('is_active', true)->count());

        // 3. Create third Hero with is_active = false -> Hero #2 remains active
        $imageFile3 = UploadedFile::fake()->image('draft-hero.png', 800, 400);
        $res3 = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/hero', [
            'type' => 'image',
            'description' => ['en' => 'Draft Hero', 'ar' => 'مسودة هيرو'],
            'is_active' => false,
            'media' => $imageFile3,
        ]);

        $res3->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertTrue((bool) Hero::find($hero2Id)->is_active);
        $this->assertSame(1, Hero::where('is_active', true)->count());
    }

    public function test_toggling_or_updating_hero_to_active_deactivates_all_other_heroes(): void
    {
        $h1 = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/hero', [
            'type' => 'image',
            'description' => ['en' => 'Hero 1', 'ar' => 'هيرو 1'],
            'is_active' => true,
            'media' => UploadedFile::fake()->image('h1.jpg'),
        ])->json('data.id');

        $h2 = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/hero', [
            'type' => 'image',
            'description' => ['en' => 'Hero 2', 'ar' => 'هيرو 2'],
            'is_active' => true,
            'media' => UploadedFile::fake()->image('h2.jpg'),
        ])->json('data.id');

        $this->assertFalse((bool) Hero::find($h1)->is_active);
        $this->assertTrue((bool) Hero::find($h2)->is_active);

        // Toggle Hero 1 active -> Hero 2 must automatically become inactive
        $toggleRes = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/hero/{$h1}/toggle-active");

        $toggleRes->assertOk()
            ->assertJsonPath('data.id', $h1)
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue((bool) Hero::find($h1)->is_active);
        $this->assertFalse((bool) Hero::find($h2)->is_active);
        $this->assertSame(1, Hero::where('is_active', true)->count());

        // Update Hero 2 with is_active = true -> Hero 1 must automatically become inactive
        $updateRes = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/hero/{$h2}", [
                'description' => ['en' => 'Hero 2 Updated', 'ar' => 'هيرو 2 محدث'],
                'is_active' => true,
            ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.id', $h2)
            ->assertJsonPath('data.is_active', true);

        $this->assertFalse((bool) Hero::find($h1)->is_active);
        $this->assertTrue((bool) Hero::find($h2)->is_active);
        $this->assertSame(1, Hero::where('is_active', true)->count());
    }

    public function test_public_general_hero_endpoint_returns_single_active_hero_object_not_array(): void
    {
        // When no Hero is active, data is null
        $emptyRes = $this->getJson('/api/v1/general/hero');
        $emptyRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        // Create two heroes; second becomes the only active one
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/hero', [
            'type' => 'image',
            'description' => ['en' => 'Old Hero', 'ar' => 'هيرو قديم'],
            'is_active' => true,
            'media' => UploadedFile::fake()->image('old.jpg'),
        ]);

        $activeHeroRes = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/hero', [
            'type' => 'video',
            'description' => ['en' => 'Active Video Hero', 'ar' => 'فيديو هيرو مفعل'],
            'is_active' => true,
            'media' => UploadedFile::fake()->create('active.mp4', 512, 'video/mp4'),
        ]);
        $activeId = $activeHeroRes->json('data.id');

        // Public GET in English
        $pubEn = $this->withHeaders(['lang' => 'en'])->getJson('/api/v1/general/hero');
        $pubEn->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $activeId)
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.description', 'Active Video Hero')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonStructure([
                'status',
                'message',
                'success',
                'data' => [
                    'id',
                    'type',
                    'description',
                    'is_active',
                    'media' => ['id', 'url', 'mime_type', 'file_name', 'size'],
                ],
            ]);

        // Public GET in Arabic
        $pubAr = $this->withHeaders(['lang' => 'ar'])->getJson('/api/v1/general/hero');
        $pubAr->assertOk()
            ->assertJsonPath('data.id', $activeId)
            ->assertJsonPath('data.description', 'فيديو هيرو مفعل');
    }

    public function test_validation_rejects_mismatched_media_mime_for_hero_type(): void
    {
        // Sending MP4 video when type is 'image' -> 422
        $res1 = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/hero', [
            'type' => 'image',
            'description' => ['en' => 'Mismatch', 'ar' => 'خطأ'],
            'media' => UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'),
        ]);
        $res1->assertStatus(422)->assertJsonValidationErrors(['media']);

        // Sending JPEG image when type is 'video' -> 422
        $res2 = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/hero', [
            'type' => 'video',
            'description' => ['en' => 'Mismatch', 'ar' => 'خطأ'],
            'media' => UploadedFile::fake()->image('pic.jpg'),
        ]);
        $res2->assertStatus(422)->assertJsonValidationErrors(['media']);
    }

    public function test_unauthorized_and_forbidden_access_to_admin_hero_endpoints(): void
    {
        $this->getJson('/api/v1/hero')->assertStatus(401);
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/hero')->assertStatus(403);
    }

    public function test_full_sections_integration_for_hero_type(): void
    {
        $homePage = ContentPage::firstOrCreate(
            ['slug' => 'home'],
            ['title' => ['en' => 'Home', 'ar' => 'الرئيسية'], 'is_active' => true]
        );

        // 1. GET /api/v1/section-types includes 'hero'
        $typesRes = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/section-types');
        $typesRes->assertOk();
        $this->assertContains('hero', $typesRes->json('data'));

        // 2. GET & POST /api/v1/section-types/hero/settings work (200 OK)
        $getSettings = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/section-types/hero/settings');
        $getSettings->assertOk()
            ->assertJsonStructure(['status', 'message', 'success', 'data' => ['front', 'back']]);

        $postSettings = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/section-types/hero/settings', [
            'front' => ['autoplay' => true, 'loop' => true, 'muted' => false],
            'back' => [],
        ]);
        $postSettings->assertOk()
            ->assertJsonPath('data.front.muted', false);

        // 3. POST /api/v1/sections with {"type": "hero"} succeeds (no longer 422)
        $createSectionRes = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sections', [
            'type' => 'hero',
            'title' => ['en' => 'Main Hero', 'ar' => 'الهيرو الرئيسي'],
            'is_active' => 1,
            'title_visible' => 0,
            'order' => 1,
        ]);

        $createSectionRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'hero')
            ->assertJsonPath('data.endpoint', 'general/hero?');

        $sectionId = $createSectionRes->json('data.id');
        Section::where('id', $sectionId)->update(['content_page_id' => $homePage->id]);
        Cache::flush();

        // 4. GET /api/v1/general/content-pages/home includes the hero section with endpoint 'general/hero?'
        $homeRes = $this->getJson('/api/v1/general/content-pages/home');
        $homeRes->assertOk();
        $heroSection = collect($homeRes->json('data.sections'))->firstWhere('type', 'hero');
        $this->assertNotNull($heroSection);
        $this->assertSame('general/hero?', $heroSection['endpoint']);

        // 5. POST /api/v1/sections with minimal payload {"type": "hero"} succeeds
        $minimalSectionRes = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sections', [
            'type' => 'hero',
        ]);
        $minimalSectionRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'hero')
            ->assertJsonPath('data.endpoint', 'general/hero?');
    }

    public function test_admin_list_show_delete_and_type_change_media_validation(): void
    {
        $createRes = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/hero', [
            'type' => 'image',
            'description' => ['en' => 'Initial Hero', 'ar' => 'هيرو أولي'],
            'is_active' => true,
            'media' => UploadedFile::fake()->image('initial.jpg'),
        ]);
        $heroId = $createRes->json('data.id');

        // List
        $listRes = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/hero');
        $listRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 1);

        // Show
        $showRes = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/hero/{$heroId}");
        $showRes->assertOk()
            ->assertJsonPath('data.id', $heroId)
            ->assertJsonPath('data.description.en', 'Initial Hero')
            ->assertJsonPath('data.description.ar', 'هيرو أولي');

        // Changing type from image to video without media file -> 422
        $badUpdate = $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/hero/{$heroId}", [
            'type' => 'video',
        ]);
        $badUpdate->assertStatus(422)->assertJsonValidationErrors(['media']);

        // Changing type from image to video WITH video file -> 200
        $goodUpdate = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/hero/{$heroId}", [
            'type' => 'video',
            'description' => ['en' => 'Now Video', 'ar' => 'الآن فيديو'],
            'media' => UploadedFile::fake()->create('new-video.mp4', 800, 'video/mp4'),
        ]);
        $goodUpdate->assertOk()
            ->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.media.mime_type', 'video/mp4');

        // Delete active hero -> public endpoint returns data: null
        $delRes = $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/hero/{$heroId}");
        $delRes->assertOk()->assertJsonPath('success', true);

        $this->getJson('/api/v1/general/hero')
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}

