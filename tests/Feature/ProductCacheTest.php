<?php

namespace Tests\Feature;

use App\Enums\FrontendResource;
use App\Services\Currency\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Tests\TestCase;

class ProductCacheTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCTS_INDEX_TAG = 'products_index';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::tags([self::PRODUCTS_INDEX_TAG])->flush();
    }

    private function createProduct(): Product
    {
        $brand = Brand::create(['name' => ['en' => 'Apple'], 'status' => 1]);

        $product = Product::create([
            'name' => ['en' => 'Cached Product'],
            'slug' => 'cached-product',
            'price' => 100.00,
            'status' => 1,
            'in_stock' => 1,
        ]);
        $product->brands()->attach($brand->id);
        ProductVariant::create(['product_id' => $product->id, 'price' => 100.00, 'quantity' => 5]);

        return $product;
    }

    private function cacheKeyFor(string $path, array $query = []): string
    {
        $fullUrl = url($path);

        if (!empty($query)) {
            $fullUrl .= '?' . http_build_query($query);
        }

        return md5($fullUrl . '|currency:' . app(CurrencyService::class)->getEffectiveCode());
    }

    public function test_products_endpoint_writes_response_to_cache_without_search_parameter(): void
    {
        $this->createProduct();

        $this->getJson('/api/v1/general/products')->assertOk();

        $this->assertTrue(
            Cache::tags([self::PRODUCTS_INDEX_TAG])->has($this->cacheKeyFor('/api/v1/general/products'))
        );
    }

    public function test_products_endpoint_serves_cached_response_without_search_parameter(): void
    {
        $this->createProduct();

        $key = $this->cacheKeyFor('/api/v1/general/products');
        Cache::tags([self::PRODUCTS_INDEX_TAG])->put($key, ['__cache_test__' => 'served_from_cache'], now()->addHour());

        $this->getJson('/api/v1/general/products')
            ->assertOk()
            ->assertJsonFragment(['__cache_test__' => 'served_from_cache']);
    }

    public function test_products_endpoint_uses_distinct_cache_entries_for_different_query_parameters(): void
    {
        $this->createProduct();

        $this->getJson('/api/v1/general/products?limit=5')->assertOk();
        $this->getJson('/api/v1/general/products?limit=10')->assertOk();

        $this->assertTrue(
            Cache::tags([self::PRODUCTS_INDEX_TAG])->has($this->cacheKeyFor('/api/v1/general/products', ['limit' => '5']))
        );
        $this->assertTrue(
            Cache::tags([self::PRODUCTS_INDEX_TAG])->has($this->cacheKeyFor('/api/v1/general/products', ['limit' => '10']))
        );
    }

    public function test_products_endpoint_with_search_parameter_does_not_read_or_write_cache(): void
    {
        $this->createProduct();

        $key = $this->cacheKeyFor('/api/v1/general/products', ['search' => 'Cached']);
        Cache::tags([self::PRODUCTS_INDEX_TAG])->put($key, ['__cache_test__' => 'stale'], now()->addHour());

        $this->getJson('/api/v1/general/products?search=Cached')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Cached Product'])
            ->assertJsonMissing(['__cache_test__' => 'stale']);

        $this->assertSame(['__cache_test__' => 'stale'], Cache::tags([self::PRODUCTS_INDEX_TAG])->get($key));
    }

    public function test_products_endpoint_with_empty_search_parameter_also_bypasses_cache(): void
    {
        $this->createProduct();

        $key = $this->cacheKeyFor('/api/v1/general/products', ['search' => '']);
        Cache::tags([self::PRODUCTS_INDEX_TAG])->put($key, ['__cache_test__' => 'stale'], now()->addHour());

        $this->getJson('/api/v1/general/products?search=')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Cached Product'])
            ->assertJsonMissing(['__cache_test__' => 'stale']);

        $this->assertSame(['__cache_test__' => 'stale'], Cache::tags([self::PRODUCTS_INDEX_TAG])->get($key));
    }
}
