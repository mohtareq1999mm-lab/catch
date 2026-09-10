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

class TagCrudTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $admin;
    private User $normalUser;
    private Product $productA;
    private Product $productB;

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

        foreach ([
            Permission::VIEW_TAGS,
            Permission::CREATE_TAGS,
            Permission::UPDATE_TAGS,
            Permission::DELETE_TAGS] as $perm) {
            \Spatie\Permission\Models\Permission::create(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->admin->givePermissionTo([
            Permission::VIEW_TAGS,
            Permission::CREATE_TAGS,
            Permission::UPDATE_TAGS,
            Permission::DELETE_TAGS]);

        $this->productA = $this->createProduct('Tag Product A');
        $this->productB = $this->createProduct('Tag Product B');
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

    private function createTag(string $name = 'Organic', array $extra = []): Tag
    {
        return Tag::create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(4)], $extra));
    }

    // =========================================================================
    // GET /tags — index
    // =========================================================================

    public function test_index_returns_standard_response_wrapper()
    {
        $this->authAdmin();
        $this->createTag('Organic');
        $this->createTag('Premium');

        $response = $this->getJson(self::PREFIX . '/tags');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('status', 200);
        $response->assertJsonStructure([
            'success', 'message', 'status', 'data' => ['data']]);
        $this->assertIsArray($response->json('data.data'));
    }

    // =========================================================================
    // POST /tags — store
    // =========================================================================

    public function test_store_returns_201_with_standard_wrapper()
    {
        $this->authAdmin();

        $response = $this->postJson(self::PREFIX . '/tags', [
            'name' => ['en' => 'Organic', 'ar' => 'عضوي']]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('status', 201);
        $response->assertJsonPath('data.name', 'Organic');
        $this->assertNotEmpty($response->json('data.slug'));
        $this->assertDatabaseHas('tags', ['slug' => $response->json('data.slug')]);
    }

    public function test_store_syncs_products_relation()
    {
        $this->authAdmin();

        $response = $this->postJson(self::PREFIX . '/tags', [
            'name' => ['en' => 'Organic'],
            'products' => [$this->productA->id, $this->productB->id]]);

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('data.products'));
        $productIds = collect($response->json('data.products'))->pluck('id')->toArray();
        $this->assertContains($this->productA->id, $productIds);
        $this->assertContains($this->productB->id, $productIds);

        $tagId = $response->json('data.id');
        $this->assertDatabaseHas('product_tag', ['tag_id' => $tagId, 'product_id' => $this->productA->id]);
        $this->assertDatabaseHas('product_tag', ['tag_id' => $tagId, 'product_id' => $this->productB->id]);
    }

    public function test_store_rejects_invalid_product_ids()
    {
        $this->authAdmin();

        $response = $this->postJson(self::PREFIX . '/tags', [
            'name' => ['en' => 'Invalid Products'],
            'products' => [999999]]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // GET /tags/{id} — show
    // =========================================================================

    public function test_show_by_id_returns_wrapper_with_products()
    {
        $this->authAdmin();
        $tag = $this->createTag('Organic');
        $tag->products()->attach([$this->productA->id]);

        $response = $this->getJson(self::PREFIX . '/tags/' . $tag->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $tag->id);
        $this->assertCount(1, $response->json('data.products'));
        $this->assertEquals($this->productA->id, $response->json('data.products.0.id'));
    }

    public function test_show_by_slug_returns_wrapper()
    {
        $this->authAdmin();
        $tag = $this->createTag('Organic');

        $response = $this->getJson(self::PREFIX . '/tags/' . $tag->slug);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.slug', $tag->slug);
    }

    // =========================================================================
    // PUT /tags/{id} — update
    // =========================================================================

    public function test_update_returns_200_with_wrapper()
    {
        $this->authAdmin();
        $tag = $this->createTag('Organic');

        $response = $this->putJson(self::PREFIX . '/tags/' . $tag->id, [
            'name' => ['en' => 'Organic Premium']]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('status', 200);
        $response->assertJsonPath('data.name', 'Organic Premium');
        $response->assertJsonPath('data.id', $tag->id);
    }

    public function test_update_syncs_products_relation()
    {
        $this->authAdmin();
        $tag = $this->createTag('Organic');
        $tag->products()->attach([$this->productA->id]);

        $response = $this->putJson(self::PREFIX . '/tags/' . $tag->id, [
            'products' => [$this->productB->id]]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.products'));
        $this->assertEquals($this->productB->id, $response->json('data.products.0.id'));

        $this->assertDatabaseMissing('product_tag', ['tag_id' => $tag->id, 'product_id' => $this->productA->id]);
        $this->assertDatabaseHas('product_tag', ['tag_id' => $tag->id, 'product_id' => $this->productB->id]);
    }

    public function test_update_with_empty_products_clears_relation()
    {
        $this->authAdmin();
        $tag = $this->createTag('Organic');
        $tag->products()->attach([$this->productA->id]);

        $response = $this->putJson(self::PREFIX . '/tags/' . $tag->id, [
            'products' => []]);

        $response->assertOk();
        $this->assertDatabaseMissing('product_tag', ['tag_id' => $tag->id, 'product_id' => $this->productA->id]);
    }

    public function test_update_rejects_invalid_product_ids()
    {
        $this->authAdmin();
        $tag = $this->createTag('Organic');

        $response = $this->putJson(self::PREFIX . '/tags/' . $tag->id, [
            'products' => [999999]]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // DELETE /tags/{id} — destroy
    // =========================================================================

    public function test_destroy_returns_200_with_wrapper_and_true()
    {
        $this->authAdmin();
        $tag = $this->createTag('Organic');
        $tag->products()->attach([$this->productA->id]);

        $response = $this->deleteJson(self::PREFIX . '/tags/' . $tag->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('status', 200);
        $response->assertJsonPath('data', true);

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseMissing('product_tag', ['tag_id' => $tag->id]);
    }

    // =========================================================================
    // Authorization
    // =========================================================================

    public function test_guest_cannot_access_tags_crud()
    {
        $this->getJson(self::PREFIX . '/tags')->assertStatus(401);
        $this->postJson(self::PREFIX . '/tags', ['name' => ['en' => 'X']])->assertStatus(401);
    }

    public function test_user_without_permission_cannot_create()
    {
        $this->authUser();

        $this->postJson(self::PREFIX . '/tags', ['name' => ['en' => 'X']])->assertStatus(403);
    }
}
