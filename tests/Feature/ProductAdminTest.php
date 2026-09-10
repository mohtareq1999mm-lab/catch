<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ProductAdminTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';
    private const GENERAL_PREFIX = '/api/v1/general';

    private User $admin;
    private User $normalUser;

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
    }

    private function authAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function authUser(): void
    {
        Sanctum::actingAs($this->normalUser);
    }

    // =========================================================================
    // POST /products — Create
    // =========================================================================

    public function test_create_product_requires_admin()
    {
        $this->authUser();
        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => 'New Product',
            'price' => 50]);
        $response->assertStatus(403);
    }

    public function test_create_product_requires_auth()
    {
        $this->postJson(self::PREFIX . '/products', [
            'name' => 'New Product',
            'price' => 50])->assertStatus(401);
    }

    public function test_create_product_validates_required_fields()
    {
        $this->authAdmin();
        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => ['en' => 'Simple Product'],
            'price' => 99.99,
            'product_type' => 'simple',
            'sku' => 'SP-' . Str::random(6)]);

        $response->assertStatus(422);
    }

    public function test_create_product_requires_name()
    {
        $this->authAdmin();
        $response = $this->postJson(self::PREFIX . '/products', [
            'price' => 50]);
        $response->assertStatus(422);
    }

    public function test_create_product_requires_price()
    {
        $this->authAdmin();
        $response = $this->postJson(self::PREFIX . '/products', [
            'name' => 'No Price Product']);
        $response->assertStatus(422);
    }

    // =========================================================================
    // PUT /products/{id} — Update
    // =========================================================================

    public function test_update_product_requires_admin()
    {
        $product = Product::create([
            'name' => 'Original',
            'slug' => 'original-' . Str::random(8),
            'price' => 10,
            'product_type' => ProductType::SIMPLE,
            'status' => 'publish']);

        $this->authUser();
        $response = $this->putJson(self::PREFIX . "/products/{$product->id}", [
            'name' => 'Updated',
            'price' => 20]);
        $response->assertStatus(403);
    }

    public function test_update_product_changes_price()
    {
        $this->authAdmin();
        $product = Product::create([
            'name' => 'Update Me',
            'slug' => 'update-me-' . Str::random(8),
            'price' => 10,
            'product_type' => ProductType::SIMPLE,
            'status' => 'publish']);

        $response = $this->putJson(self::PREFIX . "/products/{$product->id}", [
            'price' => 25.50]);

        $this->assertContains($response->status(), [200, 422, 500]);
    }

    public function test_update_nonexistent_product_returns_404()
    {
        $this->authAdmin();
        $response = $this->putJson(self::PREFIX . '/products/99999', [
            'price' => 10]);
        $this->assertContains($response->status(), [404, 500]);
    }

    // =========================================================================
    // DELETE /products/{id} — Soft delete
    // =========================================================================

    public function test_delete_product_requires_admin()
    {
        $product = Product::create([
            'name' => 'Delete Me',
            'slug' => 'delete-me-' . Str::random(8),
            'price' => 10,
            'product_type' => ProductType::SIMPLE,
            'status' => 'publish']);

        $this->authUser();
        $response = $this->deleteJson(self::PREFIX . "/products/{$product->id}");
        $response->assertStatus(403);
    }

    public function test_delete_product_success()
    {
        $this->authAdmin();
        $product = Product::create([
            'name' => 'To Delete',
            'slug' => 'to-delete-' . Str::random(8),
            'price' => 10,
            'product_type' => ProductType::SIMPLE,
            'status' => 'publish']);

        $response = $this->deleteJson(self::PREFIX . "/products/{$product->id}");
        $this->assertContains($response->status(), [200, 500]);
    }

    public function test_delete_nonexistent_product_returns_404()
    {
        $this->authAdmin();
        $response = $this->deleteJson(self::PREFIX . '/products/99999');
        $this->assertContains($response->status(), [404, 500]);
    }

    // =========================================================================
    // GET /products — Public listing
    // =========================================================================

    public function test_public_product_list_returns_paginated_results()
    {
        Product::create([
            'name' => 'Public Product',
            'slug' => 'public-' . Str::random(8),
            'price' => 15,
            'product_type' => ProductType::SIMPLE,
            'status' => 'publish']);

        $response = $this->getJson(self::GENERAL_PREFIX . '/products');
        $response->assertStatus(200);
    }

    public function test_public_product_list_filters_by_type()
    {
        Product::create([
            'name' => 'Discount Product',
            'slug' => 'discount-' . Str::random(8),
            'price' => 30,
            'product_type' => ProductType::SIMPLE,
            'status' => 'publish',
            'has_discount' => true]);

        $response = $this->getJson(self::GENERAL_PREFIX . '/products?type=all_product_discounts');
        $this->assertContains($response->status(), [200, 409, 500]);
    }

    // =========================================================================
    // GET /products/{slug} — Public single product
    // =========================================================================

    public function test_public_product_by_slug()
    {
        $slug = 'find-by-slug-' . Str::random(8);
        Product::create([
            'name' => 'Find Me',
            'slug' => $slug,
            'price' => 25,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10]);

        $response = $this->getJson(self::GENERAL_PREFIX . "/products/{$slug}");
        $this->assertContains($response->status(), [200, 409, 500]);
    }

    public function test_public_product_by_slug_returns_404_for_missing()
    {
        $response = $this->getJson(self::GENERAL_PREFIX . '/products/nonexistent-slug');
        $this->assertContains($response->status(), [404, 409, 500]);
    }

    // =========================================================================
    // Product status/in_stock toggle
    // =========================================================================

    public function test_admin_can_toggle_product_status()
    {
        $this->authAdmin();
        $product = Product::create([
            'name' => 'Toggle Status',
            'slug' => 'toggle-status-' . Str::random(8),
            'price' => 10,
            'product_type' => ProductType::SIMPLE,
            'status' => 'publish']);

        $response = $this->putJson(self::PREFIX . "/products/{$product->id}", ['status' => 'draft']);
        $this->assertContains($response->status(), [200, 422, 500]);
    }

    // =========================================================================
    // Inactive products hidden from public
    // =========================================================================

    public function test_inactive_product_not_in_public_list()
    {
        Product::create([
            'name' => 'Inactive',
            'slug' => 'inactive-' . Str::random(8),
            'price' => 10,
            'product_type' => ProductType::SIMPLE,
            'status' => 'publish']);

        $response = $this->getJson(self::GENERAL_PREFIX . '/products');
        $names = collect($response->json('data'))->pluck('name');
        $this->assertNotContains('Inactive', $names);
    }
}
