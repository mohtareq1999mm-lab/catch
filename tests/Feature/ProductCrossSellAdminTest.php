<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Enums\Permission;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Admin contract for manual cross-selling (plan rev.3 §6/§7/§8/§15.3-15.5).
 *
 * Invariants under test (Q-01/Q-02/Q-03 + D7/BRO rules):
 * - Admin show/store/update always expose `cross_sell_ids`,
 *   `saved_cross_sell_count`, `eligible_cross_sell_count`,
 *   `cross_sell_products`, `storefront_cross_sell_limit` — index never does;
 * - `cross_sell_ids` is the FULL saved list (soft-deleted included, Q-02);
 * - `cross_sell_products` is eligible-only for the CURRENT request channel
 *   and the count equals its length with no 8-cap;
 * - Q-02: omitted key preserves everything; `[]` clears; a full list
 *   replaces; NEW soft-deleted ids → 422 without touching existing links;
 * - invalid input never reaches the transaction.
 */
class ProductCrossSellAdminTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private \Marvel\Database\Models\User $adminUser;
    private \Marvel\Database\Models\User $viewUser;
    private Category $category;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');

        $this->createAllTestTables();

        foreach ([
            Permission::VIEW_PRODUCTS,
            Permission::CREATE_PRODUCT,
            Permission::UPDATE_PRODUCT,
            Permission::DELETE_PRODUCT,
        ] as $perm) {
            SpatiePermission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->viewUser = \Marvel\Database\Models\User::create([
            'name' => 'XS View User',
            'email' => 'xs-view-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => 'Password123!',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->viewUser->givePermissionTo(Permission::VIEW_PRODUCTS);

        $this->adminUser = \Marvel\Database\Models\User::create([
            'name' => 'XS Admin User',
            'email' => 'xs-admin-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => 'Password123!',
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->adminUser->givePermissionTo([
            Permission::VIEW_PRODUCTS,
            Permission::CREATE_PRODUCT,
            Permission::UPDATE_PRODUCT,
            Permission::DELETE_PRODUCT,
        ]);

        $this->category = Category::create([
            'name' => 'XS Category',
            'slug' => 'xs-category-' . Str::random(6),
        ]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => ['en' => 'XS Product ' . uniqid()],
            'slug' => 'xs-product-' . uniqid(),
            'description' => ['en' => 'XS test description'],
            'price' => 100.00,
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 50,
            'reserved_quantity' => 0,
            'product_type' => ProductType::SIMPLE,
            'item_type' => 'PHYSICAL',
            'has_discount' => false,
            'has_flash_sale' => false,
            'is_fast_shipping_available' => false,
        ], $overrides));
    }

    private function attachCrossSell(Product $source, array $targetIds): void
    {
        $sync = [];
        foreach (array_values($targetIds) as $index => $id) {
            $sync[$id] = ['sort_order' => $index + 1];
        }
        $source->crossSellProducts()->sync($sync);
    }

    private function pivotRows(Product $source): array
    {
        return DB::table('product_cross_sells')
            ->where('product_id', $source->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => ['en' => 'XS Created ' . Str::random(6)],
            'description' => ['en' => 'XS created product description.'],
            'categories' => [$this->category->id],
            'images' => [UploadedFile::fake()->image('xs.jpg')],
            'price' => 10,
            'product_type' => ProductType::SIMPLE,
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
        ], $overrides);
    }

    private function show(int $productId, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(self::PREFIX . '/products/' . $productId, $headers);
    }

    private function update(Product $product, array $payload, string $method = 'putJson', array $headers = []): \Illuminate\Testing\TestResponse
    {
        $uri = self::PREFIX . '/products/' . $product->id;
        return $this->{$method}($uri, $payload, $headers);
    }

    // =====================================================================
    // Create flow (CR-01..CR-06/CR-09/CR-10/CR-11, AD-17/AD-24)
    // =====================================================================

    public function test_CR01_create_without_link_field_produces_no_rows_and_preserves_keys(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $response = $this->postJson(self::PREFIX . '/products', $this->storePayload());

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertSame(0, DB::table('product_cross_sells')->where('product_id', $data['id'])->count());
        $this->assertSame(0, $data['eligible_cross_sell_count']);
        $this->assertSame([], $data['cross_sell_products']);
        $this->assertSame(0, $data['saved_cross_sell_count']);
        $this->assertSame([], $data['cross_sell_ids']);
        $this->assertSame(8, $data['storefront_cross_sell_limit']);
    }

    public function test_CR02_create_with_empty_array_stores_no_rows(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $response = $this->postJson(self::PREFIX . '/products', $this->storePayload(['cross_sell_ids' => []]));

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertSame(0, DB::table('product_cross_sells')->count());
        $this->assertSame([], $data['cross_sell_ids']);
    }

    public function test_CR03_create_with_valid_ids_persists_rows_in_submitted_order(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $t1 = $this->makeProduct();
        $t2 = $this->makeProduct();

        $response = $this->postJson(self::PREFIX . '/products', $this->storePayload([
            'cross_sell_ids' => [$t1->id, $t2->id],
        ]));

        $response->assertStatus(201);
        $rows = DB::table('product_cross_sells')->where('product_id', $response->json('data.id'))->orderBy('related_product_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(1, (int) $rows->firstWhere('related_product_id', $t1->id)->sort_order);
        $this->assertSame(2, (int) $rows->firstWhere('related_product_id', $t2->id)->sort_order);
    }

    public function test_CR04_create_rejects_scalar_string_with_validation_error(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $response = $this->postJson(self::PREFIX . '/products', $this->storePayload(['cross_sell_ids' => '31']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cross_sell_ids']);
        $this->assertSame(0, DB::table('product_cross_sells')->count());
    }

    public function test_CR05_create_rejects_noninteger_item_types(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $response = $this->postJson(self::PREFIX . '/products', $this->storePayload(['cross_sell_ids' => [31.5]]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cross_sell_ids.0']);
        $this->assertSame(0, DB::table('product_cross_sells')->count());
    }

    public function test_CR06_create_rejects_duplicate_ids(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $t = $this->makeProduct();
        $response = $this->postJson(self::PREFIX . '/products', $this->storePayload(['cross_sell_ids' => [$t->id, $t->id]]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cross_sell_ids.1']);
    }

    public function test_CR08_create_rejects_missing_target(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $response = $this->postJson(self::PREFIX . '/products', $this->storePayload(['cross_sell_ids' => [999999]]));

        $response->assertStatus(422);
        $this->assertSame(
            'The selected product does not exist.',
            $response->json('errors.cross_sell_ids.0')
        );
        $this->assertSame(0, DB::table('product_cross_sells')->count());
    }

    public function test_CR09_create_rejects_new_soft_deleted_target(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $trashed = $this->makeProduct();
        $trashed->delete();

        $response = $this->postJson(self::PREFIX . '/products', $this->storePayload(['cross_sell_ids' => [$trashed->id]]));

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'deleted product',
            (string) $response->json('errors.cross_sell_ids.0')
        );
        $this->assertSame(0, DB::table('product_cross_sells')->count());
    }

    public function test_CR10_create_accepts_ineligible_target_and_keeps_it_saved_but_hidden(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $ineligible = $this->makeProduct(['status' => 0]);

        $response = $this->postJson(self::PREFIX . '/products', $this->storePayload(['cross_sell_ids' => [$ineligible->id]]));

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertSame([$ineligible->id], $data['cross_sell_ids']);
        $this->assertSame(1, $data['saved_cross_sell_count']);
        $this->assertSame(0, $data['eligible_cross_sell_count']);
        $this->assertSame([], $data['cross_sell_products']);
        $this->assertDatabaseHas('product_cross_sells', ['product_id' => $data['id'], 'related_product_id' => $ineligible->id]);
    }

    public function test_CR11_create_response_preserves_submitted_order(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $t1 = $this->makeProduct();
        $t2 = $this->makeProduct();

        $response = $this->postJson(self::PREFIX . '/products', $this->storePayload([
            'cross_sell_ids' => [$t2->id, $t1->id],
        ]));

        $response->assertStatus(201);
        $this->assertSame([$t2->id, $t1->id], $response->json('data.cross_sell_ids'));
    }

    // =====================================================================
    // Update flow (UP-01..UP-15/UP-19, AD-29)
    // =====================================================================

    public function test_UP16_AD29_omitted_key_preserves_existing_soft_deleted_link(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $active = $this->makeProduct();
        $trashed = $this->makeProduct();

        $this->attachCrossSell($source, [$active->id, $trashed->id]);
        $trashed->delete();

        $response = $this->update($source, ['name' => ['en' => 'Unrelated rename']]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data['cross_sell_ids']);
        $this->assertContains($trashed->id, $data['cross_sell_ids']);
        $this->assertSame(2, $data['saved_cross_sell_count']);
        $this->assertContains($active->id, $data['cross_sell_products'] ? array_column($data['cross_sell_products'], 'id') : []);
        $this->assertSame(1, $data['eligible_cross_sell_count']);
        // Pivot row still intact (Q-02 retention).
        $this->assertDatabaseHas('product_cross_sells', ['product_id' => $source->id, 'related_product_id' => $trashed->id]);
    }

    public function test_UP17_full_replacement_including_trashed_target_retains_it(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $active = $this->makeProduct();
        $trashed = $this->makeProduct();

        $this->attachCrossSell($source, [$trashed->id, $active->id]);
        $trashed->delete();

        $response = $this->update($source, ['cross_sell_ids' => [$trashed->id, $active->id]]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame([$trashed->id, $active->id], $data['cross_sell_ids']);
        $this->assertSame(2, $data['saved_cross_sell_count']);
        $this->assertSame([$active->id], array_column($data['cross_sell_products'], 'id'));
    }

    public function test_UP18_full_replacement_omitting_trashed_target_removes_it_explicitly(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $trashed = $this->makeProduct();

        $this->attachCrossSell($source, [$trashed->id]);
        $trashed->delete();

        $response = $this->update($source, ['cross_sell_ids' => []]);

        $response->assertOk();
        $this->assertSame([], $response->json('data.cross_sell_ids'));
        $this->assertSame(0, DB::table('product_cross_sells')->where('product_id', $source->id)->count());
    }

    public function test_UP19_new_soft_deleted_target_is_rejected_and_links_stay_untouched(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $active = $this->makeProduct();
        $this->attachCrossSell($source, [$active->id]);

        $newTrashed = $this->makeProduct();
        $newTrashed->delete();

        $response = $this->update($source, ['cross_sell_ids' => [$active->id, $newTrashed->id]]);

        $response->assertStatus(422);
        // Existing links untouched.
        $rows = $this->pivotRows($source);
        $this->assertCount(1, $rows);
        $this->assertSame([$active->id], array_map(fn ($row) => (int) $row['related_product_id'], $rows));
    }

    public function test_UP01_update_omitted_key_preserves_active_links_and_order(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $this->attachCrossSell($source, [$a->id, $b->id]);

        $response = $this->update($source, ['name' => ['en' => 'Rename only']]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame([$a->id, $b->id], array_column($data['cross_sell_products'], 'id'));
        $this->assertSame(2, $data['saved_cross_sell_count']);
    }

    public function test_UP02_update_with_empty_array_clears_all(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();
        $this->attachCrossSell($source, [$a->id]);

        $response = $this->update($source, ['cross_sell_ids' => []]);

        $response->assertOk();
        $this->assertSame([], $response->json('data.cross_sell_ids'));
        $this->assertDatabaseCount('product_cross_sells', 0);
    }

    public function test_UP03_update_replaces_links_in_supplied_order(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $c = $this->makeProduct();
        $this->attachCrossSell($source, [$a->id]);

        $response = $this->update($source, ['cross_sell_ids' => [$c->id, $b->id]]);

        $response->assertOk();
        $rows = $this->pivotRows($source);
        $this->assertSame([$c->id, $b->id], array_map(fn ($row) => (int) $row['related_product_id'], $rows));
        $this->assertSame(1, (int) $rows[0]['sort_order']);
        $this->assertSame(2, (int) $rows[1]['sort_order']);
    }

    public function test_UP04_update_with_invalid_ids_rejects_and_leaves_links_unchanged(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();
        $this->attachCrossSell($source, [$a->id]);

        $response = $this->update($source, ['cross_sell_ids' => [$a->id, 999999]]);

        $response->assertStatus(422);
        $this->assertCount(1, $this->pivotRows($source));
    }

    public function test_UP07_partial_update_with_only_cross_sell_ids_is_valid(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();

        $response = $this->update($source, ['cross_sell_ids' => [$a->id]]);

        $response->assertOk();
        $this->assertSame([$a->id], $response->json('data.cross_sell_ids'));
    }

    public function test_UP08_UP09_put_and_patch_share_the_same_semantics(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $putSource = $this->makeProduct();
        $patchSource = $this->makeProduct();
        $a = $this->makeProduct();

        $put = $this->update($putSource, ['cross_sell_ids' => [$a->id]], 'putJson');
        $patch = $this->update($patchSource, ['cross_sell_ids' => [$a->id]], 'patchJson');

        $put->assertOk();
        $patch->assertOk();
        $this->assertSame([$a->id], $put->json('data.cross_sell_ids'));
        $this->assertSame([$a->id], $patch->json('data.cross_sell_ids'));
    }

    public function test_UP10_repeat_same_ordered_list_is_a_no_op(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $this->attachCrossSell($source, [$a->id, $b->id]);

        $before = collect($this->pivotRows($source))->pluck('updated_at')->values()->all();
        $auditBefore = DB::table('activity_log')
            ->where('subject_id', $source->id)
            ->where('event', 'product_cross_sells_synced')
            ->count();

        $response = $this->update($source, ['cross_sell_ids' => [$a->id, $b->id]]);

        $response->assertOk();
        $after = collect($this->pivotRows($source))->pluck('updated_at')->values()->all();
        $auditAfter = DB::table('activity_log')
            ->where('subject_id', $source->id)
            ->where('event', 'product_cross_sells_synced')
            ->count();

        $this->assertSame($before, $after, 'unchanged ordered list must not rewrite pivot rows');
        $this->assertSame($auditBefore, $auditAfter, 'unchanged ordered list must not emit an audit row');
    }

    public function test_update_under_fast_channel_removes_nonfast_target(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        // Review B1 regression: the old-id read must NOT be FastShippingScope-
        // scoped, otherwise a non-fast saved target would be invisible and
        // never detached under X-Channel: fast-shipping.
        $source = $this->makeProduct(['is_fast_shipping_available' => true]);
        $fast = $this->makeProduct(['is_fast_shipping_available' => true]);
        $home = $this->makeProduct(['is_fast_shipping_available' => false]);
        $this->attachCrossSell($source, [$fast->id, $home->id]);

        $response = $this->update(
            $source,
            ['cross_sell_ids' => [$fast->id]],
            'putJson',
            ['X-Channel' => 'fast-shipping']
        );

        $response->assertOk();
        $rows = $this->pivotRows($source);
        $this->assertSame([$fast->id], array_map(fn ($row) => (int) $row['related_product_id'], $rows));
    }

    public function test_update_under_fast_channel_retains_already_attached_soft_deleted_nonfast_target(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct(['is_fast_shipping_available' => true]);
        $fast = $this->makeProduct(['is_fast_shipping_available' => true]);
        $trashedHome = $this->makeProduct(['is_fast_shipping_available' => false]);

        $this->attachCrossSell($source, [$trashedHome->id, $fast->id]);
        $trashedHome->delete();

        // The full list returned by the admin show (channel-aware) includes the
        // trashed non-fast id; submitting it back must be accepted (Q-02).
        $response = $this->update(
            $source,
            ['cross_sell_ids' => [$trashedHome->id, $fast->id]],
            'putJson',
            ['X-Channel' => 'fast-shipping']
        );

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame([$trashedHome->id, $fast->id], $data['cross_sell_ids']);
        $this->assertSame(2, $data['saved_cross_sell_count']);
        $this->assertSame([$fast->id], array_column($data['cross_sell_products'], 'id'));
    }

    public function test_update_writes_ordered_old_new_ids_to_the_audit_log(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $this->attachCrossSell($source, [$a->id]);

        $response = $this->update($source, ['cross_sell_ids' => [$b->id, $a->id]]);

        $response->assertOk();

        $row = DB::table('activity_log')
            ->where('subject_id', $source->id)
            ->where('event', 'product_cross_sells_synced')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, 'cross-sell sync must emit a best-effort audit row');
        $properties = json_decode((string) $row->properties, true);
        $this->assertSame([$a->id], $properties['old']['ids']);
        $this->assertSame([$b->id, $a->id], $properties['new']['ids']);
    }

    public function test_UP11_reorder_updates_sort_order(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $this->attachCrossSell($source, [$a->id, $b->id]);

        $response = $this->update($source, ['cross_sell_ids' => [$b->id, $a->id]]);

        $response->assertOk();
        $rows = $this->pivotRows($source);
        $sorts = [];
        foreach ($rows as $row) {
            $sorts[(int) $row['related_product_id']] = (int) $row['sort_order'];
        }
        $this->assertSame(1, $sorts[$b->id]);
        $this->assertSame(2, $sorts[$a->id]);
    }

    public function test_UP13_update_rejects_null_and_keeps_links(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();
        $this->attachCrossSell($source, [$a->id]);

        $response = $this->update($source, ['cross_sell_ids' => null]);

        $response->assertStatus(422);
        $this->assertCount(1, $this->pivotRows($source));
    }

    public function test_UP14_update_rejects_associative_list(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();
        $this->attachCrossSell($source, [$a->id]);

        $response = $this->update($source, ['cross_sell_ids' => ['a' => $a->id]]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cross_sell_ids']);
        $this->assertCount(1, $this->pivotRows($source));
    }

    public function test_UP15_update_rejects_loose_string_int_duplicate(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();

        $response = $this->update($source, ['cross_sell_ids' => [$a->id, (string) $a->id]]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cross_sell_ids.1']);
    }

    public function test_update_rejects_self_reference_and_keeps_links(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();
        $this->attachCrossSell($source, [$a->id]);

        $response = $this->update($source, ['cross_sell_ids' => [$source->id]]);

        $response->assertStatus(422);
        $this->assertSame(
            'A product cannot recommend itself.',
            $response->json('errors.cross_sell_ids.0')
        );
        $this->assertCount(1, $this->pivotRows($source));
    }

    // =====================================================================
    // Admin show contract (AD-01..AD-03/AD-05..AD-11/AD-24..AD-29)
    // =====================================================================

    public function test_AD01_AD24_AD26_show_keys_are_always_present_and_typed_including_zero_state(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();

        $response = $this->show($source->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['saved_cross_sell_count', 'eligible_cross_sell_count', 'cross_sell_products'],
        ]);
        $data = $response->json('data');
        $this->assertSame(0, $data['saved_cross_sell_count']);
        $this->assertSame(0, $data['eligible_cross_sell_count']);
        $this->assertSame([], $data['cross_sell_products']);
        $this->assertSame([], $data['cross_sell_ids']);
        $this->assertSame(8, $data['storefront_cross_sell_limit']);
        $this->assertIsInt($data['eligible_cross_sell_count']);
    }

    public function test_AD05_limit_is_the_fixed_constant(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();

        $response = $this->show($source->id);

        $response->assertOk();
        $this->assertSame(8, $response->json('data.storefront_cross_sell_limit'));
    }

    public function test_AD02_eligible_item_has_exact_compact_shape_without_admin_reason_keys(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $target = $this->makeProduct(['slug' => 'ad02-target']);
        $this->attachCrossSell($source, [$target->id]);

        $response = $this->show($source->id);

        $response->assertOk();
        $item = $response->json('data.cross_sell_products.0');
        $this->assertSame(
            ['id', 'name', 'slug', 'price', 'thumbnail', 'status', 'in_stock', 'deleted', 'storefront_channels'],
            array_keys($item)
        );
        $this->assertSame($target->id, $item['id']);
        $this->assertTrue($item['status']);
        $this->assertTrue($item['in_stock']);
        $this->assertFalse($item['deleted']);
        $this->assertArrayNotHasKey('available', $item);
        $this->assertArrayNotHasKey('unavailability_reasons', $item);
    }

    public function test_AD03_saved_count_includes_all_rows_even_soft_deleted(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $target = $this->makeProduct();
        $this->attachCrossSell($source, [$target->id]);
        $target->delete();

        $response = $this->show($source->id);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(1, $data['saved_cross_sell_count']);
        $this->assertSame([$target->id], $data['cross_sell_ids']);
        $this->assertSame(0, $data['eligible_cross_sell_count']);
        $this->assertSame([], $data['cross_sell_products']);
    }

    public function test_AD04_AD06_AD07_ineligible_targets_are_counted_saved_not_eligible(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $active = $this->makeProduct(['slug' => 'ad06-active']);
        $inactive = $this->makeProduct(['status' => 0, 'slug' => 'ad06-inactive']);
        $outOfStock = $this->makeProduct(['in_stock' => false, 'stock_quantity' => 0, 'slug' => 'ad06-oos']);
        $this->attachCrossSell($source, [$active->id, $inactive->id, $outOfStock->id]);

        // Home channel: active home-only target eligible; other two not.
        $response = $this->show($source->id, ['X-Channel' => 'home']);
        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame([$active->id], array_column($data['cross_sell_products'], 'id'));
        $this->assertSame(1, $data['eligible_cross_sell_count']);
        $this->assertSame(3, $data['saved_cross_sell_count']);
        $this->assertSame([$active->id, $inactive->id, $outOfStock->id], $data['cross_sell_ids']);
    }

    public function test_AD08_soft_deleted_target_absent_from_products_retained_in_ids(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $target = $this->makeProduct();
        $this->attachCrossSell($source, [$target->id]);
        $target->delete();

        $response = $this->show($source->id);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertContains($target->id, $data['cross_sell_ids']);
        $this->assertSame(1, $data['saved_cross_sell_count']);
        $this->assertSame(0, $data['eligible_cross_sell_count']);
    }

    public function test_AD09_category_inactive_target_hidden_but_saved(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $inactiveCategory = Category::create([
            'name' => 'AD09 Inactive',
            'slug' => 'ad09-inactive-cat',
            'status' => false,
        ]);
        $target = $this->makeProduct(['slug' => 'ad09-target']);
        $target->categories()->attach($inactiveCategory->id);
        $this->attachCrossSell($source, [$target->id]);

        $response = $this->show($source->id);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame([$target->id], $data['cross_sell_ids']);
        $this->assertSame(1, $data['saved_cross_sell_count']);
        $this->assertSame(0, $data['eligible_cross_sell_count']);
    }

    public function test_AD10_channel_map_reflects_fast_flag_on_eligible_items(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $homeTarget = $this->makeProduct(['is_fast_shipping_available' => false, 'slug' => 'ad10-home']);
        $fastTarget = $this->makeProduct(['is_fast_shipping_available' => true, 'slug' => 'ad10-fast']);
        $this->attachCrossSell($source, [$homeTarget->id, $fastTarget->id]);

        config(['channel.enabled' => false]);
        $response = $this->show($source->id);
        $response->assertOk();
        $items = collect($response->json('data.cross_sell_products'))->keyBy('id');

        $this->assertTrue($items[$homeTarget->id]['storefront_channels']['home']);
        $this->assertTrue($items[$homeTarget->id]['storefront_channels']['fast_shipping']);
        $this->assertTrue($items[$fastTarget->id]['storefront_channels']['home']);
        $this->assertTrue($items[$fastTarget->id]['storefront_channels']['fast_shipping']);
    }

    public function test_AD11_show_order_matches_pivot_order(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $this->attachCrossSell($source, [$a->id, $b->id]);

        $response = $this->show($source->id);

        $response->assertOk();
        $this->assertSame([$a->id, $b->id], $response->json('data.cross_sell_ids'));
        $this->assertSame([$a->id, $b->id], array_column($response->json('data.cross_sell_products'), 'id'));
    }

    public function test_AD12_guest_receives_401(): void
    {
        $source = $this->makeProduct();
        $this->show($source->id)->assertStatus(401);
    }

    public function test_AD13_view_only_user_can_show_but_cannot_update(): void
    {
        Sanctum::actingAs($this->viewUser, ['*']);
        $source = $this->makeProduct();

        $this->show($source->id)->assertOk();

        $response = $this->update($source, ['cross_sell_ids' => [1]]);
        $response->assertStatus(403);
    }

    public function test_AD15_index_has_no_cross_sell_keys(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $target = $this->makeProduct();
        $this->attachCrossSell($source, [$target->id]);

        $response = $this->getJson(self::PREFIX . '/products');

        $response->assertOk();
        foreach ($response->json('data.data') as $item) {
            $this->assertArrayNotHasKey('cross_sell_ids', $item);
            $this->assertArrayNotHasKey('cross_sell_products', $item);
            $this->assertArrayNotHasKey('saved_cross_sell_count', $item);
            $this->assertArrayNotHasKey('eligible_cross_sell_count', $item);
        }
    }

    public function test_AD16_hard_deleted_target_cascades_pivot_and_disappears(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $target = $this->makeProduct();
        $this->attachCrossSell($source, [$target->id]);

        $target->forceDelete();

        $this->assertDatabaseMissing('product_cross_sells', ['related_product_id' => $target->id]);

        $response = $this->show($source->id);
        $response->assertOk();
        $this->assertSame([], $response->json('data.cross_sell_ids'));
        $this->assertSame(0, $response->json('data.saved_cross_sell_count'));
    }

    public function test_AD17_store_response_includes_full_cross_sell_contract(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $t = $this->makeProduct();

        $response = $this->postJson(self::PREFIX . '/products', $this->storePayload(['cross_sell_ids' => [$t->id]]));

        $response->assertStatus(201);
        $data = $response->json('data');
        foreach (['cross_sell_ids', 'saved_cross_sell_count', 'eligible_cross_sell_count', 'cross_sell_products', 'storefront_cross_sell_limit'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame([$t->id], $data['cross_sell_ids']);
        $this->assertSame(1, $data['saved_cross_sell_count']);
        $this->assertSame(1, $data['eligible_cross_sell_count']);
    }

    public function test_AD18_update_response_includes_full_cross_sell_contract(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $t = $this->makeProduct();

        $response = $this->update($source, ['cross_sell_ids' => [$t->id]]);

        $response->assertOk();
        $data = $response->json('data');
        foreach (['cross_sell_ids', 'saved_cross_sell_count', 'eligible_cross_sell_count', 'cross_sell_products', 'storefront_cross_sell_limit'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame([$t->id], $data['cross_sell_ids']);
    }

    // =====================================================================
    // Channel-scoped counts (AD-19/AD-20/AD-21/AD-22)
    // =====================================================================

    public function test_AD19_fast_channel_count_counts_fast_targets_only(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        // The admin show query is itself channel-scoped: under fast-shipping
        // the SOURCE must be fast-eligible or FastShippingScope 404s it.
        $source = $this->makeProduct(['is_fast_shipping_available' => true]);
        $fast = $this->makeProduct(['is_fast_shipping_available' => true, 'slug' => 'ad19-fast']);
        $home = $this->makeProduct(['is_fast_shipping_available' => false, 'slug' => 'ad19-home']);
        $this->attachCrossSell($source, [$fast->id, $home->id]);

        $response = $this->show($source->id, ['X-Channel' => 'fast-shipping']);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.eligible_cross_sell_count'));
        $this->assertSame([$fast->id], array_column($response->json('data.cross_sell_products'), 'id'));
    }

    public function test_AD20_count_excludes_channel_mismatch_on_both_channels(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        // Fast-eligible source so the fast-shipping show is reachable.
        $source = $this->makeProduct(['is_fast_shipping_available' => true]);
        $fast = $this->makeProduct(['is_fast_shipping_available' => true, 'slug' => 'ad20-fast']);
        $home = $this->makeProduct(['is_fast_shipping_available' => false, 'slug' => 'ad20-home']);
        $this->attachCrossSell($source, [$fast->id, $home->id]);

        $homeCount = $this->show($source->id, ['X-Channel' => 'home'])->json('data.eligible_cross_sell_count');
        $fastCount = $this->show($source->id, ['X-Channel' => 'fast-shipping'])->json('data.eligible_cross_sell_count');

        $this->assertSame(1, $homeCount);
        $this->assertSame(1, $fastCount);
        $this->assertSame(2, DB::table('product_cross_sells')->where('product_id', $source->id)->count());
    }

    public function test_AD21_in_stock_alone_never_suffices_for_the_count(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        // In stock but active=false → available() would never pass either.
        $this->makeProduct(['status' => 0, 'in_stock' => true, 'slug' => 'ad21-inactive-but-stocked']);
        $bogus = Product::query()->whereSlug('ad21-inactive-but-stocked')->firstOrFail();

        $this->attachCrossSell($source, [$bogus->id]);

        $response = $this->show($source->id, ['X-Channel' => 'home']);
        $response->assertOk();
        $this->assertSame(0, $response->json('data.eligible_cross_sell_count'));
    }

    public function test_AD22_channel_disabled_count_returns_all_active(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $a = $this->makeProduct(['is_fast_shipping_available' => true, 'slug' => 'ad22-a']);
        $b = $this->makeProduct(['is_fast_shipping_available' => false, 'slug' => 'ad22-b']);
        $this->attachCrossSell($source, [$a->id, $b->id]);

        config(['channel.enabled' => false]);
        $response = $this->show($source->id);

        $response->assertOk();
        $this->assertSame(2, $response->json('data.eligible_cross_sell_count'));
    }

    // =====================================================================
    // Count invariants (AD-25/AD-27/AD-28) and Q-03 contract (AD-24)
    // =====================================================================

    public function test_AD27_ineligible_set_is_derivable_from_ids_minus_products(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $eligible = $this->makeProduct(['slug' => 'ad27-e']);
        $inactive = $this->makeProduct(['status' => 0, 'slug' => 'ad27-i']);
        $this->attachCrossSell($source, [$eligible->id, $inactive->id]);

        $response = $this->show($source->id);

        $response->assertOk();
        $ids = $response->json('data.cross_sell_ids');
        $productIds = array_column($response->json('data.cross_sell_products'), 'id');
        $this->assertSame([$inactive->id], array_values(array_diff($ids, $productIds)));
    }

    public function test_AD28_admin_count_equals_list_length_and_is_not_capped_at_eight(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $targets = [];
        foreach (range(1, 10) as $i) {
            $targets[] = $this->makeProduct(['slug' => "ad28-t{$i}"])->id;
        }
        $this->attachCrossSell($source, $targets);

        $response = $this->show($source->id);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(10, $data['cross_sell_products']);
        $this->assertSame(10, $data['eligible_cross_sell_count']);
        $this->assertSame(count($data['cross_sell_products']), $data['eligible_cross_sell_count']);
    }

    public function test_AD25_saved_but_none_eligible_keeps_both_contract_keys(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $inactive = $this->makeProduct(['status' => 0, 'slug' => 'ad25-i']);
        $this->attachCrossSell($source, [$inactive->id]);

        $response = $this->show($source->id);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(1, $data['saved_cross_sell_count']);
        $this->assertSame(0, $data['eligible_cross_sell_count']);
        $this->assertSame([], $data['cross_sell_products']);
        $this->assertNotSame([], $data['cross_sell_ids']);
    }

    // =====================================================================
    // Bounded query execution (AD-14): adding 3 cross-sell links must not
    // blow up the show-request query count (relation + eligible list with
    // bounded eager loads only — no per-row N+1).
    // =====================================================================

    public function test_AD14_show_query_count_is_bounded_despite_links(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);
        $source = $this->makeProduct();
        $targets = [];
        foreach (range(1, 3) as $i) {
            $targets[] = $this->makeProduct(['slug' => "ad14-t{$i}"])->id;
        }

        $baseline = $this->queryCount(fn () => $this->show($source->id)->assertOk());

        $this->attachCrossSell($source, $targets);
        $withLinks = $this->queryCount(fn () => $this->show($source->id)->assertOk());

        $this->assertLessThanOrEqual(7, $withLinks - $baseline, 'cross-sell show path must not introduce per-row N+1');
    }

    private function queryCount(callable $callback): int
    {
        $connection = DB::connection();
        $connection->enableQueryLog();
        $callback();
        $count = count($connection->getQueryLog());
        $connection->disableQueryLog();
        $connection->flushQueryLog();
        return $count;
    }
}
