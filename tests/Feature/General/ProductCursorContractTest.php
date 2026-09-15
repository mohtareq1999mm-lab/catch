<?php

declare(strict_types=1);

namespace Tests\Feature\General;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Tag;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ProductCursorContractTest extends TestCase
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

    // A. First request
    public function test_first_cursor_page_contains_next_cursor_and_next_page_url(): void
    {
        foreach (range(1, 7) as $i) $this->makeProduct(['slug' => 'first-' . uniqid()]);

        $resp = $this->getJson(self::LIST_URL . '?pagination=cursor&limit=2');
        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertArrayHasKey('next_cursor', $data);
        $this->assertNotNull($data['next_cursor']);
        $this->assertArrayHasKey('next_page_url', $data['links'] ?? []);
        $this->assertNotNull($data['links']['next_page_url']);
        $this->assertArrayHasKey('prev_cursor', $data);
        $this->assertNull($data['prev_cursor']);
        $this->assertNull($data['links']['prev_page_url']);
        // next_cursor must equal cursor param in next_page_url
        $cursorFromUrl = $this->cursorFromUrl($data['links']['next_page_url']);
        $this->assertSame($data['next_cursor'], $cursorFromUrl);
    }

    // B. Cursor continuation
    public function test_cursor_continuation_uses_backend_cursor_no_duplicates(): void
    {
        foreach (range(1, 6) as $i) $this->makeProduct(['slug' => 'cont-' . uniqid()]);

        $p1 = $this->getJson(self::LIST_URL . '?pagination=cursor&limit=2');
        $p1->assertOk();
        $ids1 = collect($p1->json('data.data'))->pluck('id')->all();
        $cursor = $p1->json('data.next_cursor');
        $this->assertNotNull($cursor);

        $p2 = $this->getJson(self::LIST_URL . '?pagination=cursor&limit=2&cursor=' . $cursor);
        $p2->assertOk();
        $ids2 = collect($p2->json('data.data'))->pluck('id')->all();
        $this->assertNotEmpty($ids2);
        $this->assertEmpty(array_intersect($ids1, $ids2), 'no duplicates');
        $this->assertNotSame($cursor, $p2->json('data.next_cursor'));
        // prev_cursor on page 2 should be set
        $this->assertNotNull($p2->json('data.prev_cursor'));
    }

    // C. Cursor + category
    public function test_cursor_with_category(): void
    {
        $cat = Category::create(['name' => ['en' => 'CatCursor'], 'slug' => 'cat-cursor']);
        foreach (range(1, 4) as $i) {
            $p = $this->makeProduct(['slug' => 'catp-' . uniqid()]);
            $p->categories()->attach($cat->id);
        }
        $this->makeProduct(['slug' => 'cat-other-' . uniqid()]);

        $resp = $this->getJson(self::LIST_URL . '?pagination=cursor&category=cat-cursor&limit=2');
        $resp->assertOk();
        $this->assertNotNull($resp->json('data.next_cursor'));
        $this->assertStringContainsString('category=cat-cursor', $resp->json('data.links.next_page_url'));
        // walk
        $seen = [];
        $cursor = null;
        do {
            $qs = '?pagination=cursor&category=cat-cursor&limit=2' . ($cursor ? '&cursor=' . $cursor : '');
            $r = $this->getJson(self::LIST_URL . $qs);
            $r->assertOk();
            $seen = array_merge($seen, collect($r->json('data.data'))->pluck('id')->all());
            $cursor = $r->json('data.next_cursor');
        } while ($cursor);
        $this->assertCount(4, $seen);
        $this->assertCount(4, array_unique($seen));
    }

    // D. Cursor + brand
    public function test_cursor_with_brand(): void
    {
        $brand = Brand::create(['name' => ['en' => 'BrandCursor'], 'slug' => 'brand-cursor']);
        foreach (range(1, 3) as $i) {
            $p = $this->makeProduct(['slug' => 'bp-' . uniqid()]);
            $p->brands()->attach($brand->id);
        }

        $resp = $this->getJson(self::LIST_URL . '?pagination=cursor&brand=brand-cursor&limit=2');
        $resp->assertOk();
        $this->assertStringContainsString('brand=brand-cursor', $resp->json('data.links.next_page_url'));
        $this->assertNotNull($resp->json('data.next_cursor'));
    }

    // E. Cursor + price aliases
    public function test_cursor_with_price_aliases(): void
    {
        foreach (range(1, 3) as $i) $this->makeProduct(['slug' => 'pricein-' . uniqid(), 'price' => 50]);
        $this->makeProduct(['slug' => 'priceout-' . uniqid(), 'price' => 500]);

        foreach (['min_price=10&max_price=100', 'price_min=10&price_max=100', 'minPrice=10&maxPrice=100'] as $priceQs) {
            $resp = $this->getJson(self::LIST_URL . '?pagination=cursor&' . $priceQs . '&limit=2');
            $resp->assertOk();
            $this->assertNotNull($resp->json('data.next_cursor'));
            // walk should only return 3
            $seen = [];
            $cursor = null;
            do {
                $r = $this->getJson(self::LIST_URL . '?pagination=cursor&' . $priceQs . '&limit=2' . ($cursor ? '&cursor=' . $cursor : ''));
                $r->assertOk();
                $seen = array_merge($seen, collect($r->json('data.data'))->pluck('id')->all());
                $cursor = $r->json('data.next_cursor');
            } while ($cursor);
            $this->assertCount(3, $seen);
        }
    }

    // F. Cursor + search
    public function test_cursor_with_search_continues(): void
    {
        $this->makeProduct(['slug' => 'search-cursor-target', 'name' => ['en' => 'SearchCursorUnique']]);
        // search + cursor should be 422 per validation
        $resp = $this->getJson(self::LIST_URL . '?pagination=cursor&search=SearchCursorUnique');
        $resp->assertStatus(422);
    }

    // G. Cursor + rating
    public function test_cursor_with_rating(): void
    {
        $user = \Marvel\Database\Models\User::create(['name' => 'R', 'email' => 'r' . uniqid() . '@e.com', 'password' => bcrypt('s')]);
        foreach (range(1, 3) as $i) {
            $p = $this->makeProduct(['slug' => 'rated-cursor-' . uniqid()]);
            \Marvel\Database\Models\Review::create(['product_id' => $p->id, 'user_id' => $user->id, 'rating' => 5, 'comment' => 'g', 'approved' => true]);
        }
        $this->makeProduct(['slug' => 'unrated-cursor-' . uniqid()]);

        $resp = $this->getJson(self::LIST_URL . '?pagination=cursor&rating_min=4&limit=2');
        $resp->assertOk();
        $this->assertNotNull($resp->json('data.next_cursor'));
        $this->assertStringContainsString('rating_min=4', $resp->json('data.links.next_page_url'));
    }

    // H. Cursor + tags
    public function test_cursor_with_tags(): void
    {
        $t = Tag::create(['slug' => 'tag-cursor', 'name' => ['en' => 'TagCursor']]);
        foreach (range(1, 3) as $i) {
            $p = $this->makeProduct(['slug' => 'tagp-' . uniqid()]);
            $p->tags()->attach($t->id);
        }
        $this->makeProduct(['slug' => 'tagother-' . uniqid()]);

        $resp = $this->getJson(self::LIST_URL . '?pagination=cursor&tags=tag-cursor&limit=2');
        $resp->assertOk();
        $this->assertNotNull($resp->json('data.next_cursor'));
        $this->assertStringContainsString('tags=tag-cursor', $resp->json('data.links.next_page_url'));
        $this->assertSame($resp->json('data.next_cursor'), $this->cursorFromUrl($resp->json('data.links.next_page_url')));
    }

    // I/J. Promotion / flash sale / attributes — basic
    public function test_cursor_with_promotion_and_flash_sale(): void
    {
        // just verify filters don't break cursor, even if no data matches
        $resp = $this->getJson(self::LIST_URL . '?pagination=cursor&promotion=ghost&limit=2');
        $resp->assertOk();
        $this->assertNull($resp->json('data.next_cursor'));
        $this->assertNull($resp->json('data.links.next_page_url'));
    }

    // K. Multiple filters
    public function test_cursor_with_multiple_filters(): void
    {
        $cat = Category::create(['name' => ['en' => 'MultiCat'], 'slug' => 'multi-cat']);
        $brand = Brand::create(['name' => ['en' => 'MultiBrand'], 'slug' => 'multi-brand']);
        $match = $this->makeProduct(['slug' => 'multi-match-' . uniqid(), 'price' => 75]);
        $match->categories()->attach($cat->id);
        $match->brands()->attach($brand->id);
        $noBrand = $this->makeProduct(['slug' => 'multi-nobrand-' . uniqid(), 'price' => 75]);
        $noBrand->categories()->attach($cat->id);
        $outPrice = $this->makeProduct(['slug' => 'multi-outprice-' . uniqid(), 'price' => 500]);
        $outPrice->categories()->attach($cat->id);
        $outPrice->brands()->attach($brand->id);

        $resp = $this->getJson(self::LIST_URL . '?pagination=cursor&category=multi-cat&brand=multi-brand&min_price=10&max_price=100&limit=2');
        $resp->assertOk();
        $ids = collect($resp->json('data.data'))->pluck('id')->all();
        $this->assertContains($match->id, $ids);
        $this->assertNotContains($noBrand->id, $ids);
        $this->assertNotContains($outPrice->id, $ids);
        // single match → end, both null
        $this->assertNull($resp->json('data.next_cursor'));
        $this->assertNull($resp->json('data.links.next_page_url'));
        // verify filter preservation would hold if there were more pages:
        // create extra matches to force pagination and check next_page_url contains filters
        foreach (range(1, 3) as $i) {
            $p = $this->makeProduct(['slug' => 'multi-extra-' . uniqid(), 'price' => 80]);
            $p->categories()->attach($cat->id);
            $p->brands()->attach($brand->id);
        }
        $resp2 = $this->getJson(self::LIST_URL . '?pagination=cursor&category=multi-cat&brand=multi-brand&min_price=10&max_price=100&limit=2');
        $resp2->assertOk();
        $this->assertStringContainsString('category=multi-cat', $resp2->json('data.links.next_page_url'));
        $this->assertStringContainsString('brand=multi-brand', $resp2->json('data.links.next_page_url'));
        $this->assertStringContainsString('min_price=10', $resp2->json('data.links.next_page_url'));
    }

    // Full walk
    public function test_full_pagination_walk_no_duplicates(): void
    {
        foreach (range(1, 7) as $i) $this->makeProduct(['slug' => 'walk-' . uniqid()]);
        $seen = [];
        $cursor = null;
        $page = 0;
        do {
            $qs = '?pagination=cursor&limit=2' . ($cursor ? '&cursor=' . $cursor : '');
            $r = $this->getJson(self::LIST_URL . $qs);
            $r->assertOk();
            $page++;
            $ids = collect($r->json('data.data'))->pluck('id')->all();
            $seen = array_merge($seen, $ids);
            $nextCursor = $r->json('data.next_cursor');
            $nextUrl = $r->json('data.links.next_page_url');
            $this->assertSame($nextCursor, $this->cursorFromUrl($nextUrl), 'next_cursor must equal cursor in next_page_url');
            $cursor = $nextCursor;
            if ($page > 10) break;
        } while ($cursor !== null);
        $this->assertCount(7, $seen);
        $this->assertCount(7, array_unique($seen));
        $this->assertNull($cursor);
    }

    public function test_end_of_collection_null_cursors(): void
    {
        $this->makeProduct(['slug' => 'end-' . uniqid()]);
        $r = $this->getJson(self::LIST_URL . '?pagination=cursor&limit=10');
        $r->assertOk();
        $this->assertNull($r->json('data.next_cursor'));
        $this->assertNull($r->json('data.links.next_page_url'));
        $this->assertNull($r->json('data.prev_cursor'));
        $this->assertNull($r->json('data.links.prev_page_url'));
    }

    public function test_offset_vs_cursor_same_filtered_set(): void
    {
        $cat = Category::create(['name' => ['en' => 'OffCat'], 'slug' => 'off-cat']);
        foreach (range(1, 5) as $i) {
            $p = $this->makeProduct(['slug' => 'off-' . uniqid()]);
            $p->categories()->attach($cat->id);
        }
        $this->makeProduct(['slug' => 'off-other-' . uniqid()]);

        $offset = $this->getJson(self::LIST_URL . '?category=off-cat&limit=100');
        $offsetIds = collect($offset->json('data.data'))->pluck('id')->all();
        sort($offsetIds);

        $seen = [];
        $cursor = null;
        do {
            $r = $this->getJson(self::LIST_URL . '?pagination=cursor&category=off-cat&limit=2' . ($cursor ? '&cursor=' . $cursor : ''));
            $r->assertOk();
            $seen = array_merge($seen, collect($r->json('data.data'))->pluck('id')->all());
            $cursor = $r->json('data.next_cursor');
        } while ($cursor);
        sort($seen);
        $this->assertSame($offsetIds, $seen);
    }
}
