<?php

namespace Tests\Feature\Pages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\SectionType;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Tests\TestCase;

class SectionEndpointContractTest extends TestCase
{
    use RefreshDatabase;

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

        Cache::flush();
    }

    private function createHomePage(): ContentPage
    {
        return ContentPage::create([
            'title' => ['en' => 'Home', 'ar' => 'الرئيسية'],
            'slug' => 'home',
            'is_active' => true,
        ]);
    }

    private function createSectionAttached(ContentPage $page, string $type, array $back = [], ?string $storedEndpoint = null): Section
    {
        SectionType::create(['type' => $type]);

        $section = Section::create([
            'type' => $type,
            'title' => ['en' => 'Section', 'ar' => 'قسم'],
            'endpoint' => $storedEndpoint ?? 'general/' . $type,
            'is_active' => true,
            'title_visible' => true,
            'setting' => $back ? ['front' => [], 'back' => $back] : null,
        ]);

        $page->sections()->save($section);

        return $section;
    }

    private function homeSectionEndpoints(): array
    {
        $response = $this->getJson(self::PUBLIC_HOME);
        $response->assertOk();
        return collect($response->json('data.sections') ?? [])
            ->map(fn(array $section) => $section['endpoint'])
            ->all();
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

    public function test_each_seeded_section_type_generates_a_matching_general_endpoint(): void
    {
        $page = $this->createHomePage();

        foreach (self::SEEDED_TYPES as $type) {
            $this->createSectionAttached($page, $type);
        }

        $endpoints = $this->homeSectionEndpoints();

        $this->assertCount(count(self::SEEDED_TYPES), $endpoints);

        foreach (self::SEEDED_TYPES as $type) {
            $this->assertContains(
                'general/' . $type . '?',
                $endpoints,
                "Section type {$type} must generate an endpoint prefixed with general/{$type}"
            );
        }
    }

    public function test_stored_endpoint_is_ignored_and_generated_endpoint_is_authoritative(): void
    {
        $page = $this->createHomePage();
        $this->createSectionAttached($page, 'sliders', [], 'legacy-sliders');

        $endpoints = $this->homeSectionEndpoints();

        $this->assertSame(['general/sliders?'], $endpoints);
    }

    public function test_products_section_with_best_product_sales_resolves_to_real_products(): void
    {
        $this->makeProduct(['slug' => 'best-seller-contract']);
        $page = $this->createHomePage();
        $this->createSectionAttached($page, 'products', [
            'limit' => 5,
            'type' => 'best_product_sales',
        ]);

        $endpoint = $this->homeSectionEndpoints()[0];
        $this->assertStringContainsString('type=best_product_sales', $endpoint);

        $response = $this->getJson('/api/v1/' . $endpoint);
        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('best-seller-contract', $slugs);
    }

    public function test_products_section_with_new_arrivals_resolves_to_real_products(): void
    {
        $this->makeProduct(['slug' => 'new-arrival-contract']);
        $page = $this->createHomePage();
        $this->createSectionAttached($page, 'products', [
            'limit' => 5,
            'type' => 'new_arrivals',
        ]);

        $endpoint = $this->homeSectionEndpoints()[0];
        $this->assertStringContainsString('type=new_arrivals', $endpoint);

        $response = $this->getJson('/api/v1/' . $endpoint);
        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('new-arrival-contract', $slugs);
    }

    public function test_banners_section_endpoint_returns_real_banner_data(): void
    {
        $banner = Banner::create([
            'title' => 'Homepage Promotion Banner',
            'status' => true,
        ]);

        $page = $this->createHomePage();
        $this->createSectionAttached($page, 'banners', [
            'limit' => 5,
            'bannersId' => [$banner->id],
        ]);

        $endpoint = $this->homeSectionEndpoints()[0];
        $this->assertStringStartsWith('general/banners?', $endpoint);

        $response = $this->getJson('/api/v1/' . $endpoint);
        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains(Str::slug('Homepage Promotion Banner'), $slugs);
    }

    public function test_brands_section_endpoint_returns_real_brand_data(): void
    {
        $brand = Brand::create([
            'name' => ['en' => 'Sony', 'ar' => 'سوني'],
            'slug' => 'sony',
        ]);

        $page = $this->createHomePage();
        $this->createSectionAttached($page, 'brands', [
            'limit' => 5,
            'brandsId' => [$brand->id],
        ]);

        $endpoint = $this->homeSectionEndpoints()[0];
        $this->assertStringStartsWith('general/brands?', $endpoint);

        $response = $this->getJson('/api/v1/' . $endpoint);
        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('sony', $slugs);
    }

    public function test_resolved_endpoints_are_registered_public_routes(): void
    {
        $page = $this->createHomePage();

        foreach (['banners', 'sliders', 'promotions', 'tags', 'categories', 'products', 'flash-sales', 'brands', 'coupons'] as $type) {
            $this->createSectionAttached($page, $type);
        }

        $endpoints = $this->homeSectionEndpoints();

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson('/api/v1/' . $endpoint);
            $response->assertOk();
            $response->assertJsonStructure(['status', 'message', 'success', 'data']);
        }
    }
}