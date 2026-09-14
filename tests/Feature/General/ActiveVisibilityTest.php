<?php

declare(strict_types=1);

namespace Tests\Feature\General;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Cart;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ActiveVisibilityTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../../Stubs/UniqueTranslationRuleStub.php';
        }
        parent::setUp();
        app()->setLocale('en');
        $this->createAllTestTables();
        Cache::flush();
    }

    private function activeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Active Product '.uniqid(), 'ar' => 'منتج نشط'],
            'slug' => 'active-'.uniqid(),
            'price' => 100,
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'product_type' => 'simple',
        ], $overrides));
    }

    private function inactiveProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Inactive Product '.uniqid(), 'ar' => 'منتج غير نشط'],
            'slug' => 'inactive-'.uniqid(),
            'price' => 100,
            'status' => 0,
            'in_stock' => false,
            'stock_quantity' => 0,
            'reserved_quantity' => 0,
            'product_type' => 'simple',
        ], $overrides));
    }

    // Product listing: active only
    public function test_public_products_lists_only_active(): void
    {
        $active = $this->activeProduct(['slug' => 'vis-active-'.uniqid()]);
        $inactive = $this->inactiveProduct(['slug' => 'vis-inactive-'.uniqid()]);

        $resp = $this->getJson('/api/v1/general/products');
        $resp->assertOk();
        $slugs = collect($resp->json('data.data'))->pluck('slug')->all();
        $this->assertContains($active->slug, $slugs);
        $this->assertNotContains($inactive->slug, $slugs);
    }

    public function test_inactive_out_of_stock_hidden_even_with_publish_status(): void
    {
        $active = $this->activeProduct(['slug' => 'pub-active-'.uniqid(), 'status' => 1, 'in_stock' => true, 'stock_quantity' => 5]);
        $oos = $this->activeProduct(['slug' => 'pub-oos-'.uniqid(), 'status' => 1, 'in_stock' => false, 'stock_quantity' => 0]);

        $resp = $this->getJson('/api/v1/general/products');
        $resp->assertOk();
        $slugs = collect($resp->json('data.data'))->pluck('slug')->all();
        $this->assertContains($active->slug, $slugs);
        $this->assertNotContains($oos->slug, $slugs);
    }

    public function test_product_detail_inactive_returns_404(): void
    {
        $inactive = $this->inactiveProduct(['slug' => 'detail-inactive-'.uniqid()]);
        $resp = $this->getJson('/api/v1/general/products/'.$inactive->slug);
        $resp->assertStatus(404);
    }

    public function test_product_detail_active_returns_200(): void
    {
        $active = $this->activeProduct(['slug' => 'detail-active-'.uniqid()]);
        $resp = $this->getJson('/api/v1/general/products/'.$active->slug);
        $resp->assertOk();
        $this->assertSame($active->slug, $resp->json('data.slug') ?? $resp->json('data.data.slug') ?? null);
    }

    public function test_pagination_does_not_consume_slots_with_inactive(): void
    {
        // Create 3 active, 2 inactive => pagination limit 2 should still give 2 actives per page
        $a1 = $this->activeProduct(['slug' => 'page-a1-'.uniqid()]);
        $i1 = $this->inactiveProduct(['slug' => 'page-i1-'.uniqid()]);
        $a2 = $this->activeProduct(['slug' => 'page-a2-'.uniqid()]);
        $i2 = $this->inactiveProduct(['slug' => 'page-i2-'.uniqid()]);
        $a3 = $this->activeProduct(['slug' => 'page-a3-'.uniqid()]);

        $resp = $this->getJson('/api/v1/general/products?limit=2&order=asc');
        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertSame(3, $data['links']['total']);
        $this->assertCount(2, $data['data']);
        $slugs = collect($data['data'])->pluck('slug')->all();
        $this->assertNotContains($i1->slug, $slugs);
        $this->assertNotContains($i2->slug, $slugs);
    }

    public function test_search_does_not_return_inactive(): void
    {
        $term = 'SearchTerm'.uniqid();
        $active = $this->activeProduct(['slug' => 'search-a-'.uniqid(), 'name' => ['en' => $term.' Active', 'ar' => $term]]);
        $inactive = $this->inactiveProduct(['slug' => 'search-i-'.uniqid(), 'name' => ['en' => $term.' Inactive', 'ar' => $term]]);

        $resp = $this->getJson('/api/v1/general/products?search='.$term);
        $resp->assertOk();
        $slugs = collect($resp->json('data.data'))->pluck('slug')->all();
        $this->assertContains($active->slug, $slugs);
        $this->assertNotContains($inactive->slug, $slugs);
    }

    public function test_category_listing_only_active(): void
    {
        $active = Category::create(['name' => ['en' => 'Active Cat '.uniqid()], 'slug' => 'cat-active-'.uniqid(), 'status' => 1]);
        $inactive = Category::create(['name' => ['en' => 'Inactive Cat '.uniqid()], 'slug' => 'cat-inactive-'.uniqid(), 'status' => 0]);

        $resp = $this->getJson('/api/v1/general/categories');
        $resp->assertOk();
        $slugs = collect($resp->json('data'))->pluck('slug')->all();
        $this->assertContains($active->slug, $slugs);
        $this->assertNotContains($inactive->slug, $slugs);
    }

    public function test_category_detail_nested_products_only_active(): void
    {
        $cat = Category::create(['name' => ['en' => 'Cat Nest '.uniqid()], 'slug' => 'cat-nest-'.uniqid(), 'status' => 1]);
        $active = $this->activeProduct(['slug' => 'cat-nest-a-'.uniqid()]);
        $inactive = $this->inactiveProduct(['slug' => 'cat-nest-i-'.uniqid()]);
        $cat->products()->attach([$active->id, $inactive->id]);

        $resp = $this->getJson('/api/v1/general/categories/'.$cat->slug);
        $resp->assertOk();
        $payload = json_encode($resp->json());
        $this->assertStringContainsString($active->slug, $payload);
        $this->assertStringNotContainsString($inactive->slug, $payload);
        // products_count should count active only
        $count = $resp->json('data.products_count') ?? $resp->json('data.data.products_count') ?? null;
        if ($count !== null) {
            $this->assertSame(1, (int)$count);
        }
    }

    public function test_brand_listing_only_active(): void
    {
        $active = Brand::create(['name' => ['en' => 'Active Brand '.uniqid()], 'slug' => 'brand-active-'.uniqid(), 'status' => 1]);
        $inactive = Brand::create(['name' => ['en' => 'Inactive Brand '.uniqid()], 'slug' => 'brand-inactive-'.uniqid(), 'status' => 0]);

        $resp = $this->getJson('/api/v1/general/brands');
        $resp->assertOk();
        $slugs = collect($resp->json('data'))->pluck('slug')->all();
        $this->assertContains($active->slug, $slugs);
        $this->assertNotContains($inactive->slug, $slugs);
    }

    public function test_brand_detail_nested_products_only_active(): void
    {
        $brand = Brand::create(['name' => ['en' => 'Brand Nest '.uniqid()], 'slug' => 'brand-nest-'.uniqid(), 'status' => 1]);
        $active = $this->activeProduct(['slug' => 'brand-nest-a-'.uniqid()]);
        $inactive = $this->inactiveProduct(['slug' => 'brand-nest-i-'.uniqid()]);
        $brand->products()->attach([$active->id, $inactive->id]);

        $resp = $this->getJson('/api/v1/general/brands/'.$brand->slug);
        $resp->assertOk();
        $payload = json_encode($resp->json());
        $this->assertStringContainsString($active->slug, $payload);
        $this->assertStringNotContainsString($inactive->slug, $payload);
    }

    public function test_banner_detail_nested_products_only_active(): void
    {
        $banner = Banner::create(['title' => ['en' => 'Banner '.uniqid()], 'slug' => 'banner-'.uniqid(), 'status' => 1]);
        $active = $this->activeProduct(['slug' => 'banner-a-'.uniqid()]);
        $inactive = $this->inactiveProduct(['slug' => 'banner-i-'.uniqid()]);
        $banner->products()->attach([$active->id, $inactive->id]);

        $resp = $this->getJson('/api/v1/general/banners/'.$banner->slug);
        $resp->assertOk();
        $payload = json_encode($resp->json());
        $this->assertStringContainsString($active->slug, $payload);
        $this->assertStringNotContainsString($inactive->slug, $payload);
    }

    public function test_slider_detail_nested_products_only_active(): void
    {
        $slider = Slider::create(['title' => ['en' => 'Slider '.uniqid()], 'slug' => 'slider-'.uniqid(), 'status' => 1]);
        $active = $this->activeProduct(['slug' => 'slider-a-'.uniqid()]);
        $inactive = $this->inactiveProduct(['slug' => 'slider-i-'.uniqid()]);
        $slider->products()->attach([$active->id, $inactive->id]);

        $resp = $this->getJson('/api/v1/general/sliders/'.$slider->slug);
        $resp->assertOk();
        $payload = json_encode($resp->json());
        $this->assertStringContainsString($active->slug, $payload);
        $this->assertStringNotContainsString($inactive->slug, $payload);
    }

    public function test_promotion_detail_nested_products_only_active(): void
    {
        $promotion = Promotion::create(['name' => ['en' => 'Promo '.uniqid()], 'slug' => 'promo-'.uniqid(), 'status' => 1, 'start_at' => now()->subDay(), 'end_at' => now()->addDay(), 'type' => 'percentage', 'type_amount' => 'percentage', 'discount' => 10]);
        $active = $this->activeProduct(['slug' => 'promo-a-'.uniqid()]);
        $inactive = $this->inactiveProduct(['slug' => 'promo-i-'.uniqid()]);
        $promotion->products()->attach([$active->id, $inactive->id]);

        $resp = $this->getJson('/api/v1/general/promotions/'.$promotion->slug);
        $resp->assertOk();
        $payload = json_encode($resp->json());
        $this->assertStringContainsString($active->slug, $payload);
        $this->assertStringNotContainsString($inactive->slug, $payload);
    }

    public function test_flash_sale_detail_nested_products_only_active(): void
    {
        $fs = FlashSale::create(['title' => ['en' => 'FS '.uniqid()], 'slug' => 'fs-'.uniqid(), 'status' => true, 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'type' => 'percentage', 'discount' => 10]);
        $active = $this->activeProduct(['slug' => 'fs-a-'.uniqid()]);
        $inactive = $this->inactiveProduct(['slug' => 'fs-i-'.uniqid()]);
        $fs->products()->attach([$active->id, $inactive->id]);

        $resp = $this->getJson('/api/v1/general/flash-sales/'.$fs->slug);
        $resp->assertOk();
        $payload = json_encode($resp->json());
        $this->assertStringContainsString($active->slug, $payload);
        $this->assertStringNotContainsString($inactive->slug, $payload);
    }

    public function test_cannot_add_inactive_product_to_cart(): void
    {
        $user = User::create(['name' => 'Cart User', 'email' => 'cart-'.uniqid().'@example.com', 'password' => bcrypt('password'), 'phone_number' => '010'.rand(10000000,99999999), 'is_active' => true]);
        Sanctum::actingAs($user);
        $inactive = $this->inactiveProduct(['slug' => 'cart-inactive-'.uniqid()]);

        $resp = $this->postJson('/api/cart', ['item' => ['product_id' => $inactive->id, 'quantity' => 1]]);
        // CartRepository throws HttpException 400 when product not found via active scope
        $this->assertTrue(in_array($resp->getStatusCode(), [400, 404, 422]));
        $this->assertFalse(Cart::where('user_id', $user->id)->whereHas('items', fn($q) => $q->where('product_id', $inactive->id))->exists());
    }

    public function test_cannot_review_inactive_product(): void
    {
        $user = User::create(['name' => 'Reviewer', 'email' => 'rev-'.uniqid().'@example.com', 'password' => bcrypt('password'), 'phone_number' => '010'.rand(10000000,99999999), 'is_active' => true]);
        Sanctum::actingAs($user);
        $inactive = $this->inactiveProduct(['slug' => 'rev-inactive-'.uniqid()]);

        $resp = $this->postJson('/api/v1/general/products/'.$inactive->id.'/reviews', ['product_id' => $inactive->id, 'rating' => 5, 'comment' => 'test']);
        $resp->assertStatus(404);
    }

    public function test_admin_can_still_see_inactive_via_direct_query(): void
    {
        $active = $this->activeProduct(['slug' => 'admin-a-'.uniqid()]);
        $inactive = $this->inactiveProduct(['slug' => 'admin-i-'.uniqid()]);
        $allCount = Product::query()->count();
        $activeCount = Product::query()->active()->count();
        $this->assertGreaterThan($activeCount, $allCount);
        $this->assertNotNull(Product::query()->find($inactive->id));
        $this->assertNull(Product::query()->active()->find($inactive->id));
        $this->assertNotNull(Category::withTrashed()->where('slug', $inactive->slug)->first() ?? Product::query()->find($inactive->id));
    }

    public function test_product_is_not_searchable_when_inactive(): void
    {
        $inactive = $this->inactiveProduct(['slug' => 'searchable-i-'.uniqid()]);
        $active = $this->activeProduct(['slug' => 'searchable-a-'.uniqid()]);
        $this->assertFalse($inactive->shouldBeSearchable());
        $this->assertTrue($active->shouldBeSearchable());
    }
}
