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

class SectionReorderEdgeTest extends TestCase
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
            'email' => 'admin.reorder.edge@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000997',
            'is_active' => true,
        ]);
        $this->adminUser->givePermissionTo($permissions);
        $this->adminUser->assignRole(RoleEnum::EDITOR);

        Cache::flush();
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
    }

    private function createAttachedSections(int $count): array
    {
        $page = ContentPage::create([
            'title' => ['en' => 'Home', 'ar' => 'الرئيسية'],
            'slug' => 'home',
            'is_active' => true,
        ]);

        $sections = [];
        for ($i = 1; $i <= $count; $i++) {
            $section = Section::create([
                'type' => 'banner',
                'title' => ['en' => "Section {$i}", 'ar' => 'قسم'],
                'endpoint' => 'general/banner',
                'order' => $i,
                'is_active' => true,
                'title_visible' => true,
            ]);
            $page->sections()->save($section);
            $sections[] = $section;
        }

        return $sections;
    }

    public function test_reorder_with_empty_array_returns_422(): void
    {
        $this->actAsAdmin();

        $this->postJson(self::PREFIX . '/sections/reorder', [
            'sections' => [],
        ])->assertStatus(422);
    }

    public function test_reorder_with_duplicate_ids_returns_422(): void
    {
        $this->actAsAdmin();
        [$a, $b] = $this->createAttachedSections(2);

        $this->postJson(self::PREFIX . '/sections/reorder', [
            'sections' => [$a->id, $a->id, $b->id],
        ])->assertStatus(422);
    }

    public function test_reorder_with_non_integer_ids_returns_422(): void
    {
        $this->actAsAdmin();

        $this->postJson(self::PREFIX . '/sections/reorder', [
            'sections' => ['abc', 'def'],
        ])->assertStatus(422);
    }

    public function test_partial_reorder_keeps_unlisted_sections_in_relative_position(): void
    {
        $this->actAsAdmin();
        [$a, $b, $c] = $this->createAttachedSections(3);

        $this->postJson(self::PREFIX . '/sections/reorder', [
            'sections' => [$c->id, $a->id],
        ])->assertOk();

        $response = $this->getJson(self::PUBLIC_HOME);
        $response->assertOk();
        $this->assertSame(
            [$c->id, $a->id, $b->id],
            array_column($response->json('data.sections') ?? [], 'id')
        );
    }
}
