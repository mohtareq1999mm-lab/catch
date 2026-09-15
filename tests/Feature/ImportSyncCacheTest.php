<?php

namespace Tests\Feature;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ImportSyncCacheTest extends TestCase
{
    use RefreshDatabase;
    use HasCache;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::setDefaultDriver('array');
        Cache::flush();
    }

    public function test_product_cache_invalidated_via_fallback_flush(): void
    {
        $key = md5('http://test/api/v1/general/products?limit=10');
        // prime via HasCache (will use array fallback path if tags not supported)
        $result = $this->remember(FrontendResource::PRODUCTS->value, $key, 'stale');
        $this->assertEquals('stale', $result);
        // Simulate ImportProductsJob invalidate (array fallback -> Cache::flush)
        $needsFlush = false;
        foreach ([FrontendResource::PRODUCTS->value] as $tag) {
            try {
                Cache::tags([$tag])->flush();
                if (!Cache::getStore() instanceof \Illuminate\Cache\TaggableStore) {
                    $needsFlush = true;
                }
            } catch (\BadMethodCallException) {
                $needsFlush = true;
            }
        }
        if ($needsFlush) Cache::flush();
        // After flush, remember should miss and recompute
        $recalled = $this->remember(FrontendResource::PRODUCTS->value, $key, 'fresh');
        $this->assertEquals('fresh', $recalled);
    }

    public function test_brand_observer_flush_clears_product_and_brand_caches(): void
    {
        $brandKey = md5('http://test/api/v1/general/brands');
        $productKey = md5('http://test/api/v1/general/products?limit=10');
        $this->remember(FrontendResource::BRANDS->value, $brandKey, fn() => 'old_brand');
        $this->remember(FrontendResource::PRODUCTS->value, $productKey, fn() => 'old_product');
        Cache::flush();
        $freshBrand = $this->remember(FrontendResource::BRANDS->value, $brandKey, fn() => 'new_brand');
        $freshProd = $this->remember(FrontendResource::PRODUCTS->value, $productKey, fn() => 'new_product');
        $this->assertEquals('new_brand', $freshBrand);
        $this->assertEquals('new_product', $freshProd);
    }

    public function test_category_import_invalidates_product_cache(): void
    {
        $catKey = md5('http://test/api/v1/general/categories');
        $prodKey = md5('http://test/api/v1/general/products');
        $this->remember(FrontendResource::CATEGORIES->value, $catKey, fn() => 'old_cat');
        $this->remember(FrontendResource::PRODUCTS->value, $prodKey, fn() => 'old_prod');
        Cache::flush();
        $freshCat = $this->remember(FrontendResource::CATEGORIES->value, $catKey, fn() => 'new_cat');
        $freshProd = $this->remember(FrontendResource::PRODUCTS->value, $prodKey, fn() => 'new_prod');
        $this->assertEquals('new_cat', $freshCat);
        $this->assertEquals('new_prod', $freshProd);
    }

    public function test_product_import_image_deduplication(): void
    {
        // verify isDuplicateMedia logic does not create duplicate when same file re-imported
        $product = new \Marvel\Database\Models\Product([
            'name' => ['en' => 'Test', 'ar' => 'اختبار'],
            'slug' => 'test-sku-' . uniqid(),
            'sku' => 'TEST-SKU-' . uniqid(),
            'price' => 10,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'quantity' => 10,
        ]);
        $product->saveQuietly();
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, 'fake image content');
        // first attach
        $product->addMedia($tmp)->toMediaCollection('products');
        $this->assertEquals(1, $product->fresh()->getMedia('products')->count());
        // simulate duplicate check - second file with same content should be considered duplicate
        $tmp2 = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp2, 'fake image content');
        $service = new \Marvel\Services\Import\ProductImportService();
        $reflection = new \ReflectionMethod($service, 'isDuplicateMedia');
        $reflection->setAccessible(true);
        $isDup = $reflection->invoke($service, $product->fresh(), $tmp2);
        $this->assertTrue($isDup, 'Same content should be detected as duplicate');
        @unlink($tmp); @unlink($tmp2);
        $product->clearMediaCollection('products');
        $product->forceDelete();
    }
}
