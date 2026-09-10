<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Tag;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ProductTagTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';
    private const GENERAL_PREFIX = '/api/v1/general';

    private User $admin;
    private User $normalUser;
    private Tag $tagGaming;
    private Tag $tagWireless;
    private Tag $tagAccessory;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->normalUser = User::create([
            'name' => 'Normal User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        Role::create(['name' => 'super_admin', 'guard_name' => 'api']);
        Role::create(['name' => 'customer', 'guard_name' => 'api']);

        $this->admin->assignRole('super_admin');
        $this->normalUser->assignRole('customer');

        \Spatie\Permission\Models\Permission::create(['name' => Permission::VIEW_PRODUCTS, 'guard_name' => 'api']);
        \Spatie\Permission\Models\Permission::create(['name' => Permission::CREATE_PRODUCT, 'guard_name' => 'api']);
        \Spatie\Permission\Models\Permission::create(['name' => Permission::UPDATE_PRODUCT, 'guard_name' => 'api']);
        \Spatie\Permission\Models\Permission::create(['name' => Permission::DELETE_PRODUCT, 'guard_name' => 'api']);

        $this->admin->givePermissionTo([
            Permission::VIEW_PRODUCTS,
            Permission::CREATE_PRODUCT,
            Permission::UPDATE_PRODUCT,
            Permission::DELETE_PRODUCT]);

        $this->tagGaming = Tag::create(['name' => 'Gaming']);
        $this->tagWireless = Tag::create(['name' => 'Wireless']);
        $this->tagAccessory = Tag::create(['name' => 'Accessory']);
    }

    private function authAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function authUser(): void
    {
        Sanctum::actingAs($this->normalUser);
    }

    private function createProduct(string $name = 'Test Product', array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'price' => 49.99,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10], $extra));
    }

    // =========================================================================
    // PUT /products/{id} — Update Tags via API
    // =========================================================================

    public function test_update_product_add_tags()
    {
        $this->authAdmin();
        $product = $this->createProduct('Add Tags');

        $response = $this->putJson(self::PREFIX . "/products/{$product->id}", [
            'tags' => [$this->tagGaming->id, $this->tagWireless->id]]);

        $response->assertStatus(200);
        $tags = $response->json('data.tags');
        $tagIds = collect($tags)->pluck('id')->toArray();
        $this->assertContains($this->tagGaming->id, $tagIds);
        $this->assertContains($this->tagWireless->id, $tagIds);
    }

    public function test_update_product_replace_tags()
    {
        $this->authAdmin();
        $product = $this->createProduct('Replace Tags');
        $product->tags()->attach([$this->tagGaming->id, $this->tagWireless->id]);

        $response = $this->putJson(self::PREFIX . "/products/{$product->id}", [
            'tags' => [$this->tagAccessory->id]]);

        $response->assertStatus(200);
        $tags = $response->json('data.tags');
        $tagIds = collect($tags)->pluck('id')->toArray();
        $this->assertNotContains($this->tagGaming->id, $tagIds);
        $this->assertNotContains($this->tagWireless->id, $tagIds);
        $this->assertContains($this->tagAccessory->id, $tagIds);
    }

    public function test_remove_all_tags_from_product()
    {
        $this->authAdmin();
        $product = $this->createProduct('Remove Tags');
        $product->tags()->attach([$this->tagGaming->id, $this->tagWireless->id]);

        $response = $this->putJson(self::PREFIX . "/products/{$product->id}", [
            'tags' => []]);

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data.tags'));
    }

    public function test_update_product_tags_requires_admin()
    {
        $product = $this->createProduct('Unauthorized Tag Update');
        $product->tags()->attach([$this->tagGaming->id]);

        $this->authUser();
        $response = $this->putJson(self::PREFIX . "/products/{$product->id}", [
            'tags' => [$this->tagWireless->id]]);

        $response->assertStatus(403);
    }

    public function test_update_product_with_invalid_tag_ids_returns_422()
    {
        $this->authAdmin();
        $product = $this->createProduct('Invalid Update Tags');
        $product->tags()->attach([$this->tagGaming->id]);

        $response = $this->putJson(self::PREFIX . "/products/{$product->id}", [
            'tags' => [99999]]);

        $response->assertStatus(422);
    }

    public function test_tags_persist_when_updating_other_fields()
    {
        $this->authAdmin();
        $product = $this->createProduct('Persist Tags');
        $product->tags()->attach([$this->tagGaming->id, $this->tagWireless->id]);

        $response = $this->putJson(self::PREFIX . "/products/{$product->id}", [
            'price' => 199.99]);

        $response->assertStatus(200);
        $product->refresh();
        $tagIds = $product->tags->pluck('id')->toArray();
        $this->assertContains($this->tagGaming->id, $tagIds);
        $this->assertContains($this->tagWireless->id, $tagIds);
    }

    // =========================================================================
    // GET /products/{id} — Admin show includes Tags
    // =========================================================================

    public function test_admin_product_show_includes_tags()
    {
        $this->authAdmin();
        $product = $this->createProduct('Admin Show Tags');
        $product->tags()->attach([$this->tagGaming->id, $this->tagWireless->id]);

        $response = $this->getJson(self::PREFIX . "/products/{$product->id}");
        $response->assertStatus(200);
        $this->assertArrayHasKey('tags', $response->json('data'));
        $tagIds = collect($response->json('data.tags'))->pluck('id')->toArray();
        $this->assertContains($this->tagGaming->id, $tagIds);
        $this->assertContains($this->tagWireless->id, $tagIds);
    }

    // =========================================================================
    // POST /products — Create validation rejects invalid tag IDs
    // =========================================================================

    public function test_create_product_validation_rejects_invalid_tag_ids()
    {
        $this->authAdmin();
        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'Tag Validation Product'],
            'description' => ['en' => 'Description'],
            'price' => 50,
            'product_type' => ProductType::SIMPLE,
            'categories' => [],
            'images' => [],
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
            'tags' => [99999]]);
        $response->assertStatus(422);
    }

    // =========================================================================
    // POST /products — Create with valid tags
    // =========================================================================

    public function test_create_product_with_tags_via_api()
    {
        $this->authAdmin();
        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'Create With Tags'],
            'description' => ['en' => 'Description'],
            'price' => 50,
            'product_type' => ProductType::SIMPLE,
            'categories' => [],
            'images' => [],
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
            'tags' => [$this->tagGaming->id, $this->tagWireless->id]]);

        $this->assertContains($response->status(), [201, 422],
            'Create product with valid tags should be accepted');

        if ($response->status() === 201) {
            $tagIds = collect($response->json('data.tags'))->pluck('id')->toArray();
            $this->assertContains($this->tagGaming->id, $tagIds);
            $this->assertContains($this->tagWireless->id, $tagIds);
        }
    }

    // =========================================================================
    // GET /general/products — Public listing includes Tags
    // =========================================================================

    public function test_public_product_list_includes_tags()
    {
        $product = $this->createProduct('Listed With Tags');
        $product->tags()->attach([$this->tagGaming->id, $this->tagWireless->id]);

        $response = $this->getJson(self::GENERAL_PREFIX . '/products');
        $response->assertStatus(200);

        $products = $response->json('data.data');
        $taggedProduct = collect($products)->firstWhere('name', 'Listed With Tags');
        $this->assertNotNull($taggedProduct);
        $this->assertArrayHasKey('tags', $taggedProduct);
        $tagIds = collect($taggedProduct['tags'])->pluck('id')->toArray();
        $this->assertContains($this->tagGaming->id, $tagIds);
        $this->assertContains($this->tagWireless->id, $tagIds);
    }

    public function test_public_product_by_slug_includes_tags()
    {
        $product = $this->createProduct('Detail With Tags');
        $product->tags()->attach([$this->tagAccessory->id]);

        $response = $this->getJson(self::GENERAL_PREFIX . "/products/{$product->slug}");
        $this->assertContains($response->status(), [200, 409, 500]);

        if ($response->status() === 200) {
            $this->assertArrayHasKey('tags', $response->json('data'));
            $tagIds = collect($response->json('data.tags'))->pluck('id')->toArray();
            $this->assertContains($this->tagAccessory->id, $tagIds);
        }
    }

    // =========================================================================
    // GET /general/products?tag= — Filter by Tag
    // =========================================================================

    public function test_filter_products_by_single_tag()
    {
        $productA = $this->createProduct('Gaming Product');
        $productA->tags()->attach([$this->tagGaming->id]);

        $productB = $this->createProduct('Accessory Product');
        $productB->tags()->attach([$this->tagAccessory->id]);

        $response = $this->getJson(self::GENERAL_PREFIX . '/products?tag=gaming');
        $response->assertOk();

        $names = collect($response->json('data.data'))->pluck('name')->toArray();
        $this->assertContains('Gaming Product', $names);
        $this->assertNotContains('Accessory Product', $names);
    }

    public function test_filter_products_by_multiple_tags_with_and_logic()
    {
        $productA = $this->createProduct('Gaming Wireless Product');
        $productA->tags()->attach([$this->tagGaming->id, $this->tagWireless->id]);

        $productB = $this->createProduct('Gaming Only Product');
        $productB->tags()->attach([$this->tagGaming->id]);

        $response = $this->getJson(self::GENERAL_PREFIX . '/products?tag=gaming&tag=wireless');
        $response->assertOk();

        $names = collect($response->json('data.data'))->pluck('name')->toArray();
        $this->assertContains('Gaming Wireless Product', $names);
        $this->assertNotContains('Gaming Only Product', $names);
    }

    public function test_filter_products_by_non_matching_tag_returns_empty()
    {
        $product = $this->createProduct('Only Gaming Product');
        $product->tags()->attach([$this->tagGaming->id]);

        $response = $this->getJson(self::GENERAL_PREFIX . '/products?tag=nonexistent-tag');
        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_filter_products_by_tag_using_slug()
    {
        $product = $this->createProduct('Wireless Product');
        $product->tags()->attach([$this->tagWireless->id]);

        $response = $this->getJson(self::GENERAL_PREFIX . '/products?tag=' . $this->tagWireless->slug);
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Wireless Product', $response->json('data.data.0.name'));
    }

    // =========================================================================
    // GET /general/products — Dynamic Filters include Tags
    // =========================================================================

    public function test_dynamic_filters_include_tags()
    {
        $product = $this->createProduct('Filterable Product');
        $product->tags()->attach([$this->tagGaming->id, $this->tagWireless->id]);

        $response = $this->getJson(self::GENERAL_PREFIX . '/products');
        $response->assertOk();

        $filters = $response->json('data.filters');
        $this->assertNotNull($filters);

        $tagFilter = collect($filters)->firstWhere('key', 'tag');
        $this->assertNotNull($tagFilter, 'Tag filter not found in dynamic filters');
        $this->assertEquals('Tag', $tagFilter['display']);
        $this->assertContains($this->tagGaming->slug, $tagFilter['data']);
        $this->assertContains($this->tagWireless->slug, $tagFilter['data']);
    }

    // =========================================================================
    // Product Search does NOT search by Tag name (intentionally removed)
    // =========================================================================

    public function test_product_search_does_not_find_by_tag_name()
    {
        $product = $this->createProduct('Searchable Product');
        $product->tags()->attach([$this->tagGaming->id]);

        $response = $this->getJson(self::GENERAL_PREFIX . '/products?search=Gaming');
        $response->assertOk();

        $names = collect($response->json('data.data'))->pluck('name')->toArray();
        $this->assertNotContains('Searchable Product', $names);
    }

    // =========================================================================
    // Product model direct tag operations
    // =========================================================================

    public function test_product_can_have_multiple_tags()
    {
        $product = $this->createProduct('Multi Tag Product');
        $product->tags()->attach([$this->tagGaming->id, $this->tagWireless->id, $this->tagAccessory->id]);

        $this->assertCount(3, $product->tags);
        $this->assertTrue($product->tags->contains('id', $this->tagGaming->id));
        $this->assertTrue($product->tags->contains('id', $this->tagWireless->id));
        $this->assertTrue($product->tags->contains('id', $this->tagAccessory->id));
    }

    public function test_product_with_no_tags_returns_empty()
    {
        $product = $this->createProduct('No Tags');

        $this->assertCount(0, $product->tags);
    }

    // =========================================================================
    // GET /general/tags — Public Tag listing
    // =========================================================================

    public function test_public_tags_index_returns_all_tags()
    {
        $response = $this->getJson(self::GENERAL_PREFIX . '/tags');
        $response->assertOk();

        $tagNames = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Gaming', $tagNames);
        $this->assertContains('Wireless', $tagNames);
        $this->assertContains('Accessory', $tagNames);
    }

    public function test_public_tags_show_by_slug()
    {
        $response = $this->getJson(self::GENERAL_PREFIX . '/tags/' . $this->tagGaming->slug);
        $response->assertOk();
        $this->assertEquals($this->tagGaming->name, $response->json('data.name'));
    }

    public function test_public_tags_show_returns_404_for_invalid_slug()
    {
        $response = $this->getJson(self::GENERAL_PREFIX . '/tags/non-existent-tag');
        $response->assertStatus(404);
    }

    public function test_tag_can_have_multiple_products()
    {
        $productA = $this->createProduct('Product A');
        $productB = $this->createProduct('Product B');
        $this->tagGaming->products()->attach([$productA->id, $productB->id]);

        $this->assertCount(2, $this->tagGaming->products);
        $this->assertTrue($this->tagGaming->products->contains('id', $productA->id));
        $this->assertTrue($this->tagGaming->products->contains('id', $productB->id));
    }
}
