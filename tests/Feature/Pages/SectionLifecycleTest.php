<?php

namespace Tests\Feature\Pages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\SectionType;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SectionLifecycleTest extends TestCase
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
            'email' => 'admin.lifecycle@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000122',
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

    private function publicHomeSections(): array
    {
        $response = $this->getJson(self::PUBLIC_HOME);
        $response->assertOk();
        return $response->json('data.sections') ?? [];
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Wireless Headphones', 'ar' => 'سماعات لاسلكية'],
            'slug' => 'wireless-headphones-' . uniqid(),
            'price' => 99.99,
            'status' => 'publish',
            'in_stock' => true,
            'stock_quantity' => 50,
            'reserved_quantity' => 0,
            'sold_quantity' => 10,
            'product_type' => 'simple',
            'has_discount' => false,
            'has_flash_sale' => false,
            'is_fast_shipping_available' => false,
        ], $overrides));
    }

    public function test_full_section_lifecycle_end_to_end(): void
    {
        SectionType::create(['type' => 'banners']);
        $page = ContentPage::create([
            'title' => ['en' => 'Home', 'ar' => 'الرئيسية'],
            'slug' => 'home',
            'is_active' => true,
        ]);

        $this->assertSame([], $this->publicHomeSections());

        $this->actAsAdmin();

        $createResponse = $this->postJson(self::PREFIX . '/sections', [
            'type' => 'banners',
            'title' => ['en' => 'Hero Banners', 'ar' => 'بنرات رئيسية'],
            'is_active' => 1,
            'title_visible' => 1,
        ]);
        $createResponse->assertOk();
        $sectionId = $createResponse->json('data.id');
        $this->assertStringStartsWith('general/banners?', $createResponse->json('data.endpoint'));

        $this->assertNull(Section::find($sectionId)->content_page_id);
        $this->assertSame([], $this->publicHomeSections());

        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', [
            'sections' => [$sectionId],
        ])->assertOk();

        $sections = $this->publicHomeSections();
        $this->assertCount(1, $sections);
        $this->assertSame($sectionId, $sections[0]['id']);

        $this->putJson(self::PREFIX . '/sections/' . $sectionId, [
            'title' => ['en' => 'Renamed Banners', 'ar' => 'بنرات معدلة'],
        ])->assertOk();

        $sections = $this->publicHomeSections();
        $this->assertSame('Renamed Banners', $sections[0]['title']);

        $this->patchJson(self::PREFIX . '/sections/' . $sectionId . '/toggle-active')->assertOk();

        $this->assertSame([], $this->publicHomeSections());

        $this->patchJson(self::PREFIX . '/sections/' . $sectionId . '/toggle-active')->assertOk();

        $this->assertCount(1, $this->publicHomeSections());

        SectionType::create(['type' => 'products']);

        $this->postJson(self::PREFIX . '/sections', [
            'type' => 'products',
            'title' => ['en' => 'Best Sellers', 'ar' => 'الأكثر مبيعاً'],
            'is_active' => 1,
            'title_visible' => 0,
        ])->assertOk();
        $productsSectionId = Section::where('type', 'products')->first()->id;

        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', [
            'sections' => [$sectionId, $productsSectionId],
        ])->assertOk();

        $this->postJson(self::PREFIX . '/section-types/products/settings', [
            'front' => ['columns_count' => 5],
            'back' => ['limit' => 5, 'type' => 'best_product_sales'],
        ])->assertOk();

        $sections = $this->publicHomeSections();
        $productsSection = collect($sections)->firstWhere('id', $productsSectionId);
        $this->assertStringContainsString('type=best_product_sales', $productsSection['endpoint']);

        $this->makeProduct(['slug' => 'lifecycle-best-seller']);

        $productsResponse = $this->getJson('/api/v1/' . $productsSection['endpoint']);
        $productsResponse->assertOk();
        $slugs = collect($productsResponse->json('data.data'))->pluck('slug')->all();
        $this->assertContains('lifecycle-best-seller', $slugs);

        $this->postJson(self::PREFIX . '/sections/reorder', [
            'sections' => [$productsSectionId, $sectionId],
        ])->assertOk();

        $sections = $this->publicHomeSections();
        $this->assertSame([$productsSectionId, $sectionId], array_column($sections, 'id'));

        $this->postJson(self::PREFIX . '/content-pages/' . $page->id . '/attach-sections', [
            'sections' => [],
        ])->assertOk();

        $this->assertSame([], $this->publicHomeSections());

        $this->deleteJson(self::PREFIX . '/section-types/products')->assertOk();

        $this->assertNull(Section::find($productsSectionId)->sectionType);
        $this->assertNotNull(Section::find($productsSectionId), 'Deleting a section type must not delete the section rows');
        $this->assertSame([], $this->publicHomeSections());
    }
}