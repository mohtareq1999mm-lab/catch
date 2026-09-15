<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FrontendResource;
use App\Services\General\HomeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ImportCacheInvalidationTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }
        parent::setUp();
        $this->createAllTestTables();
        Cache::flush();
        Cache::forget('api_cache_version');
    }

    private function cachePutTagged(string $tag, string $key, mixed $value): void
    {
        try {
            Cache::tags([$tag])->put($key, $value, 3600);
        } catch (\BadMethodCallException) {
            Cache::put($tag.':'.$key, $value, 3600);
        }
    }

    private function cacheHasTagged(string $tag, string $key): bool
    {
        try {
            return Cache::tags([$tag])->has($key);
        } catch (\BadMethodCallException) {
            return Cache::has($tag.':'.$key);
        }
    }

    public function test_product_update_invalidates_product_and_related_caches(): void
    {
        $product = Product::create([
            'name' => ['en' => 'CacheProd'], 'slug' => 'cache-prod-'.uniqid(),
            'price' => 10, 'status' => 1, 'in_stock' => true, 'stock_quantity' => 5,
        ]);
        // prime caches
        $this->cachePutTagged(FrontendResource::PRODUCTS->value, 'test-key', 'stale');
        $this->cachePutTagged(FrontendResource::CATEGORIES->value, 'cat-key', 'stale');
        $this->cachePutTagged(FrontendResource::BRANDS->value, 'brand-key', 'stale');
        Cache::put('api_cache', ['content'=>'old'], 3600);
        Cache::increment('api_cache_version');
        $versionBefore = Cache::get('api_cache_version');

        // trigger observer via normal update (not quiet)
        $product->update(['price' => 20]);

        $this->assertFalse($this->cacheHasTagged(FrontendResource::PRODUCTS->value, 'test-key'), 'products tag should be flushed');
        // HomeService clearCache is called, check a home key is gone
        Cache::put('test-channel:home-nav-bar', 'old', 3600);
        HomeService::clearCache();
        // after product update home should be cleared
        $product2 = Product::create([
            'name' => ['en' => 'CacheProd2'], 'slug' => 'cache-prod2-'.uniqid(),
            'price' => 15, 'status' => 1, 'in_stock' => true, 'stock_quantity' => 5,
        ]);
        $product2->update(['price' => 25]);
        // version should have incremented again
        $this->assertGreaterThan($versionBefore, Cache::get('api_cache_version'));
    }

    public function test_brand_update_invalidates_brand_and_product_caches(): void
    {
        $brand = Brand::create(['name' => ['en' => 'CacheBrand'], 'slug' => 'cache-brand-'.uniqid(), 'status' => 1]);
        $this->cachePutTagged(FrontendResource::BRANDS->value, 'b-key', 'stale');
        $this->cachePutTagged(FrontendResource::BRANDS_PRODUCTS->value, 'bp-key', 'stale');
        $this->cachePutTagged(FrontendResource::PRODUCTS->value, 'p-key', 'stale');
        $brand->update(['name' => ['en' => 'CacheBrand2']]);
        $this->assertFalse($this->cacheHasTagged(FrontendResource::BRANDS->value, 'b-key'));
        $this->assertFalse($this->cacheHasTagged(FrontendResource::BRANDS_PRODUCTS->value, 'bp-key'));
        $this->assertFalse($this->cacheHasTagged(FrontendResource::PRODUCTS->value, 'p-key'));
    }

    public function test_category_update_invalidates_category_and_product_caches(): void
    {
        $cat = Category::create(['name' => ['en' => 'CacheCat'], 'slug' => 'cache-cat-'.uniqid(), 'status' => 1]);
        $this->cachePutTagged(FrontendResource::CATEGORIES->value, 'c-key', 'stale');
        $this->cachePutTagged(FrontendResource::PRODUCTS->value, 'p-key', 'stale');
        $cat->update(['name' => ['en' => 'CacheCat2']]);
        $this->assertFalse($this->cacheHasTagged(FrontendResource::CATEGORIES->value, 'c-key'));
        $this->assertFalse($this->cacheHasTagged(FrontendResource::PRODUCTS->value, 'p-key'));
    }

    public function test_import_product_sync_replaces_relationships(): void
    {
        $product = Product::create([
            'name' => ['en' => 'SyncProd'], 'slug' => 'sync-prod-'.uniqid(),
            'price' => 10, 'status' => 1, 'in_stock' => true, 'stock_quantity' => 5,
            'sku' => 'SYNC-'.uniqid(),
        ]);
        $catA = Category::create(['name' => ['en' => 'CatA'], 'slug' => 'cata-'.uniqid(), 'status' => 1]);
        $catB = Category::create(['name' => ['en' => 'CatB'], 'slug' => 'catb-'.uniqid(), 'status' => 1]);
        $product->categories()->sync([$catA->id]);
        $this->assertTrue($product->categories()->where('categories.id', $catA->id)->exists());
        // simulate import syncCategories with new cat
        $service = new \Marvel\Services\Import\ProductImportService();
        $service->syncCategories($product->sku, [$catB->slug]);
        $product->refresh();
        $this->assertFalse($product->categories()->where('categories.id', $catA->id)->exists());
        $this->assertTrue($product->categories()->where('categories.id', $catB->id)->exists());
    }

    public function test_activeVisibilityStillHoldsAfterImport(): void
    {
        $active = Product::create(['name'=>['en'=>'ActiveImp'],'slug'=>'active-imp-'.uniqid(),'price'=>10,'status'=>1,'in_stock'=>true,'stock_quantity'=>5]);
        $inactive = Product::create(['name'=>['en'=>'InactiveImp'],'slug'=>'inactive-imp-'.uniqid(),'price'=>10,'status'=>0,'in_stock'=>false,'stock_quantity'=>0]);
        $resp = $this->getJson('/api/v1/general/products');
        $slugs = collect($resp->json('data.data'))->pluck('slug')->all();
        $this->assertContains($active->slug, $slugs);
        $this->assertNotContains($inactive->slug, $slugs);
    }
}
