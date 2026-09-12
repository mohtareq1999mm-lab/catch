<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\FlashSale;
use Tests\TestCase;

class GeneralActiveVisibilityFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function createActiveProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Active Product '.uniqid()],
            'slug' => 'active-product-'.uniqid(),
            'price' => 100,
            'status' => 1,
            'in_stock' => 1,
            'stock_quantity' => 10,
        ], $overrides));
    }

    private function createInactiveProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Inactive Product '.uniqid()],
            'slug' => 'inactive-product-'.uniqid(),
            'price' => 100,
            'status' => 0,
            'in_stock' => 0,
            'stock_quantity' => 0,
        ], $overrides));
    }

    private function createActiveCategory(array $overrides = []): Category
    {
        return Category::create(array_merge([
            'name' => ['en' => 'Active Cat '.uniqid()],
            'status' => 1,
        ], $overrides));
    }

    private function createInactiveCategory(array $overrides = []): Category
    {
        return Category::create(array_merge([
            'name' => ['en' => 'Inactive Cat '.uniqid()],
            'status' => 0,
        ], $overrides));
    }

    private function createActiveBrand(array $overrides = []): Brand
    {
        return Brand::create(array_merge([
            'name' => ['en' => 'Active Brand '.uniqid()],
            'status' => 1,
        ], $overrides));
    }

    private function createInactiveBrand(array $overrides = []): Brand
    {
        return Brand::create(array_merge([
            'name' => ['en' => 'Inactive Brand '.uniqid()],
            'status' => 0,
        ], $overrides));
    }

    public function test_public_products_only_active()
    {
        $active = $this->createActiveProduct(['name'=>['en'=>'AAA Active'], 'slug'=>'aaa-active-'.uniqid()]);
        $inactive = $this->createInactiveProduct(['name'=>['en'=>'BBB Inactive'], 'slug'=>'bbb-inactive-'.uniqid()]);

        $response = $this->getJson('/api/v1/general/products');
        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->toArray();
        // also check flat data key fallback (ProductCollectionMini)
        if (empty($ids)) {
            $ids = collect($response->json('data'))->pluck('id')->toArray();
        }
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_inactive_product_detail_not_found()
    {
        $inactive = $this->createInactiveProduct();
        $this->getJson('/api/v1/general/products/'.$inactive->slug)->assertStatus(404);
    }

    public function test_active_product_detail_found()
    {
        $active = $this->createActiveProduct();
        $this->getJson('/api/v1/general/products/'.$active->slug)->assertOk();
    }

    public function test_mixed_products_pagination_excludes_inactive()
    {
        $a = $this->createActiveProduct(['name'=>['en'=>'A active']]);
        $b = $this->createInactiveProduct(['name'=>['en'=>'B inactive']]);
        $c = $this->createActiveProduct(['name'=>['en'=>'C active']]);
        $d = $this->createInactiveProduct(['name'=>['en'=>'D inactive']]);
        $e = $this->createActiveProduct(['name'=>['en'=>'E active']]);

        // per_page 2 should paginate only active: A,C,E
        $response = $this->getJson('/api/v1/general/products?limit=2');
        $response->assertOk();
        $data = $response->json('data.data') ?? $response->json('data');
        $this->assertCount(2, $data);
        $ids = collect($data)->pluck('id')->toArray();
        $this->assertNotContains($b->id, $ids);
        $this->assertNotContains($d->id, $ids);
        // total should be 3 active
        $total = $response->json('data.total') ?? $response->json('meta.total') ?? null;
        if ($total !== null) {
            $this->assertEquals(3, $total);
        }
    }

    public function test_public_categories_only_active()
    {
        $active = $this->createActiveCategory();
        $inactive = $this->createInactiveCategory();

        $response = $this->getJson('/api/v1/general/categories');
        $response->assertOk();
        $data = $response->json('data.data') ?? $response->json('data');
        $ids = collect($data)->pluck('id')->toArray();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_public_brands_only_active()
    {
        $active = $this->createActiveBrand();
        $inactive = $this->createInactiveBrand();

        $response = $this->getJson('/api/v1/general/brands');
        $response->assertOk();
        $data = $response->json('data') ?? $response->json('data.data') ?? [];
        // BrandService returns Collection without pagination wrapper in some paths, but controller wraps BrandResource::collection
        if (isset($data['data'])) $data = $data['data'];
        $ids = collect($data)->pluck('id')->toArray();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_nested_category_products_only_active()
    {
        $cat = $this->createActiveCategory();
        $activeProduct = $this->createActiveProduct();
        $inactiveProduct = $this->createInactiveProduct();
        $cat->products()->attach([$activeProduct->id, $inactiveProduct->id]);

        $response = $this->getJson('/api/v1/general/categories/'.$cat->slug);
        $response->assertOk();
        $productIds = collect($response->json('data.products'))->pluck('id')->toArray();
        $this->assertContains($activeProduct->id, $productIds);
        $this->assertNotContains($inactiveProduct->id, $productIds);
        // withCount should be 1
        $this->assertEquals(1, $response->json('data.products_count'));
    }

    public function test_nested_brand_products_only_active()
    {
        $brand = $this->createActiveBrand();
        $activeProduct = $this->createActiveProduct();
        $inactiveProduct = $this->createInactiveProduct();
        $brand->products()->attach([$activeProduct->id, $inactiveProduct->id]);

        $response = $this->getJson('/api/v1/general/brands/'.$brand->slug);
        $response->assertOk();
        $productIds = collect($response->json('data.products'))->pluck('id')->toArray();
        $this->assertContains($activeProduct->id, $productIds);
        $this->assertNotContains($inactiveProduct->id, $productIds);
    }

    public function test_banner_products_only_active()
    {
        $banner = Banner::create(['title'=>['en'=>'Banner '.uniqid()], 'slug'=>'banner-'.uniqid(), 'status'=>1]);
        $active = $this->createActiveProduct();
        $inactive = $this->createInactiveProduct();
        $banner->products()->attach([$active->id, $inactive->id]);

        $response = $this->getJson('/api/v1/general/banners/'.$banner->slug.'?with_products=true');
        $response->assertOk();
        $productIds = collect($response->json('data.products'))->pluck('id')->toArray();
        $this->assertContains($active->id, $productIds);
        $this->assertNotContains($inactive->id, $productIds);
    }

    public function test_slider_products_only_active()
    {
        $slider = Slider::create(['title'=>['en'=>'Slider '.uniqid()], 'slug'=>'slider-'.uniqid(), 'status'=>1]);
        $active = $this->createActiveProduct();
        $inactive = $this->createInactiveProduct();
        $slider->products()->attach([$active->id, $inactive->id]);

        $response = $this->getJson('/api/v1/general/sliders/'.$slider->slug);
        $response->assertOk();
        $productIds = collect($response->json('data.products'))->pluck('id')->toArray();
        $this->assertContains($active->id, $productIds);
        $this->assertNotContains($inactive->id, $productIds);
    }

    public function test_promotion_products_only_active()
    {
        $promo = Promotion::create(['name'=>['en'=>'Promo '.uniqid()], 'code'=>'PROMO'.uniqid(), 'status'=>1, 'type'=>'price', 'type_amount'=>'percentage', 'discount'=>10, 'value'=>10, 'start_at'=>now()->subDay(), 'end_at'=>now()->addDay()]);
        $active = $this->createActiveProduct();
        $inactive = $this->createInactiveProduct();
        $promo->products()->attach([$active->id, $inactive->id]);

        $response = $this->getJson('/api/v1/general/promotions/'.$promo->slug);
        $response->assertOk();
        $productIds = collect($response->json('data.products'))->pluck('id')->toArray();
        $this->assertContains($active->id, $productIds);
        $this->assertNotContains($inactive->id, $productIds);
    }

    public function test_flash_sale_products_only_active()
    {
        $flash = FlashSale::create(['title'=>['en'=>'Flash '.uniqid()], 'slug'=>'flash-'.uniqid(), 'status'=>1, 'start_date'=>now()->subDay(), 'end_date'=>now()->addDay()]);
        $active = $this->createActiveProduct(['has_flash_sale'=>1]);
        $inactive = $this->createInactiveProduct(['has_flash_sale'=>1]);
        $flash->products()->attach([$active->id, $inactive->id]);

        $response = $this->getJson('/api/v1/general/flash-sales/'.$flash->slug);
        $response->assertOk();
        $productIds = collect($response->json('data.products'))->pluck('id')->toArray();
        $this->assertContains($active->id, $productIds);
        $this->assertNotContains($inactive->id, $productIds);
    }

    public function test_search_excludes_inactive()
    {
        $active = $this->createActiveProduct(['name'=>['en'=>'UniqueSearchActive'.uniqid()], 'slug'=>'usearch-active-'.uniqid()]);
        $inactive = $this->createInactiveProduct(['name'=>['en'=>'UniqueSearchActive'.uniqid()], 'slug'=>'usearch-inactive-'.uniqid()]);
        // Ensure inactive name contains same term
        $inactive->update(['name'=>['en'=>$active->getTranslation('name','en')]]);

        $term = $active->getTranslation('name','en');
        $response = $this->getJson('/api/v1/general/products?search='.urlencode($term));
        $response->assertOk();
        $data = $response->json('data.data') ?? $response->json('data');
        $ids = collect($data)->pluck('id')->toArray();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_counts_exclude_inactive()
    {
        $cat = $this->createActiveCategory();
        $active = $this->createActiveProduct();
        $inactive = $this->createInactiveProduct();
        $cat->products()->attach([$active->id, $inactive->id]);

        $response = $this->getJson('/api/v1/general/categories/'.$cat->slug);
        $response->assertOk();
        $this->assertEquals(1, $response->json('data.products_count'));
    }

    public function test_admin_query_sees_inactive_product()
    {
        $active = $this->createActiveProduct();
        $inactive = $this->createInactiveProduct();

        $this->assertEquals(2, Product::query()->count());
        $this->assertEquals(1, Product::query()->active()->count());
        $this->assertTrue(Product::query()->where('id', $inactive->id)->exists());
        $this->assertFalse(Product::query()->active()->where('id', $inactive->id)->exists());
    }

    public function test_admin_query_sees_inactive_category_and_brand()
    {
        $activeCat = $this->createActiveCategory();
        $inactiveCat = $this->createInactiveCategory();
        $this->assertEquals(2, Category::query()->count());
        $this->assertEquals(1, Category::query()->active()->count());

        $activeBrand = $this->createActiveBrand();
        $inactiveBrand = $this->createInactiveBrand();
        $this->assertEquals(2, Brand::query()->count());
        $this->assertEquals(1, Brand::query()->active()->count());
    }

    public function test_should_be_searchable_filters_inactive()
    {
        $active = $this->createActiveProduct();
        $inactive = $this->createInactiveProduct();
        $this->assertTrue($active->shouldBeSearchable());
        $this->assertFalse($inactive->shouldBeSearchable());
    }

    public function test_product_parent_category_filter_active()
    {
        $parentActive = $this->createActiveCategory(['level'=>0]);
        $parentInactive = $this->createInactiveCategory(['level'=>0]);
        // Product linked via parentCategories logic: product in parent category via whereHas categories
        // We ensure parent inactive not counted via ProductService::getProductForParentCategory logic (pluck active only)
        $ids = Category::query()->active()->whereNull('parent_id')->pluck('id')->toArray();
        $this->assertContains($parentActive->id, $ids);
        $this->assertNotContains($parentInactive->id, $ids);
    }
}
