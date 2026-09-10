<?php

declare(strict_types=1);

namespace Tests\Feature\General;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Category;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class NavDataEndpointTest extends TestCase
{
    use RefreshDatabase, CreatesTestTables;

    private const PREFIX = '/api/v1/general/nav-data';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAllTestTables();

        app()->setLocale('en');
    }

    public function test_public_endpoint_requires_no_authentication(): void
    {
        $response = $this->getJson(self::PREFIX);

        $response->assertOk();
        $response->assertJsonStructure(['status', 'message', 'success', 'data']);
        $this->assertTrue($response->json('success'));
        $this->assertSame('Data fetched successfully', $response->json('message'));
    }

    public function test_returns_empty_data_when_no_categories_exist(): void
    {
        $response = $this->getJson(self::PREFIX);

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
        $this->assertCount(0, $response->json('data'));
    }

    public function test_returns_full_three_level_nav_tree_by_default(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        $child = Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        Category::create([
            'name' => ['en' => 'Grandchild'],
            'slug' => 'grandchild',
            'parent_id' => $child->id,
        ]);

        $response = $this->getJson(self::PREFIX);

        $response->assertOk();
        $parentNode = collect($response->json('data'))->firstWhere('slug', 'parent');

        $this->assertNotNull($parentNode, 'parent category must appear in nav data');
        $this->assertSame(1, $parentNode['level']);
        $this->assertCount(1, $parentNode['children']);

        $childNode = $parentNode['children'][0];
        $this->assertSame('child', $childNode['slug']);
        $this->assertSame(2, $childNode['level']);
        $this->assertCount(1, $childNode['children']);
        $this->assertSame('grandchild', $childNode['children'][0]['slug']);
    }

    public function test_level_one_returns_top_level_categories_only(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        $response = $this->getJson(self::PREFIX . '?level=1');

        $response->assertOk();
        $parentNode = collect($response->json('data'))->firstWhere('slug', 'parent');
        $this->assertSame([], $parentNode['children']);
    }

    public function test_level_two_returns_two_levels_only(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        $child = Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        Category::create([
            'name' => ['en' => 'Grandchild'],
            'slug' => 'grandchild',
            'parent_id' => $child->id,
        ]);

        $response = $this->getJson(self::PREFIX . '?level=2');

        $response->assertOk();
        $parentNode = collect($response->json('data'))->firstWhere('slug', 'parent');
        $this->assertCount(1, $parentNode['children']);
        $this->assertSame([], $parentNode['children'][0]['children']);
    }

    public function test_name_resolves_in_requested_locale(): void
    {
        Category::create([
            'name' => ['en' => 'Electronics', 'ar' => 'إلكترونيات'],
            'slug' => 'electronics',
        ]);

        $english = $this->getJson(self::PREFIX);
        $englishNode = collect($english->json('data'))->firstWhere('slug', 'electronics');
        $this->assertSame('Electronics', $englishNode['name']);

        $arabic = $this->withHeader('lang', 'ar')->getJson(self::PREFIX);
        $arabicNode = collect($arabic->json('data'))->firstWhere('slug', 'electronics');
        $this->assertSame('إلكترونيات', $arabicNode['name']);
    }

    public function test_inactive_categories_are_excluded(): void
    {
        Category::create([
            'name' => ['en' => 'Active Cat'],
            'slug' => 'active-cat',
        ]);

        Category::create([
            'name' => ['en' => 'Inactive Cat'],
            'slug' => 'inactive-cat',
            'status' => false,
        ]);

        $response = $this->getJson(self::PREFIX);

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('active-cat', $slugs);
        $this->assertNotContains('inactive-cat', $slugs);
    }

    public function test_soft_deleted_categories_are_excluded(): void
    {
        $category = Category::create([
            'name' => ['en' => 'To Be Deleted'],
            'slug' => 'to-be-deleted',
        ]);

        $category->delete();

        $response = $this->getJson(self::PREFIX);

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_level_three_returns_full_tree(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        $child = Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        Category::create([
            'name' => ['en' => 'Grandchild'],
            'slug' => 'grandchild',
            'parent_id' => $child->id,
        ]);

        $response = $this->getJson(self::PREFIX . '?level=3');

        $response->assertOk();
        $parentNode = collect($response->json('data'))->firstWhere('slug', 'parent');
        $this->assertCount(1, $parentNode['children']);
        $this->assertCount(1, $parentNode['children'][0]['children']);
    }

    public function test_zero_level_behaves_like_default_full_tree(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        $child = Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        Category::create([
            'name' => ['en' => 'Grandchild'],
            'slug' => 'grandchild',
            'parent_id' => $child->id,
        ]);

        $noLevel = $this->getJson(self::PREFIX);
        $zero = $this->getJson(self::PREFIX . '?level=0');

        $noLevelParent = collect($noLevel->json('data'))->firstWhere('slug', 'parent');
        $zeroParent = collect($zero->json('data'))->firstWhere('slug', 'parent');

        $this->assertCount(1, $noLevelParent['children']);
        $this->assertCount(1, $zeroParent['children']);
        $this->assertCount(1, $zeroParent['children'][0]['children']);
    }

    public function test_negative_level_behaves_like_default_full_tree(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        $response = $this->getJson(self::PREFIX . '?level=-5');

        $response->assertOk();
        $parentNode = collect($response->json('data'))->firstWhere('slug', 'parent');
        $this->assertCount(1, $parentNode['children']);
    }

    public function test_non_numeric_level_behaves_like_default_full_tree(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        $response = $this->getJson(self::PREFIX . '?level=abc');

        $response->assertOk();
        $parentNode = collect($response->json('data'))->firstWhere('slug', 'parent');
        $this->assertCount(1, $parentNode['children']);
    }

    public function test_rate_limit_returns_429_after_api_throttle_exhausted(): void
    {
        $limit = config('cache.default') ? 60 : 60;

        for ($i = 0; $i < $limit; $i++) {
            $this->getJson(self::PREFIX)->assertOk();
        }

        $response = $this->getJson(self::PREFIX);
        $response->assertStatus(429);
    }

    public function test_category_creation_invalidates_nav_cache_and_returns_fresh_data(): void
    {
        Category::create([
            'name' => ['en' => 'First'],
            'slug' => 'first',
        ]);

        $first = $this->getJson(self::PREFIX);
        $this->assertCount(1, $first->json('data'));

        Category::create([
            'name' => ['en' => 'Second'],
            'slug' => 'second',
        ]);

        $second = $this->getJson(self::PREFIX);
        $this->assertCount(2, $second->json('data'), 'new category must appear after cache invalidation');
    }

    public function test_category_update_invalidates_nav_cache_and_returns_fresh_data(): void
    {
        $category = Category::create([
            'name' => ['en' => 'Original Name'],
            'slug' => 'category',
        ]);

        $first = $this->getJson(self::PREFIX);
        $this->assertSame('Original Name', collect($first->json('data'))->firstWhere('id', $category->id)['name']);

        $category->update(['name' => ['en' => 'Renamed']]);

        $second = $this->getJson(self::PREFIX);
        $this->assertSame('Renamed', collect($second->json('data'))->firstWhere('id', $category->id)['name']);
    }

    public function test_category_delete_invalidates_nav_cache(): void
    {
        $category = Category::create([
            'name' => ['en' => 'To Be Deleted'],
            'slug' => 'to-be-deleted',
        ]);

        $this->getJson(self::PREFIX)->assertOk()->assertJsonCount(1, 'data');

        $category->delete();

        $this->getJson(self::PREFIX)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_category_deactivation_invalidates_nav_cache(): void
    {
        $category = Category::create([
            'name' => ['en' => 'To Be Deactivated'],
            'slug' => 'to-be-deactivated',
        ]);

        $this->getJson(self::PREFIX)->assertOk()->assertJsonCount(1, 'data');

        $category->update(['status' => false]);

        $this->getJson(self::PREFIX)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_category_restore_invalidates_nav_cache(): void
    {
        $category = Category::create([
            'name' => ['en' => 'To Be Restored'],
            'slug' => 'to-be-restored',
        ]);

        $this->getJson(self::PREFIX)->assertOk()->assertJsonCount(1, 'data');

        $category->delete();
        $this->getJson(self::PREFIX)->assertOk()->assertJsonCount(0, 'data');

        $category->restore();
        $this->getJson(self::PREFIX)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_category_mutation_invalidates_all_level_variants(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        foreach ([1, 2, 3] as $level) {
            $this->getJson(self::PREFIX . "?level={$level}")->assertOk();
        }

        $parent->update(['name' => ['en' => 'Parent Renamed']]);

        foreach ([1, 2, 3] as $level) {
            $response = $this->getJson(self::PREFIX . "?level={$level}")->assertOk();
            $node = collect($response->json('data'))->firstWhere('id', $parent->id);
            $this->assertSame('Parent Renamed', $node['name'], "level {$level} cache must be invalidated");
        }
    }

    public function test_different_levels_do_not_share_cache_entries(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        $child = Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        Category::create([
            'name' => ['en' => 'Grandchild'],
            'slug' => 'grandchild',
            'parent_id' => $child->id,
        ]);

        $levelOne = $this->getJson(self::PREFIX . '?level=1')->assertOk();
        $levelOneParent = collect($levelOne->json('data'))->firstWhere('slug', 'parent');
        $this->assertSame([], $levelOneParent['children']);

        $levelThree = $this->getJson(self::PREFIX . '?level=3')->assertOk();
        $levelThreeParent = collect($levelThree->json('data'))->firstWhere('slug', 'parent');
        $this->assertCount(1, $levelThreeParent['children']);
        $this->assertCount(1, $levelThreeParent['children'][0]['children']);
    }

    public function test_same_level_is_served_from_cache_on_second_request(): void
    {
        Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        $this->getJson(self::PREFIX . '?level=1')->assertOk();

        DB::enableQueryLog();

        $cached = $this->getJson(self::PREFIX . '?level=1')->assertOk();
        $this->assertSame('Parent', collect($cached->json('data'))->firstWhere('slug', 'parent')['name']);
        $this->assertSame(0, $this->countCategoriesQueries(), 'second level=1 request must come from cache');
    }

    public function test_level_one_query_does_not_load_children(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        DB::enableQueryLog();

        $this->getJson(self::PREFIX . '?level=1')->assertOk();

        $this->assertSame(1, $this->countCategoriesQueries(), 'level=1 must issue exactly one categories query');
    }

    public function test_level_two_query_does_not_load_grandchildren(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        $child = Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        Category::create([
            'name' => ['en' => 'Grandchild'],
            'slug' => 'grandchild',
            'parent_id' => $child->id,
        ]);

        DB::enableQueryLog();

        $this->getJson(self::PREFIX . '?level=2')->assertOk();

        $this->assertSame(2, $this->countCategoriesQueries(), 'level=2 must issue exactly two categories queries');
    }

    public function test_level_three_query_loads_full_hierarchy(): void
    {
        $parent = Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        $child = Category::create([
            'name' => ['en' => 'Child'],
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        Category::create([
            'name' => ['en' => 'Grandchild'],
            'slug' => 'grandchild',
            'parent_id' => $child->id,
        ]);

        DB::enableQueryLog();

        $this->getJson(self::PREFIX . '?level=3')->assertOk();

        $this->assertSame(3, $this->countCategoriesQueries(), 'level=3 must issue exactly three categories queries');
    }

    public function test_no_outer_controller_cache_can_serve_stale_data(): void
    {
        Category::create([
            'name' => ['en' => 'Parent'],
            'slug' => 'parent',
        ]);

        $this->getJson(self::PREFIX . '?level=3')->assertOk()->assertJsonCount(1, 'data');

        Category::create([
            'name' => ['en' => 'Second'],
            'slug' => 'second',
        ]);

        $response = $this->getJson(self::PREFIX . '?level=3')->assertOk();
        $this->assertCount(2, $response->json('data'), 'no second cache layer may retain stale nav data');
    }

    private function countCategoriesQueries(): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn(array $query) => preg_match('/\bfrom\s+["`]?categories["`]?/i', $query['query']) === 1)
            ->count();
    }
}
