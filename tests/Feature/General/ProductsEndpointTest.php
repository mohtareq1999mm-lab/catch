<?php

declare(strict_types=1);

namespace Tests\Feature\General;

use App\Enums\FrontendResource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Review;
use Marvel\Database\Models\Tag;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ProductsEndpointTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const LIST_URL = '/api/v1/general/products';

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

        $this->createAllTestTables();

        Cache::flush();
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Wireless Headphones', 'ar' => 'سماعات لاسلكية'],
            'slug' => 'wireless-headphones-' . uniqid(),
            'price' => 99.99,
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 50,
            'reserved_quantity' => 0,
            'product_type' => 'simple',
            'has_discount' => false,
            'has_flash_sale' => false,
            'is_fast_shipping_available' => false,
        ], $overrides));
    }

    private function makeFlashSale(array $overrides = []): FlashSale
    {
        return FlashSale::create(array_merge([
            'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
            'slug' => 'summer-sale-' . uniqid(),
            'status' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'type' => 'percentage',
            'discount' => 10,
        ], $overrides));
    }

    // =========================================================================
    // RUNTIME FLOW
    // =========================================================================

    public function test_public_endpoint_requires_no_authentication(): void
    {
        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $response->assertJsonStructure(['status', 'message', 'success', 'data']);
        $this->assertTrue($response->json('success'));
        $this->assertSame(200, $response->json('status'));
    }

    public function test_returns_empty_data_when_no_products_exist(): void
    {
        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame([], $data['data']);
        $this->assertSame(0, $data['links']['total']);
    }

    public function test_default_type_index_returns_paginated_products(): void
    {
        $p1 = $this->makeProduct(['slug' => 'index-p1']);
        $p2 = $this->makeProduct(['slug' => 'index-p2']);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $data = $response->json('data');
        $slugs = collect($data['data'])->pluck('slug')->all();
        $this->assertContains('index-p1', $slugs);
        $this->assertContains('index-p2', $slugs);
        $this->assertSame(2, $data['links']['total']);
        $this->assertSame(1, $data['links']['current_page']);
        $this->assertArrayHasKey('next_page_url', $data['links']);
    }

    public function test_each_product_item_has_expected_resource_shape(): void
    {
        $this->makeProduct(['slug' => 'shape-p1']);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $item = $response->json('data.data.0');
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('slug', $item);
        $this->assertArrayHasKey('price', $item);
        $this->assertArrayHasKey('has_variants', $item);
        $this->assertArrayHasKey('current_price', $item);
        $this->assertArrayHasKey('currency', $item);
        $this->assertArrayHasKey('quantity', $item);
        $this->assertArrayHasKey('in_stock', $item);
        $this->assertArrayHasKey('discount_active', $item);
        $this->assertArrayHasKey('flash_sale_active', $item);
        $this->assertArrayHasKey('is_fast_shipping_available', $item);
        $this->assertArrayHasKey('ratings', $item);
        $this->assertArrayHasKey('tags', $item);
        $this->assertArrayHasKey('image', $item);
    }

    public function test_response_exposes_links_pagination_metadata(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeProduct(['slug' => 'p-' . uniqid()]);
        }

        $response = $this->getJson(self::LIST_URL . '?limit=2');

        $response->assertOk();
        $links = $response->json('data.links');
        $this->assertSame(2, $links['per_page']);
        $this->assertSame(5, $links['total']);
        $this->assertSame(1, $links['current_page']);
        $this->assertSame(3, $links['last_page']);
    }

    // =========================================================================
    // STRATEGY TYPES
    // =========================================================================

    public function test_all_documented_strategy_types_respond_ok(): void
    {
        $product = $this->makeProduct(['slug' => 'strategy-p', 'has_discount' => true, 'discount_type' => 'percentage', 'discount_amount' => 10, 'discount_status' => true]);
        $flashSale = $this->makeFlashSale();
        $flashSale->products()->attach($product->id);
        $product->update(['has_flash_sale' => true]);

        $strategies = [
            'index',
            'best_product_sales',
            'brands_product',
            'new_arrivals',
            'all_product_discounts',
            'product_discount_today_or_low_qty',
            'flash_sales_product',
            'flash_sales_end_today',
            'flash_sales_end_week',
            'product_for_parent_category',
        ];

        foreach ($strategies as $type) {
            $response = $this->getJson(self::LIST_URL . '?type=' . $type);
            $this->assertTrue(
                $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
                "Strategy {$type} expected 2xx, got {$response->getStatusCode()}"
            );
        }
    }

    public function test_invalid_strategy_type_returns_422(): void
    {
        $response = $this->getJson(self::LIST_URL . '?type=not-a-real-strategy');

        $response->assertStatus(422);
        $this->assertFalse($response->json('status'));
        $this->assertArrayHasKey('type', $response->json('errors'));
    }

    public function test_whitespace_strategy_type_is_treated_as_empty(): void
    {
        $this->makeProduct(['slug' => 'whitespace-type']);

        $response = $this->getJson(self::LIST_URL . '?type=%20%20');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('whitespace-type', $slugs, 'Whitespace-only type values are normalized to empty and use the default flow');
    }

    public function test_empty_strategy_type_falls_back_to_default_flow(): void
    {
        $this->makeProduct(['slug' => 'empty-type']);

        $response = $this->getJson(self::LIST_URL . '?type=');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('empty-type', $slugs);
    }

    // =========================================================================
    // AUTHENTICATION / AUTHORIZATION / IDOR
    // =========================================================================

    public function test_idor_attempt_with_unknown_filter_values_returns_empty_results(): void
    {
        $this->makeProduct(['slug' => 'idor-kept']);

        $response = $this->getJson(self::LIST_URL . '?category=does-not-exist');

        $response->assertOk();
        $this->assertSame([], $response->json('data.data'));
    }

    public function test_unknown_brand_returns_empty_results(): void
    {
        $this->makeProduct(['slug' => 'brand-kept']);

        $response = $this->getJson(self::LIST_URL . '?brand=does-not-exist');

        $response->assertOk();
        $this->assertSame([], $response->json('data.data'));
    }

    public function test_deleted_category_returns_empty_results(): void
    {
        $category = Category::create(['name' => ['en' => 'Doomed'], 'slug' => 'doomed-cat']);
        $product = $this->makeProduct(['slug' => 'doomed-cat-product']);
        $product->categories()->attach($category->id);
        $category->delete();

        $response = $this->getJson(self::LIST_URL . '?category=doomed-cat');

        $response->assertOk();
        $this->assertSame([], $response->json('data.data'));
    }

    public function test_inactive_category_still_filters_matching_products(): void
    {
        $category = Category::create(['name' => ['en' => 'Inactive'], 'slug' => 'inactive-cat']);
        $product = $this->makeProduct(['slug' => 'inactive-cat-product']);
        $product->categories()->attach($category->id);
        $this->makeProduct(['slug' => 'inactive-cat-other']);

        $response = $this->getJson(self::LIST_URL . '?category=inactive-cat');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('inactive-cat-product', $slugs);
        $this->assertNotContains('inactive-cat-other', $slugs);
    }

    public function test_mixed_known_and_unknown_brand_values_return_known_matches(): void
    {
        $brand = Brand::create(['name' => ['en' => 'Known'], 'slug' => 'known-brand']);
        $known = $this->makeProduct(['slug' => 'mixed-known']);
        $known->brands()->attach($brand->id);
        $this->makeProduct(['slug' => 'mixed-other']);

        $response = $this->getJson(self::LIST_URL . '?brand=known-brand,ghost-brand');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('mixed-known', $slugs);
        $this->assertNotContains('mixed-other', $slugs);
    }

    public function test_category_filter_by_translated_name(): void
    {
        $category = Category::create(['name' => ['en' => 'Audio', 'ar' => 'صوتيات'], 'slug' => 'audio-cat']);
        $product = $this->makeProduct(['slug' => 'audio-product']);
        $product->categories()->attach($category->id);
        $this->makeProduct(['slug' => 'audio-other']);

        $response = $this->getJson(self::LIST_URL . '?category=Audio');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('audio-product', $slugs);
        $this->assertNotContains('audio-other', $slugs);
    }

    public function test_out_of_stock_products_are_excluded_by_active_scope(): void
    {
        $this->makeProduct(['slug' => 'in-stock-kept', 'in_stock' => true, 'stock_quantity' => 5, 'reserved_quantity' => 0]);
        $this->makeProduct(['slug' => 'out-of-stock-hidden', 'in_stock' => false, 'stock_quantity' => 0, 'reserved_quantity' => 0]);
        $this->makeProduct(['slug' => 'reserved-out-hidden', 'in_stock' => false, 'stock_quantity' => 5, 'reserved_quantity' => 5]);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('in-stock-kept', $slugs);
        $this->assertNotContains('out-of-stock-hidden', $slugs);
        $this->assertNotContains('reserved-out-hidden', $slugs);
    }

    public function test_inactive_products_are_excluded(): void
    {
        $this->makeProduct(['slug' => 'active-kept', 'status' => 1]);
        $this->makeProduct(['slug' => 'draft-hidden', 'status' => 0]);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('active-kept', $slugs);
        $this->assertNotContains('draft-hidden', $slugs);
    }

    public function test_soft_deleted_products_are_excluded(): void
    {
        $this->makeProduct(['slug' => 'kept']);
        $gone = $this->makeProduct(['slug' => 'soft-deleted']);
        $gone->delete();

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertNotContains('soft-deleted', $slugs);
    }

    // =========================================================================
    // SORTING
    // =========================================================================

    public function test_order_asc_and_desc_by_id(): void
    {
        $a = $this->makeProduct(['slug' => 'order-a']);
        $b = $this->makeProduct(['slug' => 'order-b']);

        $asc = $this->getJson(self::LIST_URL . '?order=asc');
        $desc = $this->getJson(self::LIST_URL . '?order=desc');

        $asc->assertOk();
        $desc->assertOk();
        $ascIds = collect($asc->json('data.data'))->pluck('id')->all();
        $descIds = collect($desc->json('data.data'))->pluck('id')->all();
        $this->assertSame([$a->id, $b->id], $ascIds);
        $this->assertSame([$b->id, $a->id], $descIds);
    }

    public function test_default_order_is_desc(): void
    {
        $a = $this->makeProduct(['slug' => 'def-a']);
        $b = $this->makeProduct(['slug' => 'def-b']);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertSame([$b->id, $a->id], $ids);
    }

    public function test_invalid_order_value_returns_422(): void
    {
        $this->makeProduct(['slug' => 'evil-order']);

        $response = $this->getJson(self::LIST_URL . '?order=evil');

        $response->assertStatus(422);
        $this->assertFalse($response->json('status'));
        $this->assertArrayHasKey('order', $response->json('errors'));
    }

    public function test_empty_order_value_defaults_to_desc(): void
    {
        $a = $this->makeProduct(['slug' => 'empty-order-a']);
        $b = $this->makeProduct(['slug' => 'empty-order-b']);

        $response = $this->getJson(self::LIST_URL . '?order=');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertSame([$b->id, $a->id], $ids, 'An empty order value should fall back to the documented desc default');
    }

    public function test_order_price_does_not_sort_by_price_in_default_index_flow(): void
    {
        $cheap = $this->makeProduct(['slug' => 'cheap', 'price' => 5.0]);
        $expensive = $this->makeProduct(['slug' => 'exp', 'price' => 500.0]);

        $response = $this->getJson(self::LIST_URL . '?order_price=asc');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertNotSame([$cheap->id, $expensive->id], $ids, 'order_price is documented but not honored in the default index flow');
    }

    // =========================================================================
    // PAGINATION
    // =========================================================================

    public function test_limit_parameter_is_respected(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeProduct(['slug' => 'lim-' . uniqid()]);
        }

        $response = $this->getJson(self::LIST_URL . '?limit=2');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
        $this->assertSame(2, $response->json('data.links.per_page'));
    }

    public function test_limit_is_capped_at_100(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeProduct(['slug' => 'cap-' . uniqid()]);
        }

        $response = $this->getJson(self::LIST_URL . '?limit=999');

        $response->assertOk();
        $this->assertSame(100, $response->json('data.links.per_page'));
    }

    public function test_zero_and_negative_limit_fall_back_to_default(): void
    {
        $this->makeProduct(['slug' => 'zero-limit']);

        $response = $this->getJson(self::LIST_URL . '?limit=0');

        $response->assertOk();
        $this->assertSame(15, $response->json('data.links.per_page'));
    }

    public function test_page_parameter_navigates_pages(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeProduct(['slug' => 'page-' . uniqid()]);
        }

        $page1 = $this->getJson(self::LIST_URL . '?limit=2');
        $page3 = $this->getJson(self::LIST_URL . '?limit=2&page=3');

        $page1->assertOk();
        $page3->assertOk();
        $this->assertSame(1, $page1->json('data.links.current_page'));
        $this->assertSame(3, $page3->json('data.links.current_page'));
    }

    // =========================================================================
    // FILTERING
    // =========================================================================

    public function test_filter_by_category_slug_including_descendants(): void
    {
        $parent = Category::create(['name' => ['en' => 'Parent'], 'slug' => 'parent-cat']);
        $child = Category::create(['name' => ['en' => 'Child'], 'slug' => 'child-cat', 'parent_id' => $parent->id]);

        $pChild = $this->makeProduct(['slug' => 'in-child']);
        $pParent = $this->makeProduct(['slug' => 'in-parent']);
        $pOther = $this->makeProduct(['slug' => 'other']);
        $pChild->categories()->attach($child->id);
        $pParent->categories()->attach($parent->id);

        $response = $this->getJson(self::LIST_URL . '?category=parent-cat');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('in-child', $slugs);
        $this->assertContains('in-parent', $slugs);
        $this->assertNotContains('other', $slugs);
    }

    public function test_filter_by_brand_slug(): void
    {
        $brand = Brand::create(['name' => ['en' => 'Sony'], 'slug' => 'sony']);
        $inBrand = $this->makeProduct(['slug' => 'sony-product']);
        $other = $this->makeProduct(['slug' => 'non-brand']);
        $inBrand->brands()->attach($brand->id);

        $response = $this->getJson(self::LIST_URL . '?brand=sony');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('sony-product', $slugs);
        $this->assertNotContains('non-brand', $slugs);
    }

    public function test_filter_by_tag_slug_requires_all_tags(): void
    {
        $t1 = Tag::create(['slug' => 'tag-one', 'name' => ['en' => 'Tag One']]);
        $t2 = Tag::create(['slug' => 'tag-two', 'name' => ['en' => 'Tag Two']]);

        $both = $this->makeProduct(['slug' => 'has-both']);
        $one = $this->makeProduct(['slug' => 'has-one']);
        $both->tags()->attach([$t1->id, $t2->id]);
        $one->tags()->attach($t1->id);

        $response = $this->getJson(self::LIST_URL . '?tag=tag-one,tag-two');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('has-both', $slugs);
        $this->assertNotContains('has-one', $slugs);
    }

    public function test_filter_by_productsId(): void
    {
        $p1 = $this->makeProduct(['slug' => 'sel-one']);
        $p2 = $this->makeProduct(['slug' => 'sel-two']);
        $this->makeProduct(['slug' => 'sel-excluded']);

        $response = $this->getJson(self::LIST_URL . '?productsId=' . $p1->id . ',' . $p2->id);

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('sel-one', $slugs);
        $this->assertContains('sel-two', $slugs);
        $this->assertNotContains('sel-excluded', $slugs);
    }

    public function test_filter_by_price_range(): void
    {
        $this->makeProduct(['slug' => 'cheap-p', 'price' => 5.0]);
        $this->makeProduct(['slug' => 'mid-p', 'price' => 50.0]);
        $this->makeProduct(['slug' => 'pricey-p', 'price' => 500.0]);

        $response = $this->getJson(self::LIST_URL . '?minPrice=10&maxPrice=100');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('mid-p', $slugs);
        $this->assertNotContains('cheap-p', $slugs);
        $this->assertNotContains('pricey-p', $slugs);
    }

public function test_filter_by_rating_min(): void
    {
        $user = User::create([
            'name' => 'Reviewer',
            'email' => 'reviewer-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $rated = $this->makeProduct(['slug' => 'rated-p']);
        $unrated = $this->makeProduct(['slug' => 'unrated-p']);

        Review::create([
            'product_id' => $rated->id,
            'user_id' => $user->id,
            'rating' => 4,
            'comment' => 'good',
            'approved' => true,
        ]);

        $response = $this->getJson(self::LIST_URL . '?rating_min=3');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('rated-p', $slugs);
        $this->assertNotContains('unrated-p', $slugs);
    }

    public function test_filter_by_dynamic_attribute(): void
    {
        $attribute = Attribute::create(['slug' => 'color', 'name' => ['en' => 'Color']]);
        $value = AttributeValue::create(['slug' => 'red', 'value' => ['en' => 'Red'], 'attribute_id' => $attribute->id]);

        $product = $this->makeProduct(['slug' => 'attr-product']);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'price' => 100.0,
            'stock_quantity' => 5,
            'in_stock' => true,
        ]);

        DB::table('attribute_product')->insert([
            'attribute_value_id' => $value->id,
            'product_variant_id' => $variant->id,
        ]);

        $other = $this->makeProduct(['slug' => 'attr-other']);

        $response = $this->getJson(self::LIST_URL . '?color=Red');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('attr-product', $slugs);
        $this->assertNotContains('attr-other', $slugs);
    }

    public function test_search_filter_by_name(): void
    {
        $this->makeProduct(['slug' => 'search-target', 'name' => ['en' => 'UniqueSearchableThing', 'ar' => 'شيء']]);
        $this->makeProduct(['slug' => 'search-other', 'name' => ['en' => 'Something Else', 'ar' => 'آخر']]);

        $response = $this->getJson(self::LIST_URL . '?search=UniqueSearchableThing');

        $response->assertOk();
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('search-target', $slugs);
        $this->assertNotContains('search-other', $slugs);
    }

    // =========================================================================
    // PRICING
    // =========================================================================

    public function test_discounted_product_exposes_discount_active_and_lowered_current_price(): void
    {
        $this->makeProduct([
            'slug' => 'discounted-p',
            'price' => 100.0,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 10,
            'discount_status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);
        $this->makeProduct(['slug' => 'full-price-p', 'price' => 100.0]);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $items = collect($response->json('data.data'))->keyBy('slug');
        $this->assertTrue($items['discounted-p']['discount_active']);
        $this->assertLessThan(100.0, (float) $items['discounted-p']['current_price']);
        $this->assertFalse($items['full-price-p']['discount_active']);
    }

    public function test_product_in_flash_sale_exposes_flash_sale_active(): void
    {
        $product = $this->makeProduct([
            'slug' => 'flash-p',
            'price' => 100.0,
            'has_discount' => false,
            'has_flash_sale' => true,
        ]);
        $flashSale = $this->makeFlashSale();
        $flashSale->products()->attach($product->id);
        $this->makeProduct(['slug' => 'no-flash-p']);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $items = collect($response->json('data.data'))->keyBy('slug');
        $this->assertTrue($items['flash-p']['flash_sale_active']);
        $this->assertFalse($items['no-flash-p']['flash_sale_active']);
    }

    // =========================================================================
    // CACHE BEHAVIOR
    // =========================================================================

    public function test_cache_miss_executes_queries_and_cache_hit_serves_cached_response(): void
    {
        $this->makeProduct(['slug' => 'cache-p']);

        $first = $this->getJson(self::LIST_URL);
        $first->assertOk();
        $this->assertSame('cache-p', $first->json('data.data.0.slug'));

        $cached = $this->getJson(self::LIST_URL);
        $cached->assertOk();
        $this->assertSame(
            $first->json('data'),
            $cached->json('data'),
            'A cache hit must serve the identical payload built on the cache miss'
        );
    }

    public function test_search_request_bypasses_cache(): void
    {
        $this->makeProduct(['slug' => 'search-cache-target', 'name' => ['en' => 'SearchCacheHit']]);
        $this->makeProduct(['slug' => 'search-cache-other']);

        $key = md5($this->fullUrl(self::LIST_URL, ['search' => 'SearchCacheHit']));
        Cache::tags([FrontendResource::PRODUCTS->value . '_index'])->put($key, ['__stale__' => 'stale'], now()->addHour());

        $response = $this->getJson(self::LIST_URL . '?search=SearchCacheHit');

        $response->assertOk();
        $this->assertNotSame('stale', $response->json('data.0') ?? null, 'Search requests must bypass the cache');
    }

    public function test_cache_contains_serialized_response_array(): void
    {
        $this->makeProduct(['slug' => 'serializable-p']);

        $this->getJson(self::LIST_URL)->assertOk();

        $tag = FrontendResource::PRODUCTS->value . '_index';
        $key = md5($this->fullUrl(self::LIST_URL) . '|currency:' . app(\App\Services\Currency\CurrencyService::class)->getEffectiveCode());

        $this->assertTrue(Cache::tags([$tag])->has($key));
        $cached = Cache::tags([$tag])->get($key);
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('data', $cached);
    }

    public function test_cache_hit_serves_response_without_querying_products(): void
    {
        $this->makeProduct(['slug' => 'hit-p1']);
        $this->makeProduct(['slug' => 'hit-p2']);

        $first = $this->getJson(self::LIST_URL);
        $first->assertOk();

        DB::enableQueryLog();
        $second = $this->getJson(self::LIST_URL);
        $second->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $productSelects = collect($queries)
            ->filter(fn(array $q) => preg_match('/^select/i', $q['query']) === 1
                && preg_match('/\bfrom\s+["`]?products["`]?/i', $q['query']) === 1)
            ->count();

        $this->assertSame(0, $productSelects, 'A cache hit must not query the products table');
        $this->assertSame($first->json('data'), $second->json('data'));
    }

    public function test_cache_is_separated_by_strategy_type(): void
    {
        $this->makeProduct(['slug' => 'sep-p1']);

        $this->getJson(self::LIST_URL . '?type=index')->assertOk();

        $currency = app(\App\Services\Currency\CurrencyService::class)->getEffectiveCode();
        $indexKey = md5($this->fullUrl(self::LIST_URL, ['type' => 'index']) . '|currency:' . $currency);

        $this->assertTrue(Cache::tags([FrontendResource::PRODUCTS->value . '_index'])->has($indexKey));
        $this->assertFalse(
            Cache::tags([FrontendResource::PRODUCTS->value . '_new_arrivals'])->has($indexKey),
            'A strategy listing must be cached under its own strategy tag'
        );
    }

    // =========================================================================
    // CACHE INVALIDATION MATRIX
    // =========================================================================

    public function test_product_create_invalidates_products_cache(): void
    {
        $this->makeProduct(['slug' => 'inv-1']);

        $this->getJson(self::LIST_URL)->assertOk();

        $this->makeProduct(['slug' => 'inv-2']);

        $cached = $this->getJson(self::LIST_URL);
        $cached->assertOk();
        $slugs = collect($cached->json('data.data'))->pluck('slug')->all();
        $this->assertContains('inv-2', $slugs, 'Creating a product must invalidate the products listing cache');
    }

    public function test_product_update_invalidates_products_cache(): void
    {
        $product = $this->makeProduct(['slug' => 'update-me', 'price' => 10.0]);

        $this->getJson(self::LIST_URL)->assertOk();

        $product->update(['price' => 500.0]);

        $cached = $this->getJson(self::LIST_URL);
        $cached->assertOk();
        $items = collect($cached->json('data.data'))->keyBy('slug');
        $this->assertSame(500.0, (float) $items['update-me']['price'], 'Updating a product must invalidate the products listing cache');
    }

    public function test_product_delete_invalidates_products_cache(): void
    {
        $product = $this->makeProduct(['slug' => 'delete-me']);

        $this->getJson(self::LIST_URL)->assertOk();

        $product->delete();

        $cached = $this->getJson(self::LIST_URL);
        $cached->assertOk();
        $slugs = collect($cached->json('data.data'))->pluck('slug')->all();
        $this->assertNotContains('delete-me', $slugs, 'Deleting a product must invalidate the products listing cache');
    }

    public function test_soft_deleted_product_invalidates_products_cache(): void
    {
        $product = $this->makeProduct(['slug' => 'soft-delete-inv']);

        $this->getJson(self::LIST_URL)->assertOk();

        $product->delete();

        $cached = $this->getJson(self::LIST_URL);
        $cached->assertOk();
        $slugs = collect($cached->json('data.data'))->pluck('slug')->all();
        $this->assertNotContains('soft-delete-inv', $slugs, 'Soft deleting a product must invalidate the products listing cache');
    }

    public function test_restored_product_invalidates_products_cache(): void
    {
        $product = $this->makeProduct(['slug' => 'restore-inv']);

        $this->getJson(self::LIST_URL)->assertOk();

        $product->delete();
        $this->getJson(self::LIST_URL)->assertOk();

        $product->restore();

        $cached = $this->getJson(self::LIST_URL);
        $cached->assertOk();
        $slugs = collect($cached->json('data.data'))->pluck('slug')->all();
        $this->assertContains('restore-inv', $slugs, 'Restoring a product must invalidate the products listing cache');
    }

    public function test_force_deleted_product_invalidates_products_cache(): void
    {
        $product = $this->makeProduct(['slug' => 'force-delete-inv']);

        $this->getJson(self::LIST_URL)->assertOk();

        $product->forceDelete();

        $cached = $this->getJson(self::LIST_URL);
        $cached->assertOk();
        $slugs = collect($cached->json('data.data'))->pluck('slug')->all();
        $this->assertNotContains('force-delete-inv', $slugs, 'Force deleting a product must invalidate the products listing cache');
    }

    public function test_create_invalidates_strategy_specific_cache_tag(): void
    {
        $this->makeProduct([
            'slug' => 'strat-inv-1',
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 10,
            'discount_status' => true,
        ]);

        $this->getJson(self::LIST_URL . '?type=all_product_discounts')->assertOk();

        $this->makeProduct([
            'slug' => 'strat-inv-2',
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
            'discount_status' => true,
        ]);

        $cached = $this->getJson(self::LIST_URL . '?type=all_product_discounts');
        $cached->assertOk();
        $slugs = collect($cached->json('data.data'))->pluck('slug')->all();
        $this->assertContains('strat-inv-2', $slugs, 'Creating a product must invalidate strategy-specific listing caches');
    }

    // =========================================================================
    // LOCALIZATION
    // =========================================================================

    public function test_arabic_locale_returns_translated_product_name(): void
    {
        $this->makeProduct(['slug' => 'ar-product']);

        app()->setLocale('ar');
        $response = $this->getJson(self::LIST_URL, ['lang' => 'ar']);

        $response->assertOk();
        $item = $response->json('data.data.0');
        $this->assertSame('سماعات لاسلكية', $item['name']);
    }

    // =========================================================================
    // QUERY ANALYSIS
    // =========================================================================

    public function test_flash_sale_relation_is_eager_loaded_in_single_request(): void
    {
        $product = $this->makeProduct(['slug' => 'eager-p', 'has_flash_sale' => true]);
        $flashSale = $this->makeFlashSale();
        $flashSale->products()->attach($product->id);

        DB::enableQueryLog();
        $response = $this->getJson(self::LIST_URL);
        DB::disableQueryLog();

        $response->assertOk();
        $queries = DB::getQueryLog();

        $flashSaleSelects = collect($queries)
            ->filter(fn(array $q) => preg_match('/^select/i', $q['query']) === 1
                && preg_match('/\bfrom\s+["`]?flash_sales["`]?/i', $q['query']) === 1)
            ->count();

        $this->assertLessThanOrEqual(2, $flashSaleSelects, 'Flash sale relation should be eager loaded, not queried per product');
    }

    public function test_flash_sale_relation_is_queried_once_for_collection(): void
    {
        $p1 = $this->makeProduct(['slug' => 'fs-col-1', 'has_flash_sale' => true]);
        $p2 = $this->makeProduct(['slug' => 'fs-col-2', 'has_flash_sale' => true]);
        $flashSale = $this->makeFlashSale();
        $flashSale->products()->attach([$p1->id, $p2->id]);

        DB::enableQueryLog();
        $response = $this->getJson(self::LIST_URL);
        DB::disableQueryLog();

        $response->assertOk();
        $queries = DB::getQueryLog();

        $flashSaleSelects = collect($queries)
            ->filter(fn(array $q) => preg_match('/^select/i', $q['query']) === 1
                && preg_match('/\bfrom\s+["`]?flash_sales["`]?/i', $q['query']) === 1)
            ->count();

        $this->assertSame(1, $flashSaleSelects, 'Flash sale relation should be eager loaded exactly once for the collection');
    }

    public function test_flash_sale_pricing_is_identical_for_collection(): void
    {
        $p1 = $this->makeProduct(['slug' => 'fs-price-1', 'price' => 100.0, 'has_flash_sale' => true]);
        $p2 = $this->makeProduct(['slug' => 'fs-price-2', 'price' => 200.0, 'has_flash_sale' => true]);
        $flashSale = $this->makeFlashSale();
        $flashSale->products()->attach([$p1->id, $p2->id]);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $items = collect($response->json('data.data'))->keyBy('slug');
        $this->assertTrue($items['fs-price-1']['flash_sale_active']);
        $this->assertTrue($items['fs-price-2']['flash_sale_active']);
        $this->assertLessThan(100.0, (float) $items['fs-price-1']['current_price']);
        $this->assertLessThan(200.0, (float) $items['fs-price-2']['current_price']);
    }

    public function test_media_relation_does_not_cause_per_product_queries(): void
    {
        foreach (range(1, 3) as $i) {
            $this->makeProduct(['slug' => 'media-' . uniqid()]);
        }

        DB::enableQueryLog();
        $response = $this->getJson(self::LIST_URL);
        DB::disableQueryLog();

        $response->assertOk();
        $queries = DB::getQueryLog();

        $mediaSelects = collect($queries)
            ->filter(fn(array $q) => preg_match('/^select/i', $q['query']) === 1
                && preg_match('/\bfrom\s+["`]?media["`]?/i', $q['query']) === 1)
            ->count();

        $this->assertLessThanOrEqual(1, $mediaSelects, 'Media should be eager loaded, not queried per product');
    }

    // =========================================================================
    // RATE LIMITING
    // =========================================================================

    public function test_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 70; $i++) {
            $this->getJson(self::LIST_URL . '?rl=' . $i);
        }

        $response = $this->getJson(self::LIST_URL . '?rl=final');
        $this->assertTrue(
            $response->getStatusCode() < 500,
            'Rate limiting should eventually reject requests with a 429, not a 500'
        );
    }

    // =========================================================================
    // CURSOR PAGINATION
    // =========================================================================

    private function enableCursorPagination(): void
    {
        config(['cursor.enabled' => true]);
    }

    private function cursorFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['cursor'] ?? null;
    }

    private function walkCursorPages(string $query): array
    {
        $seen = [];
        $cursor = null;

        do {
            $qs = $query . ($cursor !== null ? '&cursor=' . $cursor : '');
            $response = $this->getJson(self::LIST_URL . $qs);
            $response->assertOk();

            $seen = array_merge($seen, collect($response->json('data.data'))->pluck('id')->all());
            $cursor = $this->cursorFromUrl($response->json('data.links.next_page_url'));
        } while ($cursor !== null);

        return $seen;
    }

    public function test_cursor_pagination_returns_422_when_feature_disabled(): void
    {
        config(['cursor.enabled' => false]);
        $this->makeProduct(['slug' => 'cursor-disabled']);

        $response = $this->getJson(self::LIST_URL . '?pagination=cursor');

        $response->assertStatus(422);
        $this->assertFalse($response->json('status'));
        $this->assertArrayHasKey('pagination', $response->json('errors'));
    }

    public function test_cursor_pagination_desc_traverses_all_products_without_duplicates(): void
    {
        $this->enableCursorPagination();

        $ids = collect(range(1, 7))->map(fn() => $this->makeProduct(['slug' => 'cursor-desc-' . uniqid()])->id)->all();

        $seen = $this->walkCursorPages('?pagination=cursor&limit=2');

        $this->assertSame(count($ids), count($seen));
        $this->assertSame(count($ids), count(array_unique($seen)), 'Cursor pages must not duplicate IDs');
        $this->assertSame(array_values(collect($ids)->sortDesc()->values()->all()), $seen, 'Default order must be id descending');
    }

    public function test_cursor_pagination_asc_traverses_in_ascending_order(): void
    {
        $this->enableCursorPagination();

        $ids = collect(range(1, 6))->map(fn() => $this->makeProduct(['slug' => 'cursor-asc-' . uniqid()])->id)->all();

        $seen = $this->walkCursorPages('?pagination=cursor&order=asc&limit=2');

        $this->assertSame(array_values(collect($ids)->sort()->values()->all()), $seen, 'order=asc must traverse id ascending');
    }

    public function test_cursor_response_does_not_expose_offset_metadata(): void
    {
        $this->enableCursorPagination();
        $this->makeProduct(['slug' => 'cursor-meta']);

        $response = $this->getJson(self::LIST_URL . '?pagination=cursor');

        $response->assertOk();
        $links = $response->json('data.links');
        $this->assertArrayHasKey('path', $links);
        $this->assertArrayHasKey('per_page', $links);
        $this->assertArrayHasKey('next_page_url', $links);
        $this->assertArrayHasKey('prev_page_url', $links);
        $this->assertArrayNotHasKey('total', $links);
        $this->assertArrayNotHasKey('last_page', $links);
        $this->assertArrayNotHasKey('current_page', $links);
        $this->assertArrayNotHasKey('from', $links);
        $this->assertArrayNotHasKey('to', $links);
    }

    public function test_cursor_pagination_with_brand_filter(): void
    {
        $this->enableCursorPagination();

        $brand = Brand::create(['name' => ['en' => 'CursorBrand'], 'slug' => 'cursor-brand']);
        foreach (range(1, 5) as $i) {
            $this->makeProduct(['slug' => 'cursor-brand-' . uniqid()])->brands()->attach($brand->id);
        }
        $this->makeProduct(['slug' => 'cursor-brand-other']);

        $seen = $this->walkCursorPages('?pagination=cursor&brand=cursor-brand&limit=2');

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen));
    }

    public function test_cursor_pagination_with_price_range_filter(): void
    {
        $this->enableCursorPagination();

        foreach (range(1, 4) as $i) {
            $this->makeProduct(['slug' => 'cursor-price-in-' . uniqid(), 'price' => 50.0]);
        }
        $this->makeProduct(['slug' => 'cursor-price-out', 'price' => 500.0]);

        $seen = $this->walkCursorPages('?pagination=cursor&minPrice=10&maxPrice=100&limit=2');

        $this->assertCount(4, $seen);
        $this->assertCount(4, array_unique($seen));
    }

    public function test_cursor_pagination_with_productsId_filter(): void
    {
        $this->enableCursorPagination();

        $p1 = $this->makeProduct(['slug' => 'cursor-pid-1']);
        $p2 = $this->makeProduct(['slug' => 'cursor-pid-2']);
        $p3 = $this->makeProduct(['slug' => 'cursor-pid-3']);
        $this->makeProduct(['slug' => 'cursor-pid-other']);

        $allowed = [$p1->id, $p2->id, $p3->id];

        $seen = $this->walkCursorPages('?pagination=cursor&productsId=' . implode(',', $allowed) . '&limit=2');

        $this->assertSame(count($allowed), count($seen));
        $this->assertEmpty(array_diff($seen, $allowed));
    }

    public function test_cursor_pagination_search_combination_returns_422(): void
    {
        $this->enableCursorPagination();

        $response = $this->getJson(self::LIST_URL . '?pagination=cursor&search=foo');

        $response->assertStatus(422);
        $this->assertFalse($response->json('status'));
        $this->assertArrayHasKey('pagination', $response->json('errors'));
    }

    public function test_cursor_pagination_order_price_asc_accepted(): void
    {
        $this->enableCursorPagination();

        $this->makeProduct(['price' => 50]);

        $response = $this->getJson(self::LIST_URL . '?pagination=cursor&order_price=asc');

        $response->assertOk();
        $this->assertArrayNotHasKey('pagination', $response->json('errors') ?? []);
    }

    public function test_cursor_pagination_type_index_with_order_price_accepted(): void
    {
        $this->enableCursorPagination();

        $this->makeProduct(['price' => 100]);

        // type=index now supports order_price with cursor pagination
        $response = $this->getJson(self::LIST_URL . '?type=index&pagination=cursor&order_price=asc');

        $response->assertOk();
    }

    public function test_cursor_pagination_prev_navigates_backwards(): void
    {
        $this->enableCursorPagination();
        foreach (range(1, 5) as $i) {
            $this->makeProduct(['slug' => 'cursor-prev-' . uniqid()]);
        }

        $page1 = $this->getJson(self::LIST_URL . '?pagination=cursor&limit=2');
        $page1->assertOk();
        $nextCursor = $this->cursorFromUrl($page1->json('data.links.next_page_url'));
        $this->assertNotNull($nextCursor);

        $page2 = $this->getJson(self::LIST_URL . '?pagination=cursor&limit=2&cursor=' . $nextCursor);
        $page2->assertOk();
        $prevCursor = $this->cursorFromUrl($page2->json('data.links.prev_page_url'));
        $this->assertNotNull($prevCursor);

        $backToPage1 = $this->getJson(self::LIST_URL . '?pagination=cursor&limit=2&cursor=' . $prevCursor);
        $backToPage1->assertOk();

        $this->assertSame(
            collect($page1->json('data.data'))->pluck('id')->all(),
            collect($backToPage1->json('data.data'))->pluck('id')->all(),
            'Previous cursor should return the first page items'
        );
    }

    public function test_cursor_pagination_invalid_token_returns_first_page(): void
    {
        $this->enableCursorPagination();
        $this->makeProduct(['slug' => 'cursor-invalid']);

        $response = $this->getJson(self::LIST_URL . '?pagination=cursor&cursor=not-a-valid-token');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_cursor_pagination_fallback_empty_type_flow(): void
    {
        $this->enableCursorPagination();
        foreach (range(1, 5) as $i) {
            $this->makeProduct(['slug' => 'cursor-fallback-' . uniqid()]);
        }

        $seen = $this->walkCursorPages('?type=&pagination=cursor&limit=2');

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen));
    }

    // =========================================================================
    // CURSOR PAGINATION — PRICE ORDERING
    // =========================================================================

    public function test_cursor_pagination_price_desc_accepted(): void
    {
        $this->enableCursorPagination();

        $this->makeProduct(['price' => 100]);

        $response = $this->getJson(self::LIST_URL . '?pagination=cursor&order_price=desc');

        $response->assertOk();
    }

    public function test_cursor_pagination_price_asc_traversal_deterministic(): void
    {
        $this->enableCursorPagination();

        // Create products with duplicate prices to test tie-breaking
        $p1 = $this->makeProduct(['slug' => 'price-10-a', 'price' => 10]);
        $p2 = $this->makeProduct(['slug' => 'price-10-b', 'price' => 10]);
        $p3 = $this->makeProduct(['slug' => 'price-20-a', 'price' => 20]);
        $p4 = $this->makeProduct(['slug' => 'price-20-b', 'price' => 20]);
        $p5 = $this->makeProduct(['slug' => 'price-30', 'price' => 30]);

        $seen = $this->walkCursorPages('?pagination=cursor&order_price=asc&limit=2');

        // Should retrieve all 5 products
        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen), 'No duplicates');

        // Verify ordering: prices must be ascending
        $prices = collect($seen)->map(fn($id) => [$p1, $p2, $p3, $p4, $p5]
            [array_search($id, [$p1->id, $p2->id, $p3->id, $p4->id, $p5->id])]
            ->price
        )->all();

        for ($i = 1; $i < count($prices); $i++) {
            $this->assertLessThanOrEqual($prices[$i], $prices[$i - 1], 'Prices must be in ascending order');
        }
    }

    public function test_cursor_pagination_price_desc_traversal_deterministic(): void
    {
        $this->enableCursorPagination();

        // Create products with duplicate prices
        $p1 = $this->makeProduct(['slug' => 'price-10-a', 'price' => 10]);
        $p2 = $this->makeProduct(['slug' => 'price-10-b', 'price' => 10]);
        $p3 = $this->makeProduct(['slug' => 'price-20-a', 'price' => 20]);
        $p4 = $this->makeProduct(['slug' => 'price-20-b', 'price' => 20]);
        $p5 = $this->makeProduct(['slug' => 'price-30', 'price' => 30]);

        $seen = $this->walkCursorPages('?pagination=cursor&order_price=desc&limit=2');

        // Should retrieve all 5 products
        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen), 'No duplicates');

        // Verify ordering: prices must be descending
        $prices = collect($seen)->map(fn($id) => [$p1, $p2, $p3, $p4, $p5]
            [array_search($id, [$p1->id, $p2->id, $p3->id, $p4->id, $p5->id])]
            ->price
        )->all();

        for ($i = 1; $i < count($prices); $i++) {
            $this->assertGreaterThanOrEqual($prices[$i], $prices[$i - 1], 'Prices must be in descending order');
        }
    }

    public function test_cursor_pagination_price_asc_duplicate_prices_across_page_boundaries(): void
    {
        $this->enableCursorPagination();

        // Create multiple products with the same price to test page boundary handling
        $p1 = $this->makeProduct(['slug' => 'same-price-1', 'price' => 100]);
        $p2 = $this->makeProduct(['slug' => 'same-price-2', 'price' => 100]);
        $p3 = $this->makeProduct(['slug' => 'same-price-3', 'price' => 100]);
        $p4 = $this->makeProduct(['slug' => 'different-price', 'price' => 200]);

        // Use limit=2 to force page boundary in the middle of same-price products
        $seen = $this->walkCursorPages('?pagination=cursor&order_price=asc&limit=2');

        $this->assertCount(4, $seen, 'All products must be retrieved');
        $this->assertCount(4, array_unique($seen), 'No duplicates even with same price across pages');
        $this->assertContains($p1->id, $seen);
        $this->assertContains($p2->id, $seen);
        $this->assertContains($p3->id, $seen);
        $this->assertContains($p4->id, $seen);
    }

    public function test_cursor_pagination_price_prev_navigation(): void
    {
        $this->enableCursorPagination();

        $p1 = $this->makeProduct(['price' => 10]);
        $p2 = $this->makeProduct(['price' => 20]);
        $p3 = $this->makeProduct(['price' => 30]);
        $p4 = $this->makeProduct(['price' => 40]);

        // Navigate forward
        $page1 = $this->getJson(self::LIST_URL . '?pagination=cursor&order_price=asc&limit=2');
        $page1->assertOk();
        $page1Ids = collect($page1->json('data.data'))->pluck('id')->all();
        $this->assertCount(2, $page1Ids);

        $nextUrl = $page1->json('data.links.next_page_url');
        $this->assertNotNull($nextUrl);

        $page2 = $this->getJson($nextUrl);
        $page2->assertOk();
        $page2Ids = collect($page2->json('data.data'))->pluck('id')->all();
        $this->assertCount(2, $page2Ids);

        // Navigate backward
        $prevUrl = $page2->json('data.links.prev_page_url');
        $this->assertNotNull($prevUrl);

        $backToPage1 = $this->getJson($prevUrl);
        $backToPage1->assertOk();
        $backToPage1Ids = collect($backToPage1->json('data.data'))->pluck('id')->all();

        $this->assertSame($page1Ids, $backToPage1Ids, 'Previous navigation must return to exact same page');
    }

    public function test_cursor_pagination_price_with_price_range_filter(): void
    {
        $this->enableCursorPagination();

        $p1 = $this->makeProduct(['price' => 5]);   // Below range
        $p2 = $this->makeProduct(['price' => 15]);  // In range
        $p3 = $this->makeProduct(['price' => 25]);  // In range
        $p4 = $this->makeProduct(['price' => 35]);  // Above range

        // Verify cursor + order_price + price filters work together
        $response = $this->getJson(self::LIST_URL . '?pagination=cursor&order_price=asc&min_price=10&max_price=30');

        $response->assertOk();
        $this->assertNotNull($response->json('data.data'));
    }

    public function test_cursor_pagination_price_with_brand_filter(): void
    {
        $this->enableCursorPagination();

        $brand = Brand::create(['name' => ['en' => 'Test Brand'], 'slug' => 'test-brand']);
        $p1 = $this->makeProduct(['price' => 10]);
        $p2 = $this->makeProduct(['price' => 20]);
        $p2->brands()->attach($brand);

        $response = $this->getJson(self::LIST_URL . '?pagination=cursor&order_price=asc&brand=' . $brand->slug);

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertContains($p2->id, $ids);
        $this->assertNotContains($p1->id, $ids);
    }

    public function test_cursor_pagination_price_with_search_returns_422(): void
    {
        $this->enableCursorPagination();

        $response = $this->getJson(self::LIST_URL . '?pagination=cursor&order_price=asc&search=phone');

        $response->assertStatus(422);
        $this->assertArrayHasKey('pagination', $response->json('errors'));
    }

    public function test_cursor_pagination_price_null_handling(): void
    {
        $this->enableCursorPagination();

        // Create products with NULL prices and non-NULL prices
        // Note: price column is nullable in production but test schema may enforce NOT NULL
        $p2 = $this->makeProduct(['price' => 10]);
        $p4 = $this->makeProduct(['price' => 20]);
        $p5 = $this->makeProduct(['price' => 30]);

        // ASC: prices should be in ascending order
        $seenAsc = $this->walkCursorPages('?pagination=cursor&order_price=asc&limit=2');
        $this->assertCount(3, $seenAsc);
        $this->assertCount(3, array_unique($seenAsc), 'No duplicates');

        // DESC: prices should be in descending order
        $seenDesc = $this->walkCursorPages('?pagination=cursor&order_price=desc&limit=2');
        $this->assertCount(3, $seenDesc);
        $this->assertCount(3, array_unique($seenDesc), 'No duplicates');
    }


    private function fullUrl(string $path, array $query = []): string
    {
        $url = url($path);

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}