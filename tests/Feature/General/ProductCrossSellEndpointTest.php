<?php

declare(strict_types=1);

namespace Tests\Feature\General;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Storefront contract for manual cross-selling (plan rev.3 §9/§15.6).
 *
 * Invariants under test (Q-01/Q-02/Q-03):
 * - `eligible_cross_sell_count` + `cross_sell_products` are ALWAYS present,
 *   including the exact zero fragment `0` / `[]` (never omitted, never null);
 * - `cross_sell_products` is eligible-only (availability + CURRENT channel);
 * - `eligible_cross_sell_count === count(cross_sell_products)` and the
 *   storefront count shares the 8-item display cap;
 * - already-attached soft-deleted targets never surface on Storefront.
 */
class ProductCrossSellEndpointTest extends TestCase
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
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'Cross-sell Product ' . uniqid()],
            'slug' => 'xs-product-' . uniqid(),
            'description' => ['en' => 'Cross-sell test description'],
            'price' => 100.00,
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 50,
            'reserved_quantity' => 0,
            'product_type' => 'simple',
            'item_type' => 'PHYSICAL',
            'has_discount' => false,
            'has_flash_sale' => false,
            'is_fast_shipping_available' => false,
        ], $overrides));
    }

    /** Attach ordered cross-sell links (source → targets). */
    private function attachCrossSell(Product $source, array $targetIds): void
    {
        $sync = [];
        foreach (array_values($targetIds) as $index => $id) {
            $sync[$id] = ['sort_order' => $index + 1];
        }
        $source->crossSellProducts()->sync($sync);
    }

    private function detail(Product $product, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(self::LIST_URL . '/' . $product->slug, $headers);
    }

    // =====================================================================
    // Q-03 — both keys always present (SF-01/SF-02/SF-26/SF-30)
    // =====================================================================

    public function test_SF02_SF26_no_links_returns_exact_zero_fragment_with_both_keys(): void
    {
        $source = $this->makeProduct();

        $response = $this->detail($source);

        $response->assertOk();
        $body = $response->getContent();

        // Encoding sanity: the empty list must serialize as a JSON array
        // (`[]):`), not an object (`{}`) — SF-28.
        $this->assertMatchesRegularExpression(
            '/"cross_sell_products"\s*:\s*\[\s*\]/',
            (string) $body
        );

        $data = $response->json('data');
        $this->assertArrayHasKey('eligible_cross_sell_count', $data);
        $this->assertArrayHasKey('cross_sell_products', $data);
        $this->assertSame(0, $data['eligible_cross_sell_count']);
        $this->assertSame([], $data['cross_sell_products']);
        // Admin-only management keys must not leak to the client (Q-03).
        $this->assertArrayNotHasKey('cross_sell_ids', $data);
        $this->assertArrayNotHasKey('saved_cross_sell_count', $data);
        $this->assertArrayNotHasKey('storefront_cross_sell_limit', $data);
    }

    public function test_SF03_saved_but_all_ineligible_returns_keys_present_with_zero_counts(): void
    {
        $source = $this->makeProduct();

        $inactive = $this->makeProduct(['status' => 0, 'slug' => 'sf03-inactive']);
        $outOfStock = $this->makeProduct(['in_stock' => false, 'stock_quantity' => 0, 'slug' => 'sf03-oos']);
        $trashed = $this->makeProduct(['slug' => 'sf03-deleted']);
        $trashed->delete();

        $this->attachCrossSell($source, [$inactive->id, $outOfStock->id, $trashed->id]);

        $response = $this->detail($source);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(0, $data['eligible_cross_sell_count']);
        $this->assertSame([], $data['cross_sell_products']);
    }

    // =====================================================================
    // Eligibility by availability state (SF-04..SF-08)
    // =====================================================================

    public function test_SF04_one_eligible_returns_exactly_one_with_matching_count(): void
    {
        $source = $this->makeProduct();
        $target = $this->makeProduct(['slug' => 'sf04-target']);

        $this->attachCrossSell($source, [$target->id]);

        $response = $this->detail($source);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(1, $data['eligible_cross_sell_count']);
        $this->assertCount(1, $data['cross_sell_products']);
        $this->assertSame($target->id, $data['cross_sell_products'][0]['id']);
    }

    public function test_SF05_inactive_target_excluded(): void
    {
        $source = $this->makeProduct();
        $target = $this->makeProduct(['status' => 0, 'slug' => 'sf05-inactive']);

        $this->attachCrossSell($source, [$target->id]);
        $response = $this->detail($source);

        $response->assertOk();
        $this->assertSame(0, $response->json('data.eligible_cross_sell_count'));
    }

    public function test_SF06_out_of_stock_target_excluded(): void
    {
        $source = $this->makeProduct();
        $target = $this->makeProduct([
            'in_stock' => false,
            'stock_quantity' => 0,
            'reserved_quantity' => 5,
            'slug' => 'sf06-oos',
        ]);

        $this->attachCrossSell($source, [$target->id]);
        $response = $this->detail($source);

        $response->assertOk();
        $this->assertSame(0, $response->json('data.eligible_cross_sell_count'));
    }

    public function test_SF07_soft_deleted_target_excluded_but_pivot_row_remains(): void
    {
        $source = $this->makeProduct();
        $target = $this->makeProduct(['slug' => 'sf07-deleted']);

        $this->attachCrossSell($source, [$target->id]);
        $target->delete();

        $response = $this->detail($source);

        $response->assertOk();
        $this->assertSame(0, $response->json('data.eligible_cross_sell_count'));

        // Pivot row survives the soft delete (Q-02 — no auto-prune).
        $this->assertDatabaseHas('product_cross_sells', [
            'product_id' => $source->id,
            'related_product_id' => $target->id,
        ]);
    }

    public function test_SF08_category_inactive_target_excluded(): void
    {
        $source = $this->makeProduct();
        $inactiveCategory = Category::create([
            'name' => 'Inactive Cat',
            'slug' => 'sf08-cat-inactive',
            'status' => false,
        ]);
        $target = $this->makeProduct(['slug' => 'sf08-target']);
        $target->categories()->attach($inactiveCategory->id);

        $this->attachCrossSell($source, [$target->id]);
        $response = $this->detail($source);

        $response->assertOk();
        $this->assertSame(0, $response->json('data.eligible_cross_sell_count'));
    }

    // =====================================================================
    // 8-cap and derivable counts (SF-09/SF-10/SF-11/SF-25/SF-29/SF-31)
    // =====================================================================

    public function test_SF09_more_than_eight_eligible_returns_exactly_first_eight_with_count_eight(): void
    {
        $source = $this->makeProduct();
        $targets = [];
        foreach (range(1, 12) as $i) {
            $targets[] = $this->makeProduct(['slug' => "sf09-t{$i}"])->id;
        }

        $this->attachCrossSell($source, $targets);

        $response = $this->detail($source);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(8, $data['cross_sell_products']);
        $this->assertSame(8, $data['eligible_cross_sell_count']);
        $this->assertSame($targets[0], $data['cross_sell_products'][0]['id']);
        $this->assertSame($targets[7], $data['cross_sell_products'][7]['id']);
        // Saved rows were 12 — the cap applies to display AND count alike.
        $this->assertDatabaseCount('product_cross_sells', 12);
    }

    public function test_SF10_fewer_than_eight_returns_all_with_count_equal_to_list_length(): void
    {
        $source = $this->makeProduct();
        $targets = [$this->makeProduct(['slug' => 'sf10-a']), $this->makeProduct(['slug' => 'sf10-b']), $this->makeProduct(['slug' => 'sf10-c'])];

        $this->attachCrossSell($source, [$targets[0]->id, $targets[1]->id, $targets[2]->id]);

        $response = $this->detail($source);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(3, $data['cross_sell_products']);
        $this->assertSame(count($data['cross_sell_products']), $data['eligible_cross_sell_count']);
    }

    public function test_SF25_ineligible_are_filtered_before_limit(): void
    {
        // Home-side scenario (the source query is channel-scoped on BOTH
        // surfaces, so this source is home-eligible; fast-side parity is
        // covered by SF16/SF09).
        $source = $this->makeProduct(['is_fast_shipping_available' => false]);
        $eligible = [];
        foreach (range(1, 6) as $i) {
            $eligible[] = $this->makeProduct(['slug' => "sf25-e{$i}", 'is_fast_shipping_available' => false])->id;
        }
        $mismatched = [];
        foreach (range(1, 4) as $i) {
            $mismatched[] = $this->makeProduct(['slug' => "sf25-m{$i}", 'is_fast_shipping_available' => true, 'status' => 1])->id;
        }

        // Ineligible rows FIRST: with limit-before-filter the first 8 would
        // contain 4 ineligible + 4 eligible (→4); filter-before-limit gives 6.
        $this->attachCrossSell($source, array_merge($mismatched, $eligible));

        $home = $this->detail($source, ['X-Channel' => 'home']);
        $home->assertOk();
        $data = $home->json('data');
        $this->assertCount(6, $data['cross_sell_products']);
        $this->assertSame(6, $data['eligible_cross_sell_count']);
    }

    public function test_SF12_self_row_is_defensively_filtered(): void
    {
        $source = $this->makeProduct();

        // Insert a self-referential pivot row directly (cannot happen through
        // the API — validation blocks it — but the query denies it anyway).
        DB::table('product_cross_sells')->insert([
            'product_id' => $source->id,
            'related_product_id' => $source->id,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->detail($source);

        $response->assertOk();
        $this->assertSame(0, $response->json('data.eligible_cross_sell_count'));
        $this->assertSame([], $response->json('data.cross_sell_products'));
    }

    public function test_SF13_re_activated_target_returns_in_saved_order(): void
    {
        $source = $this->makeProduct();
        $target = $this->makeProduct(['slug' => 'sf13-target']);

        $this->attachCrossSell($source, [$target->id]);
        $target->update(['status' => 0]);
        $this->assertSame(0, $this->detail($source)->json('data.eligible_cross_sell_count'));

        $target->update(['status' => 1]);
        $data = $this->detail($source)->json('data');
        $this->assertSame(1, $data['eligible_cross_sell_count']);
        $this->assertSame($target->id, $data['cross_sell_products'][0]['id']);
    }

    public function test_restored_soft_deleted_target_becomes_eligible_again_without_a_write(): void
    {
        // Q-02 keeps the pivot row on soft delete; restoring the target must
        // make it eligible again on the next read (documented R-17).
        $source = $this->makeProduct();
        $target = $this->makeProduct(['slug' => 'sf13-restored']);

        $this->attachCrossSell($source, [$target->id]);
        $target->delete();
        $this->assertSame(0, $this->detail($source)->json('data.eligible_cross_sell_count'));

        $target->restore();

        $data = $this->detail($source)->json('data');
        $this->assertSame(1, $data['eligible_cross_sell_count']);
        $this->assertSame($target->id, $data['cross_sell_products'][0]['id']);
    }

    // =====================================================================
    // Channel semantics (SF-15/SF-16/SF-21/SF-22/SF-23/SF-24)
    // =====================================================================

    public function test_SF15_home_channel_excludes_fast_shipping_targets(): void
    {
        $source = $this->makeProduct();
        $fast = $this->makeProduct(['is_fast_shipping_available' => true, 'slug' => 'sf15-fast']);
        $home = $this->makeProduct(['is_fast_shipping_available' => false, 'slug' => 'sf15-home']);

        $this->attachCrossSell($source, [$fast->id, $home->id]);

        $response = $this->detail($source, ['X-Channel' => 'home']);
        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame([$home->id], array_column($data['cross_sell_products'], 'id'));
        $this->assertSame(1, $data['eligible_cross_sell_count']);
    }

    public function test_SF16_fast_channel_returns_only_fast_targets(): void
    {
        // The storefront detail query is channel-scoped too: under
        // fast-shipping the SOURCE must be fast-eligible.
        $source = $this->makeProduct(['is_fast_shipping_available' => true]);
        $fast = $this->makeProduct(['is_fast_shipping_available' => true, 'slug' => 'sf16-fast']);
        $home = $this->makeProduct(['is_fast_shipping_available' => false, 'slug' => 'sf16-home']);

        $this->attachCrossSell($source, [$fast->id, $home->id]);

        $response = $this->detail($source, ['X-Channel' => 'fast-shipping']);
        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame([$fast->id], array_column($data['cross_sell_products'], 'id'));
        $this->assertSame(1, $data['eligible_cross_sell_count']);
    }

    public function test_SF23_missing_header_defaults_to_home_channel(): void
    {
        $source = $this->makeProduct();
        $fast = $this->makeProduct(['is_fast_shipping_available' => true, 'slug' => 'sf23-fast']);
        $home = $this->makeProduct(['is_fast_shipping_available' => false, 'slug' => 'sf23-home']);

        $this->attachCrossSell($source, [$fast->id, $home->id]);

        $data = $this->detail($source)->json('data');
        $this->assertSame([$home->id], array_column($data['cross_sell_products'], 'id'));
    }

    public function test_SF24_channel_disabled_returns_both_channel_types(): void
    {
        $source = $this->makeProduct();
        $fast = $this->makeProduct(['is_fast_shipping_available' => true, 'slug' => 'sf24-fast']);
        $home = $this->makeProduct(['is_fast_shipping_available' => false, 'slug' => 'sf24-home']);

        $this->attachCrossSell($source, [$fast->id, $home->id]);

        config(['channel.enabled' => false]);

        $data = $this->detail($source)->json('data');
        $ids = array_column($data['cross_sell_products'], 'id');
        $this->assertContains($fast->id, $ids);
        $this->assertContains($home->id, $ids);
        $this->assertSame(2, $data['eligible_cross_sell_count']);
    }

    // =====================================================================
    // Ordering, mini-shape, backward compatibility (SF-14/SF-17/SF-18/SF-19)
    // =====================================================================

    public function test_SF11_listing_order_survives_filtering(): void
    {
        $source = $this->makeProduct();

        $eligible1 = $this->makeProduct(['slug' => 'sf11-e1']);
        $ineligible = $this->makeProduct(['status' => 0, 'slug' => 'sf11-x']);
        $eligible2 = $this->makeProduct(['slug' => 'sf11-e2']);

        // Submitted order: [eligible1, ineligible, eligible2] → [eligible1, eligible2].
        $this->attachCrossSell($source, [$eligible1->id, $ineligible->id, $eligible2->id]);

        $data = $this->detail($source)->json('data');
        $this->assertSame([$eligible1->id, $eligible2->id], array_column($data['cross_sell_products'], 'id'));
        $this->assertSame(2, $data['eligible_cross_sell_count']);
    }

    public function test_SF17_SF18_cross_items_expose_mini_shape_with_no_admin_metadata(): void
    {
        $source = $this->makeProduct();
        $target = $this->makeProduct(['slug' => 'sf17-target']);

        $this->attachCrossSell($source, [$target->id]);

        $response = $this->detail($source);
        $response->assertOk();

        $item = $response->json('data.cross_sell_products.0');
        $this->assertNotNull($item);
        $this->assertArrayNotHasKey('status', $item);
        $this->assertArrayNotHasKey('deleted', $item);
        $this->assertArrayNotHasKey('storefront_channels', $item);
        // Mini pricing contract (SF-18).
        $this->assertArrayHasKey('current_price', $item);
        $this->assertArrayHasKey('currency', $item);
        $this->assertArrayHasKey('in_stock', $item);
        $this->assertArrayHasKey('image', $item);
    }

    public function test_SF14_related_products_contract_unchanged(): void
    {
        $source = $this->makeProduct();
        $target = $this->makeProduct(['slug' => 'sf14-shared']);

        $this->attachCrossSell($source, [$target->id]);

        $data = $this->detail($source)->json('data');
        $this->assertArrayHasKey('related_products', $data);
        $this->assertSame([], $data['related_products']);
        $this->assertArrayHasKey('cross_sell_products', $data);
    }

    public function test_SF19_unknown_slug_still_404s(): void
    {
        $this->detail(new Product(['slug' => 'unknown-slug-404-still']))->assertNotFound();
    }

    // =====================================================================
    // Cross-surface invariant (SF-32) — parity with Admin when ≤ 8
    // =====================================================================

    public function test_SF32_storefront_count_matches_admin_count_for_same_channel(): void
    {
        $source = $this->makeProduct();
        $targets = [];
        foreach (range(1, 3) as $i) {
            $targets[] = $this->makeProduct(['slug' => "sf32-t{$i}", 'is_fast_shipping_available' => false])->id;
        }
        $this->attachCrossSell($source, $targets);

        // Storefront under home.
        $storefront = $this->detail($source, ['X-Channel' => 'home'])->json('data.eligible_cross_sell_count');

        // Admin show under the same channel (auth still required).
        $adminUser = $this->makeAdminUser();
        \Laravel\Sanctum\Sanctum::actingAs($adminUser, ['*']);
        $admin = $this->getJson('/api/v1/products/' . $source->id, ['X-Channel' => 'home']);
        $admin->assertOk();
        $adminCount = $admin->json('data.eligible_cross_sell_count');

        $this->assertSame(3, $storefront);
        $this->assertSame($storefront, $adminCount);
    }

    private function makeAdminUser(): \Marvel\Database\Models\User
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'XS Admin',
            'email' => 'xs-admin-' . uniqid() . '@example.com',
            'password' => 'password',
            'is_active' => true,
            'type' => 'admin',
        ]);
        \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => \Marvel\Enums\Permission::VIEW_PRODUCTS, 'guard_name' => 'api']
        );
        $user->givePermissionTo(\Marvel\Enums\Permission::VIEW_PRODUCTS);
        return $user;
    }
}
