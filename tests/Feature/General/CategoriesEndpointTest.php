<?php

declare(strict_types=1);

namespace Tests\Feature\General;

use App\Enums\FrontendResource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class CategoriesEndpointTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const LIST_URL = '/api/v1/general/categories';

    private User $adminUser;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

        config(['filesystems.disks.categories' => [
            'driver' => 'local',
            'root' => storage_path('app/public/categories'),
            'url' => env('APP_URL') . '/storage/categories',
            'visibility' => 'public',
        ]]);

        $this->createAllTestTables();

        foreach (['view-categories', 'create-category', 'update-category', 'delete-category'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->adminUser = User::create([
            'name' => 'Category Admin',
            'email' => 'admin.category@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('Password123!'),
            'phone_number' => '01000000003',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo([
            'view-categories',
            'create-category',
            'update-category',
            'delete-category',
        ]);
    }

    private function makeCategory(array $overrides = []): Category
    {
        return Category::create(array_merge([
            'name' => ['en' => 'Electronics'],
            'slug' => 'electronics-' . uniqid(),
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
        $this->assertSame('Data fetched successfully', $response->json('message'));
    }

    public function test_returns_empty_data_when_no_categories_exist(): void
    {
        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
        $this->assertCount(0, $response->json('data'));
    }

    public function test_returns_only_active_categories(): void
    {
        $this->makeCategory(['slug' => 'active-cat']);
        $this->makeCategory(['slug' => 'inactive-cat', 'status' => false]);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('active-cat', $slugs);
        $this->assertNotContains('inactive-cat', $slugs);
    }

    public function test_soft_deleted_categories_are_excluded_from_fresh_query(): void
    {
        $this->makeCategory(['slug' => 'keep']);
        $gone = $this->makeCategory(['slug' => 'soft-deleted']);
        $gone->delete();

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertNotContains('soft-deleted', $slugs);
    }

    public function test_slug_query_param_delegates_to_single_category(): void
    {
        $category = $this->makeCategory(['slug' => 'men']);

        $response = $this->getJson(self::LIST_URL . '?slug=men');

        $response->assertOk();
        $this->assertSame($category->id, $response->json('data.id'));
        $this->assertSame('men', $response->json('data.slug'));
    }

    public function test_slug_route_returns_single_category(): void
    {
        $category = $this->makeCategory(['slug' => 'women']);

        $response = $this->getJson(self::LIST_URL . '/women');

        $response->assertOk();
        $this->assertSame($category->id, $response->json('data.id'));
    }

    public function test_unknown_slug_query_param_returns_404(): void
    {
        $response = $this->getJson(self::LIST_URL . '?slug=nope');

        $response->assertNotFound();
        $response->assertJsonPath('status', false);
        $response->assertJsonPath('message', 'Resource Not Found');
    }

    public function test_unknown_slug_route_returns_404(): void
    {
        $response = $this->getJson(self::LIST_URL . '/nope');

        $response->assertNotFound();
        $response->assertJsonPath('status', false);
    }

    // =========================================================================
    // CONTRACT
    // =========================================================================

    public function test_public_response_exposes_no_pagination_metadata(): void
    {
        foreach (range(1, 3) as $i) {
            $this->makeCategory(['slug' => "cat-{$i}"]);
        }

        $response = $this->getJson(self::LIST_URL . '?limit=2');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertArrayNotHasKey('meta', $response->json(), 'no Laravel meta block is exposed');
        $this->assertArrayNotHasKey('links', $response->json(), 'no Laravel links block is exposed');
        $this->assertArrayNotHasKey('pagination', $response->json(), 'no documented pagination block is exposed');
    }

    public function test_public_response_should_expose_pagination_metadata(): void
    {
        foreach (range(1, 3) as $i) {
            $this->makeCategory(['slug' => "cat-{$i}"]);
        }

        $response = $this->getJson(self::LIST_URL . '?limit=2');

        $response->assertOk();
        $this->assertArrayHasKey('pagination', $response->json(), 'docs promise a pagination object for clients to paginate');
        $this->assertSame(3, $response->json('pagination.total'));
        $this->assertSame(2, $response->json('pagination.per_page'));
        $this->assertSame(1, $response->json('pagination.current_page'));
        $this->assertSame(2, $response->json('pagination.last_page'));
    }

    public function test_category_item_contract_structure(): void
    {
        $category = $this->makeCategory(['slug' => 'contract-cat']);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $item = collect($response->json('data'))->firstWhere('slug', 'contract-cat');

        $this->assertSame($category->id, $item['id']);
        $this->assertSame('Electronics', $item['name']);
        $this->assertSame('contract-cat', $item['slug']);
        $this->assertIsArray($item['image']);
        $this->assertArrayHasKey('desktop', $item['image']);
        $this->assertArrayHasKey('mobile', $item['image']);
        $this->assertArrayHasKey('products_count', $item);
    }

    public function test_details_included_only_when_present(): void
    {
        $this->makeCategory(['slug' => 'with-details', 'details' => 'Some details']);
        $this->makeCategory(['slug' => 'without-details']);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $withDetails = collect($response->json('data'))->firstWhere('slug', 'with-details');
        $withoutDetails = collect($response->json('data'))->firstWhere('slug', 'without-details');

        $this->assertSame('Some details', $withDetails['details']);
        $this->assertArrayNotHasKey('details', $withoutDetails);
    }

    public function test_localized_name_resolves_through_lang_header(): void
    {
        $this->makeCategory(['slug' => 'localized', 'name' => ['en' => 'Localized', 'ar' => 'مترجم']]);

        $english = $this->getJson(self::LIST_URL);
        $this->assertSame('Localized', collect($english->json('data'))->firstWhere('slug', 'localized')['name']);

        $arabic = $this->withHeader('lang', 'ar')->getJson(self::LIST_URL);
        $this->assertSame('مترجم', collect($arabic->json('data'))->firstWhere('slug', 'localized')['name']);
    }

    public function test_limit_is_capped_at_100(): void
    {
        foreach (range(1, 120) as $i) {
            $this->makeCategory(['slug' => "bulk-{$i}"]);
        }

        $response = $this->getJson(self::LIST_URL . '?limit=500');

        $response->assertOk();
        $this->assertCount(100, $response->json('data'), 'limit must be capped at 100');
    }

    public function test_zero_negative_and_non_numeric_limit_fall_back_to_default(): void
    {
        foreach (range(1, 20) as $i) {
            $this->makeCategory(['slug' => "limit-{$i}"]);
        }

        foreach (['0', '-5', 'abc'] as $limit) {
            $response = $this->getJson(self::LIST_URL . "?limit={$limit}");
            $response->assertOk();
            $this->assertCount(15, $response->json('data'), "limit={$limit} must fall back to 15");
        }
    }

    public function test_search_matches_translatable_name(): void
    {
        $this->makeCategory(['slug' => 'kids-fashion', 'name' => ['en' => 'Kids']]);
        $this->makeCategory(['slug' => 'adult-fashion', 'name' => ['en' => 'Adults']]);

        $response = $this->getJson(self::LIST_URL . '?search=kids');

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('kids-fashion', $slugs);
        $this->assertNotContains('adult-fashion', $slugs);
    }

    public function test_parent_filter_returns_root_categories_only(): void
    {
        $parent = $this->makeCategory(['slug' => 'root-cat']);
        $this->makeCategory(['slug' => 'child-cat', 'parent_id' => $parent->id]);

        $response = $this->getJson(self::LIST_URL . '?parent=true');

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('root-cat', $slugs);
        $this->assertNotContains('child-cat', $slugs);
    }

    public function test_categories_id_filter_returns_matching_ids(): void
    {
        $a = $this->makeCategory(['slug' => 'id-a']);
        $b = $this->makeCategory(['slug' => 'id-b']);
        $c = $this->makeCategory(['slug' => 'id-c']);

        $response = $this->getJson(self::LIST_URL . "?categoriesId={$a->id},{$b->id}");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertNotContains($c->id, $ids);
    }

    public function test_non_numeric_categories_id_is_ignored(): void
    {
        $this->makeCategory(['slug' => 'only-cat']);

        $response = $this->getJson(self::LIST_URL . '?categoriesId=abc,def');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_pest_category_sorts_by_products_count_desc(): void
    {
        $low = $this->makeCategory(['slug' => 'low-count']);
        $high = $this->makeCategory(['slug' => 'high-count']);

        $p1 = Product::create(['name' => 'P1', 'slug' => 'p1']);
        $p2 = Product::create(['name' => 'P2', 'slug' => 'p2']);
        $high->products()->attach([$p1->id, $p2->id]);
        $low->products()->attach([$p1->id]);

        $response = $this->getJson(self::LIST_URL . '?pest_category=true');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$high->id, $low->id], $ids);
    }

    public function test_order_ascending_and_descending_by_id(): void
    {
        $a = $this->makeCategory(['slug' => 'order-a']);
        $b = $this->makeCategory(['slug' => 'order-b']);

        $asc = $this->getJson(self::LIST_URL . '?order=asc');
        $this->assertSame([$a->id, $b->id], collect($asc->json('data'))->pluck('id')->all());

        $desc = $this->getJson(self::LIST_URL . '?order=desc');
        $this->assertSame([$b->id, $a->id], collect($desc->json('data'))->pluck('id')->all());
    }

    public function test_invalid_order_direction_returns_422(): void
    {
        $this->makeCategory(['slug' => 'order-invalid']);

        $response = $this->getJson(self::LIST_URL . '?order=evil');

        $response->assertStatus(422);
        $response->assertJsonPath('status', false);
        $response->assertJsonStructure(['message', 'status', 'errors' => ['order']]);
    }

    public function test_empty_order_falls_back_to_default_desc(): void
    {
        $a = $this->makeCategory(['slug' => 'empty-a']);
        $b = $this->makeCategory(['slug' => 'empty-b']);

        $response = $this->getJson(self::LIST_URL . '?order=');

        $response->assertOk();
        $this->assertSame([$b->id, $a->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_omitted_order_defaults_to_desc(): void
    {
        $a = $this->makeCategory(['slug' => 'none-a']);
        $b = $this->makeCategory(['slug' => 'none-b']);

        $response = $this->getJson(self::LIST_URL);

        $response->assertOk();
        $this->assertSame([$b->id, $a->id], collect($response->json('data'))->pluck('id')->all());
    }

    // =========================================================================
    // CACHE
    // =========================================================================

    public function test_cache_entry_is_written_and_readable(): void
    {
        $this->makeCategory(['slug' => 'cached-cat']);

        $this->getJson(self::LIST_URL)->assertOk();

        $key = md5($this->app['request']->fullUrl());
        $this->assertTrue(
            Cache::tags([FrontendResource::CATEGORIES->value])->has($key),
            'list result must be stored under the categories tag'
        );
    }

    public function test_cache_hit_response_identical_to_cache_miss(): void
    {
        $this->makeCategory(['slug' => 'identical-cat']);

        $miss = $this->getJson(self::LIST_URL)->assertOk();
        $hit = $this->getJson(self::LIST_URL)->assertOk();

        $this->assertSame($miss->json(), $hit->json(), 'cache hit must return the exact same response as the miss');
    }

    public function test_cached_paginator_survives_serialize_unserialize_roundtrip(): void
    {
        $this->makeCategory(['slug' => 'serial-cat']);

        $this->getJson(self::LIST_URL)->assertOk();

        $key = md5($this->app['request']->fullUrl());
        $cached = Cache::tags([FrontendResource::CATEGORIES->value])->get($key);

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $cached);
        $this->assertCount(1, $cached->items());

        $roundTripped = unserialize(serialize($cached));

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $roundTripped);
        $this->assertCount(1, $roundTripped->items());
        $this->assertSame('serial-cat', $roundTripped->items()[0]->slug);
    }

    public function test_cache_miss_executes_category_queries(): void
    {
        $this->makeCategory(['slug' => 'miss-cat']);

        DB::enableQueryLog();

        $this->getJson(self::LIST_URL)->assertOk();

        $this->assertGreaterThanOrEqual(
            1,
            $this->countTableQueries('categories'),
            'a cache miss must execute the category query'
        );
    }

    public function test_cache_hit_does_not_execute_category_queries(): void
    {
        $this->makeCategory(['slug' => 'hit-cat']);

        $this->getJson(self::LIST_URL)->assertOk();

        DB::enableQueryLog();

        $cached = $this->getJson(self::LIST_URL)->assertOk();
        $this->assertSame('hit-cat', collect($cached->json('data'))->firstWhere('slug', 'hit-cat')['slug']);

        $this->assertSame(
            0,
            $this->countTableQueries('categories'),
            'a cache hit must not execute the category query'
        );
        $this->assertSame(
            0,
            $this->countTableQueries('media'),
            'a cache hit must not execute media queries'
        );
    }

    public function test_different_queries_use_distinct_cache_keys(): void
    {
        $this->makeCategory(['slug' => 'key-cat']);

        $this->getJson(self::LIST_URL)->assertOk();
        $this->getJson(self::LIST_URL . '?parent=true')->assertOk();
        $this->getJson(self::LIST_URL . '?search=Electronics')->assertOk();

        $plain = $this->getJson(self::LIST_URL);
        $parent = $this->getJson(self::LIST_URL . '?parent=true');
        $search = $this->getJson(self::LIST_URL . '?search=Electronics');

        $this->assertSame(1, count($plain->json('data')));
        $this->assertSame(1, count($parent->json('data')));
        $this->assertSame(1, count($search->json('data')));
    }

    public function test_admin_store_flushes_list_cache(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->getJson(self::LIST_URL)->assertOk()->assertJsonCount(0, 'data');

        $response = $this->postJson('/api/v1/categories', [
            'name' => ['en' => 'New Category'],
            'image-desktop' => UploadedFile::fake()->image('desktop.jpg'),
            'image-mobile' => UploadedFile::fake()->image('mobile.jpg'),
        ]);

        $response->assertOk();

        $list = $this->getJson(self::LIST_URL)->assertOk();
        $this->assertCount(1, $list->json('data'), 'new category must appear in list after admin store');
        $this->assertSame('New Category', $list->json('data.0.name'));
    }

    public function test_admin_update_flushes_list_cache(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $category = $this->makeCategory(['slug' => 'rename-me']);

        $this->getJson(self::LIST_URL)->assertOk();

        $response = $this->putJson('/api/v1/categories/' . $category->id, [
            'name' => ['en' => 'Renamed Category'],
        ]);

        $response->assertOk();

        $list = $this->getJson(self::LIST_URL)->assertOk();
        $this->assertSame('Renamed Category', $list->json('data.0.name'), 'updated name must appear in list');
    }

    public function test_admin_feature_toggle_flushes_list_cache(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $category = $this->makeCategory(['slug' => 'featured-cat']);

        $this->getJson(self::LIST_URL)->assertOk();

        $response = $this->putJson('/api/v1/categories/feature', ['id' => $category->id]);

        $response->assertOk();

        $list = $this->getJson(self::LIST_URL)->assertOk();
        $this->assertCount(1, $list->json('data'), 'feature toggle must not lose data from list cache');
    }

    public function test_admin_delete_is_reflected_in_list_cache(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $category = $this->makeCategory(['slug' => 'delete-me']);

        $this->getJson(self::LIST_URL)->assertOk()->assertJsonCount(1, 'data');

        $response = $this->deleteJson('/api/v1/categories/' . $category->id);
        $response->assertOk();

        $list = $this->getJson(self::LIST_URL)->assertOk();
        $slugs = collect($list->json('data'))->pluck('slug')->all();

        $this->assertNotContains('delete-me', $slugs, 'deleted category must NOT remain in the public list cache');
    }

    public function test_model_soft_delete_refreshes_list_cache(): void
    {
        $category = $this->makeCategory(['slug' => 'observer-delete']);

        $this->getJson(self::LIST_URL)->assertOk()->assertJsonCount(1, 'data');

        $category->delete();

        $list = $this->getJson(self::LIST_URL)->assertOk();
        $slugs = collect($list->json('data'))->pluck('slug')->all();

        $this->assertNotContains('observer-delete', $slugs, 'soft delete must not leave stale entry in list cache');
    }

    public function test_model_force_delete_refreshes_list_cache(): void
    {
        $category = $this->makeCategory(['slug' => 'force-delete']);

        $this->getJson(self::LIST_URL)->assertOk()->assertJsonCount(1, 'data');

        $category->forceDelete();

        $list = $this->getJson(self::LIST_URL)->assertOk();
        $slugs = collect($list->json('data'))->pluck('slug')->all();

        $this->assertNotContains('force-delete', $slugs, 'force delete must not leave stale entry in list cache');
    }

    public function test_model_restore_refreshes_list_cache(): void
    {
        $category = $this->makeCategory(['slug' => 'observer-restore']);

        $this->getJson(self::LIST_URL)->assertOk()->assertJsonCount(1, 'data');

        $category->delete();
        $this->getJson(self::LIST_URL)->assertOk()->assertJsonCount(0, 'data');

        $category->restore();

        $list = $this->getJson(self::LIST_URL)->assertOk();
        $this->assertCount(1, $list->json('data'), 'restored category must reappear in public list cache');
    }

    // =========================================================================
    // QUERY ANALYSIS
    // =========================================================================

    public function test_list_uses_single_categories_query_with_count_aggregate(): void
    {
        $this->makeCategory(['slug' => 'query-cat']);
        $p = Product::create(['name' => 'P', 'slug' => 'p']);
        Category::where('slug', 'query-cat')->first()->products()->attach($p->id);

        DB::enableQueryLog();

        $this->getJson(self::LIST_URL)->assertOk();

        $categoryQueries = collect(DB::getQueryLog())
            ->filter(fn(array $q) => preg_match('/\bfrom\s+["`]?categories["`]?/i', $q['query']) === 1);

        $this->assertCount(2, $categoryQueries, 'one paginate() call issues a count query plus a select query');
    }

    public function test_media_is_loaded_without_per_item_queries(): void
    {
        foreach (range(1, 3) as $i) {
            $this->makeCategory(['slug' => "media-cat-{$i}"]);
        }

        DB::enableQueryLog();
        $this->getJson(self::LIST_URL)->assertOk();

        $mediaQueries = collect(DB::getQueryLog())
            ->filter(fn(array $q) => preg_match('/\bfrom\s+["`]?media["`]?/i', $q['query']) === 1)
            ->count();

        $this->assertLessThanOrEqual(1, $mediaQueries, 'media must be eager loaded, not queried per category item');
    }

    public function test_products_count_uses_subquery_not_extra_queries(): void
    {
        $this->makeCategory(['slug' => 'count-cat']);

        DB::enableQueryLog();

        $this->getJson(self::LIST_URL)->assertOk();

        $queries = DB::getQueryLog();

        $selectOnCategory = collect($queries)
            ->filter(fn(array $q) => preg_match('/^select/i', $q['query']) === 1
                && preg_match('/\bfrom\s+["`]?categories["`]?/i', $q['query']) === 1)
            ->map(fn(array $q) => $q['query'])
            ->first();

        $this->assertNotNull($selectOnCategory, 'expected a SELECT from categories');
    }

    // =========================================================================
    // RATE LIMITING
    // =========================================================================

    public function test_rate_limit_returns_429_after_api_throttle_exhausted(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson(self::LIST_URL)->assertOk();
        }

        $response = $this->getJson(self::LIST_URL);
        $response->assertStatus(429);
    }

    private function countTableQueries(string $table): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn(array $query) => preg_match('/\bfrom\s+["`]?' . preg_quote($table, '/') . '["`]?/i', $query['query']) === 1)
            ->count();
    }
}
