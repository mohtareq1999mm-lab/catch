<?php

declare(strict_types=1);

namespace Tests\Feature\General;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class FlashSalesEndpointTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const LIST_URL = '/api/v1/general/flash-sales';
    private const QTY_URL = '/api/v1/general/flash-sale-products';
    private const WEEK_URL = '/api/v1/general/flash-sale-products-ending-this-week';
    private const TODAY_URL = '/api/v1/general/flash-sale-products-ending-today';

    protected function setUp(): void
    {
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
            'status' => 'publish',
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

    private function flashSaleSelects(): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn(array $q) => preg_match('/^select/i', $q['query']) === 1
                && preg_match('/\bfrom\s+["`]?flash_sales["`]?/i', $q['query']) === 1)
            ->count();
    }

    // =========================================================================
    // CONTRACT / HAPPY PATH
    // =========================================================================

    public function test_public_endpoint_requires_no_authentication(): void
    {
        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $response->assertJsonStructure(['status', 'message', 'success', 'data']);
        $this->assertTrue($response->json('success'));
        $this->assertSame(200, $response->json('status'));
    }

    public function test_returns_empty_data_when_no_flash_sales_exist(): void
    {
        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_index_returns_only_valid_flash_sales(): void
    {
        $this->makeFlashSale(['slug' => 'valid-fs']);
        $this->makeFlashSale(['slug' => 'inactive-fs', 'status' => false]);
        $this->makeFlashSale(['slug' => 'expired-fs', 'start_date' => now()->subDays(10)->toDateString(), 'end_date' => now()->subDay()->toDateString()]);
        $this->makeFlashSale(['slug' => 'future-fs', 'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(5)->toDateString()]);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('valid-fs', $slugs);
        $this->assertNotContains('inactive-fs', $slugs);
        $this->assertNotContains('expired-fs', $slugs);
        $this->assertNotContains('future-fs', $slugs);
    }

    public function test_index_respects_flash_sales_id_filter(): void
    {
        $fs1 = $this->makeFlashSale(['slug' => 'id-fs-1']);
        $fs2 = $this->makeFlashSale(['slug' => 'id-fs-2']);

        $response = $this->getJson(self::LIST_URL . '?flashSalesId=' . $fs1->id);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$fs1->id], $ids);
        $this->assertNotContains($fs2->id, $ids);
    }

    public function test_flash_sale_detail_returns_products_with_pricing(): void
    {
        $flashSale = $this->makeFlashSale(['slug' => 'detail-fs', 'discount' => 50]);
        $product = $this->makeProduct(['slug' => 'detail-p', 'price' => 100.0, 'has_flash_sale' => true]);
        $flashSale->products()->attach($product->id);

        $response = $this->getJson(self::LIST_URL . '/' . $flashSale->slug);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('Summer Sale', $data['name']);
        $this->assertSame('detail-fs', $data['slug']);
        $productData = collect($data['products'])->firstWhere('slug', 'detail-p');
        $this->assertNotNull($productData);
        $this->assertTrue($productData['flash_sale_active']);
        $this->assertLessThan(100.0, (float) $productData['current_price']);
    }

    public function test_flash_sale_detail_returns_404_for_missing_slug(): void
    {
        $response = $this->getJson(self::LIST_URL . '/does-not-exist');

        $response->assertStatus(404);
    }

    public function test_flash_sale_products_endpoint_returns_products(): void
    {
        $flashSale = $this->makeFlashSale();
        $p1 = $this->makeProduct(['slug' => 'qty-p1', 'has_flash_sale' => true]);
        $p2 = $this->makeProduct(['slug' => 'qty-p2', 'has_flash_sale' => true]);
        $flashSale->products()->attach([$p1->id, $p2->id]);

        $response = $this->getJson(self::QTY_URL);

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertCount(2, $slugs);
        $this->assertContains('qty-p1', $slugs);
        $this->assertContains('qty-p2', $slugs);
    }

    public function test_flash_sale_products_ending_this_week_returns_products(): void
    {
        $flashSale = $this->makeFlashSale(['start_date' => now()->subDay()->toDateString(), 'end_date' => today()->toDateString()]);
        $product = $this->makeProduct(['slug' => 'week-p', 'has_flash_sale' => true]);
        $flashSale->products()->attach($product->id);

        $response = $this->getJson(self::WEEK_URL);

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('week-p', $slugs);
    }

    public function test_flash_sale_products_ending_today_returns_products(): void
    {
        $flashSale = $this->makeFlashSale(['start_date' => now()->subDay()->toDateString(), 'end_date' => today()->toDateString()]);
        $product = $this->makeProduct(['slug' => 'today-p', 'has_flash_sale' => true]);
        $flashSale->products()->attach($product->id);

        $response = $this->getJson(self::TODAY_URL);

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('today-p', $slugs);
    }

    // =========================================================================
    // FINDING PROOFS
    // =========================================================================

    public function test_index_cache_hit_must_not_re_execute_query_pipeline(): void
    {
        $this->makeFlashSale(['slug' => 'cache-fs']);

        $this->getJson(self::LIST_URL)->assertOk();

        DB::enableQueryLog();
        $response = $this->getJson(self::LIST_URL);
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThanOrEqual(
            1,
            $this->flashSaleSelects(),
            'A cache hit must skip the flash sales query pipeline'
        );
    }

    public function test_ending_this_week_preserves_product_stock_and_variant_fields(): void
    {
        $flashSale = $this->makeFlashSale(['start_date' => now()->subDay()->toDateString(), 'end_date' => today()->toDateString()]);
        $product = $this->makeProduct([
            'slug' => 'week-fields-p',
            'product_type' => 'simple',
            'in_stock' => true,
            'stock_quantity' => 10,
            'reserved_quantity' => 2,
            'is_fast_shipping_available' => true,
            'has_flash_sale' => true,
        ]);
        $flashSale->products()->attach($product->id);

        $response = $this->getJson(self::WEEK_URL);

        $response->assertOk();
        $item = collect($response->json('data'))->firstWhere('slug', 'week-fields-p');
        $this->assertNotNull($item);
        $this->assertFalse($item['has_variants'], 'product_type must be selected so has_variants is computed correctly');
        $this->assertSame(10, $item['quantity'], 'stock_quantity must be selected');
        $this->assertTrue($item['in_stock'], 'in_stock must be selected');
        $this->assertTrue($item['is_fast_shipping_available'], 'is_fast_shipping_available must be selected');
        $this->assertTrue($item['flash_sale_active']);
        $this->assertLessThan((float) $item['price'], (float) $item['current_price']);
    }

    public function test_ending_today_preserves_product_stock_and_variant_fields(): void
    {
        $flashSale = $this->makeFlashSale(['start_date' => now()->subDay()->toDateString(), 'end_date' => today()->toDateString()]);
        $product = $this->makeProduct([
            'slug' => 'today-fields-p',
            'product_type' => 'simple',
            'in_stock' => true,
            'stock_quantity' => 10,
            'reserved_quantity' => 2,
            'is_fast_shipping_available' => true,
            'has_flash_sale' => true,
        ]);
        $flashSale->products()->attach($product->id);

        $response = $this->getJson(self::TODAY_URL);

        $response->assertOk();
        $item = collect($response->json('data'))->firstWhere('slug', 'today-fields-p');
        $this->assertNotNull($item);
        $this->assertFalse($item['has_variants'], 'product_type must be selected so has_variants is computed correctly');
        $this->assertSame(10, $item['quantity'], 'stock_quantity must be selected');
        $this->assertTrue($item['in_stock'], 'in_stock must be selected');
        $this->assertTrue($item['is_fast_shipping_available'], 'is_fast_shipping_available must be selected');
        $this->assertTrue($item['flash_sale_active']);
        $this->assertLessThan((float) $item['price'], (float) $item['current_price']);
    }

    public function test_flash_sale_products_eager_loads_flash_sales_relation(): void
    {
        $flashSale = $this->makeFlashSale(['slug' => 'eager-fs']);
        $p1 = $this->makeProduct(['slug' => 'eager-p1', 'has_flash_sale' => true]);
        $p2 = $this->makeProduct(['slug' => 'eager-p2', 'has_flash_sale' => true]);
        $flashSale->products()->attach([$p1->id, $p2->id]);

        DB::enableQueryLog();
        $response = $this->getJson(self::QTY_URL);
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThanOrEqual(
            2,
            $this->flashSaleSelects(),
            'flash_sales relation must be eager loaded, not queried once per product'
        );
    }

    public function test_flash_sale_products_does_not_duplicate_products(): void
    {
        $fs1 = $this->makeFlashSale(['slug' => 'dup-fs-1']);
        $fs2 = $this->makeFlashSale(['slug' => 'dup-fs-2']);
        $product = $this->makeProduct(['slug' => 'dup-p', 'has_flash_sale' => true]);
        $fs1->products()->attach($product->id);
        $fs2->products()->attach($product->id);

        $response = $this->getJson(self::QTY_URL);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertCount(count(array_unique($ids)), $ids, 'A product shared by multiple flash sales must not be duplicated');
    }

    public function test_flash_sale_detail_hides_inactive_flash_sale(): void
    {
        $flashSale = $this->makeFlashSale(['slug' => 'inactive-detail-fs', 'status' => false]);

        $response = $this->getJson(self::LIST_URL . '/' . $flashSale->slug);

        $response->assertStatus(404);
    }

    public function test_flash_sale_detail_hides_expired_flash_sale(): void
    {
        $flashSale = $this->makeFlashSale([
            'slug' => 'expired-detail-fs',
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->getJson(self::LIST_URL . '/' . $flashSale->slug);

        $response->assertStatus(404);
    }

    public function test_index_rejects_invalid_order_param(): void
    {
        $this->makeFlashSale();

        $response = $this->getJson(self::LIST_URL . '?order=evil');

        $response->assertStatus(422);
    }

    public function test_index_caps_limit_param(): void
    {
        foreach (range(1, 101) as $i) {
            $this->makeFlashSale(['slug' => 'cap-fs-' . $i]);
        }

        $response = $this->getJson(self::LIST_URL . '?limit=1000');

        $response->assertOk();
        $this->assertLessThanOrEqual(100, count($response->json('data')), 'limit must be capped');
    }

    public function test_flash_sale_cache_is_invalidated_on_create(): void
    {
        $this->makeFlashSale(['slug' => 'pre-cache-fs']);

        $this->getJson(self::LIST_URL)->assertOk();

        $this->makeFlashSale(['slug' => 'post-create-fs']);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains(
            'post-create-fs',
            $slugs,
            'A newly created flash sale must invalidate the flash-sales frontend cache'
        );
    }

    public function test_index_cache_miss_executes_queries(): void
    {
        $this->makeFlashSale(['slug' => 'miss-fs']);

        DB::enableQueryLog();
        $this->getJson(self::LIST_URL)->assertOk();
        DB::disableQueryLog();

        $this->assertGreaterThanOrEqual(1, $this->flashSaleSelects(), 'A cache miss must query the flash sales table');
    }

    public function test_index_cache_hit_returns_identical_response(): void
    {
        $this->makeFlashSale(['slug' => 'identical-fs', 'title' => ['en' => 'Identical Sale']]);

        $miss = $this->getJson(self::LIST_URL);
        $miss->assertOk();

        $hit = $this->getJson(self::LIST_URL);
        $hit->assertOk();

        $this->assertSame($miss->json('data'), $hit->json('data'), 'A cache hit must serve the identical payload');
    }

    public function test_flash_sale_products_pricing_is_identical(): void
    {
        $flashSale = $this->makeFlashSale(['slug' => 'price-fs', 'discount' => 50]);
        $p1 = $this->makeProduct(['slug' => 'price-p1', 'price' => 100.0, 'has_flash_sale' => true]);
        $p2 = $this->makeProduct(['slug' => 'price-p2', 'price' => 200.0, 'has_flash_sale' => true]);
        $flashSale->products()->attach([$p1->id, $p2->id]);

        $response = $this->getJson(self::QTY_URL);

        $response->assertOk();
        $items = collect($response->json('data'))->keyBy('slug');
        $this->assertTrue($items['price-p1']['flash_sale_active']);
        $this->assertTrue($items['price-p2']['flash_sale_active']);
        $this->assertLessThan(100.0, (float) $items['price-p1']['current_price']);
        $this->assertLessThan(200.0, (float) $items['price-p2']['current_price']);
    }

    public function test_index_empty_order_defaults_to_desc(): void
    {
        $a = $this->makeFlashSale(['slug' => 'empty-order-a']);
        $b = $this->makeFlashSale(['slug' => 'empty-order-b']);

        $response = $this->getJson(self::LIST_URL . '?order=');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$b->id, $a->id], $ids, 'An empty order value must fall back to the desc default');
    }

    public function test_index_negative_limit_uses_default(): void
    {
        $this->makeFlashSale(['slug' => 'neg-limit-fs']);

        $response = $this->getJson(self::LIST_URL . '?limit=-5');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_zero_limit_uses_default(): void
    {
        $this->makeFlashSale(['slug' => 'zero-limit-fs']);

        $response = $this->getJson(self::LIST_URL . '?limit=0');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_non_numeric_limit_uses_default(): void
    {
        $this->makeFlashSale(['slug' => 'alpha-limit-fs']);

        $response = $this->getJson(self::LIST_URL . '?limit=abc');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_limit_of_100_returns_up_to_100(): void
    {
        foreach (range(1, 101) as $i) {
            $this->makeFlashSale(['slug' => 'hundred-fs-' . $i]);
        }

        $response = $this->getJson(self::LIST_URL . '?limit=100');

        $response->assertOk();
        $this->assertLessThanOrEqual(100, count($response->json('data')));
    }

    public function test_flash_sale_cache_is_invalidated_on_update(): void
    {
        $flashSale = $this->makeFlashSale(['slug' => 'pre-update-fs']);

        $this->getJson(self::LIST_URL)->assertOk();

        $flashSale->update(['title' => ['en' => 'Updated Sale'], 'discount' => 30]);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Updated Sale', $names, 'A flash sale update must invalidate the flash-sales cache');
    }

    public function test_flash_sale_cache_is_invalidated_on_delete(): void
    {
        $flashSale = $this->makeFlashSale(['slug' => 'pre-delete-fs']);

        $this->getJson(self::LIST_URL)->assertOk();

        $flashSale->delete();

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertNotContains('pre-delete-fs', $slugs, 'A deleted flash sale must not remain cached');
    }

    public function test_flash_sale_cache_is_invalidated_on_restore(): void
    {
        $flashSale = $this->makeFlashSale(['slug' => 'pre-restore-fs']);

        $this->getJson(self::LIST_URL)->assertOk();

        $flashSale->delete();
        $this->getJson(self::LIST_URL)->assertOk();

        $flashSale->restore();

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('pre-restore-fs', $slugs, 'A restored flash sale must invalidate the flash-sales cache');
    }
}