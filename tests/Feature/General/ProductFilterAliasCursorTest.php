<?php

declare(strict_types=1);

namespace Tests\Feature\General;

use App\Enums\FrontendResource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Slider;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ProductFilterAliasCursorTest extends TestCase
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
        config(['cursor.enabled' => true]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'P ' . uniqid(), 'ar' => 'م ' . uniqid()],
            'slug' => 'p-' . uniqid(),
            'price' => 50.0,
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

    private function cursorFromUrl(?string $url): ?string
    {
        if (!$url) return null;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $q['cursor'] ?? null;
    }

    public function test_min_price_alias_filters_offset(): void
    {
        $cheap = $this->makeProduct(['slug' => 'cheap-' . uniqid(), 'price' => 5.0]);
        $mid = $this->makeProduct(['slug' => 'mid-' . uniqid(), 'price' => 50.0]);
        $expensive = $this->makeProduct(['slug' => 'expensive-' . uniqid(), 'price' => 500.0]);

        $resp = $this->getJson(self::LIST_URL . '?min_price=10&max_price=100');
        $resp->assertOk();
        $slugs = collect($resp->json('data.data'))->pluck('slug')->all();
        $this->assertContains($mid->slug, $slugs);
        $this->assertNotContains($cheap->slug, $slugs);
        $this->assertNotContains($expensive->slug, $slugs);
    }

    public function test_min_price_alias_with_cursor_traverses_only_filtered(): void
    {
        foreach (range(1, 4) as $i) {
            $this->makeProduct(['slug' => 'cursor-price-in-' . uniqid(), 'price' => 50.0]);
        }
        $this->makeProduct(['slug' => 'cursor-price-out', 'price' => 500.0]);

        $seen = [];
        $cursor = null;
        do {
            $qs = '?pagination=cursor&min_price=10&max_price=100&limit=2' . ($cursor ? '&cursor=' . $cursor : '');
            $r = $this->getJson(self::LIST_URL . $qs);
            $r->assertOk();
            $seen = array_merge($seen, collect($r->json('data.data'))->pluck('slug')->all());
            $cursor = $this->cursorFromUrl($r->json('data.links.next_page_url'));
        } while ($cursor);

        $this->assertCount(4, $seen);
        $this->assertNotContains('cursor-price-out', $seen);
    }

    public function test_brands_plural_alias_filters(): void
    {
        $brand = Brand::create(['name' => ['en' => 'BrandsAlias'], 'slug' => 'brands-alias']);
        $inBrand = $this->makeProduct(['slug' => 'in-brands-' . uniqid()]);
        $inBrand->brands()->attach($brand->id);
        $other = $this->makeProduct(['slug' => 'other-brands-' . uniqid()]);

        $resp = $this->getJson(self::LIST_URL . '?brands=brands-alias');
        $resp->assertOk();
        $slugs = collect($resp->json('data.data'))->pluck('slug')->all();
        $this->assertContains($inBrand->slug, $slugs);
        $this->assertNotContains($other->slug, $slugs);
    }

    public function test_brands_plural_alias_with_cursor(): void
    {
        $brand = Brand::create(['name' => ['en' => 'CursorBrandsAlias'], 'slug' => 'cursor-brands-alias']);
        foreach (range(1, 3) as $i) {
            $this->makeProduct(['slug' => 'cb-' . uniqid()])->brands()->attach($brand->id);
        }
        $this->makeProduct(['slug' => 'cb-other-' . uniqid()]);

        $seen = [];
        $cursor = null;
        do {
            $qs = '?pagination=cursor&brands=cursor-brands-alias&limit=2' . ($cursor ? '&cursor=' . $cursor : '');
            $r = $this->getJson(self::LIST_URL . $qs);
            $r->assertOk();
            $seen = array_merge($seen, collect($r->json('data.data'))->pluck('id')->all());
            $cursor = $this->cursorFromUrl($r->json('data.links.next_page_url'));
        } while ($cursor);

        $this->assertCount(3, $seen);
    }

    public function test_categories_plural_alias_filters(): void
    {
        $cat = Category::create(['name' => ['en' => 'CatsAlias'], 'slug' => 'cats-alias']);
        $inCat = $this->makeProduct(['slug' => 'in-cats-' . uniqid()]);
        $inCat->categories()->attach($cat->id);
        $other = $this->makeProduct(['slug' => 'other-cats-' . uniqid()]);

        $resp = $this->getJson(self::LIST_URL . '?categories=cats-alias');
        $resp->assertOk();
        $slugs = collect($resp->json('data.data'))->pluck('slug')->all();
        $this->assertContains($inCat->slug, $slugs);
        $this->assertNotContains($other->slug, $slugs);
    }

    public function test_unknown_banner_returns_empty_not_all(): void
    {
        $banner = Banner::create(['title' => ['en' => 'Known Banner'], 'slug' => 'known-banner', 'status' => 1]);
        $withBanner = $this->makeProduct(['slug' => 'with-banner-' . uniqid()]);
        $withBanner->banners()->attach($banner->id);
        $other = $this->makeProduct(['slug' => 'other-banner-' . uniqid()]);

        // known banner returns only matched
        $resp = $this->getJson(self::LIST_URL . '?banner=known-banner');
        $resp->assertOk();
        $this->assertCount(1, $resp->json('data.data'));

        // unknown banner must return empty, not all
        $resp2 = $this->getJson(self::LIST_URL . '?banner=does-not-exist-banner');
        $resp2->assertOk();
        $this->assertSame([], $resp2->json('data.data'));

        // same with cursor
        $resp3 = $this->getJson(self::LIST_URL . '?pagination=cursor&banner=does-not-exist-banner&limit=5');
        $resp3->assertOk();
        $this->assertSame([], $resp3->json('data.data'));
        $this->assertNull($resp3->json('data.links.next_page_url'));
    }

    public function test_unknown_slider_returns_empty(): void
    {
        $this->makeProduct(['slug' => 'slider-other-' . uniqid()]);
        $resp = $this->getJson(self::LIST_URL . '?slider=ghost-slider');
        $resp->assertOk();
        $this->assertSame([], $resp->json('data.data'));
    }

    public function test_filter_plus_cursor_produces_same_total_as_offset(): void
    {
        $brand = Brand::create(['name' => ['en' => 'CountBrand'], 'slug' => 'count-brand']);
        foreach (range(1, 7) as $i) {
            $this->makeProduct(['slug' => 'cnt-' . uniqid()])->brands()->attach($brand->id);
        }
        $this->makeProduct(['slug' => 'cnt-other-' . uniqid()]);

        $offsetResp = $this->getJson(self::LIST_URL . '?brand=count-brand&limit=100');
        $offsetResp->assertOk();
        $offsetIds = collect($offsetResp->json('data.data'))->pluck('id')->all();

        $seen = [];
        $cursor = null;
        do {
            $qs = '?pagination=cursor&brand=count-brand&limit=2' . ($cursor ? '&cursor=' . $cursor : '');
            $r = $this->getJson(self::LIST_URL . $qs);
            $r->assertOk();
            $seen = array_merge($seen, collect($r->json('data.data'))->pluck('id')->all());
            $cursor = $this->cursorFromUrl($r->json('data.links.next_page_url'));
        } while ($cursor);

        sort($offsetIds);
        sort($seen);
        $this->assertSame($offsetIds, $seen, 'Cursor traversal must yield same filtered set as offset');
        $this->assertCount(7, $seen);
        $this->assertSame(count($seen), count(array_unique($seen)));
    }

    public function test_combined_filters_with_cursor(): void
    {
        $cat = Category::create(['name' => ['en' => 'ComboCat'], 'slug' => 'combo-cat']);
        $brand = Brand::create(['name' => ['en' => 'ComboBrand'], 'slug' => 'combo-brand']);
        $match = $this->makeProduct(['slug' => 'combo-match-' . uniqid(), 'price' => 75.0]);
        $match->categories()->attach($cat->id);
        $match->brands()->attach($brand->id);
        $noBrand = $this->makeProduct(['slug' => 'combo-no-brand-' . uniqid(), 'price' => 75.0]);
        $noBrand->categories()->attach($cat->id);
        $outPrice = $this->makeProduct(['slug' => 'combo-out-price-' . uniqid(), 'price' => 500.0]);
        $outPrice->categories()->attach($cat->id);
        $outPrice->brands()->attach($brand->id);

        $resp = $this->getJson(self::LIST_URL . '?pagination=cursor&category=combo-cat&brands=combo-brand&min_price=10&max_price=100&limit=10');
        $resp->assertOk();
        $slugs = collect($resp->json('data.data'))->pluck('slug')->all();
        $this->assertContains($match->slug, $slugs);
        $this->assertNotContains($noBrand->slug, $slugs);
        $this->assertNotContains($outPrice->slug, $slugs);
        $this->assertNull($resp->json('data.links.next_page_url'));
    }
}
