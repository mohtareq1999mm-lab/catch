<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Wishlist;
use Marvel\Enums\ProductType;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class WishlistApiTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $user;
    private User $otherUser;
    private Product $product;
    private Product $variableProduct;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        if (!Schema::hasTable('wishlists')) {
            Schema::create('wishlists', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->unsignedBigInteger('product_id');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->foreign('product_variant_id')->references('id')->on('product_variants')->onDelete('cascade');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('attribute_product')) {
            Schema::create('attribute_product', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('attribute_value_id');
                $table->unsignedBigInteger('product_variant_id');
                $table->timestamps();
            });
        }

        $this->user = User::create([
            'name' => 'Wishlist User',
            'email' => 'wishlist@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);

        $this->otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Simple Product',
            'slug' => 'simple-product-' . Str::random(8),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => 'publish',
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        $this->variableProduct = Product::create([
            'name' => 'Variable Product',
            'slug' => 'variable-product-' . Str::random(8),
            'price' => 200.00,
            'product_type' => ProductType::VARIABLE,
            'status' => 'publish',
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->variableProduct->id,
            'sku' => 'VAR-' . Str::random(6),
            'price' => 150.00,
            'stock_quantity' => 10,
            'in_stock' => true,
        ]);
    }

    private function auth(User $user = null): void
    {
        Sanctum::actingAs($user ?? $this->user);
    }

    private function addToWishlist(Product $product, ?ProductVariant $variant = null, ?User $user = null): void
    {
        $this->auth($user);
        $payload = ['product_id' => $product->id];
        if ($variant) {
            $payload['product_variant_id'] = $variant->id;
        }
        $this->postJson(self::PREFIX . '/wishlists', $payload)->assertStatus(200);
    }

    // =========================================================================
    // Authentication — every authenticated endpoint must return 401 for guests
    // =========================================================================

    public function test_index_requires_auth()
    {
        $this->getJson(self::PREFIX . '/wishlists')->assertStatus(401);
    }

    public function test_store_requires_auth()
    {
        $this->postJson(self::PREFIX . '/wishlists', ['product_id' => $this->product->id])
            ->assertStatus(401);
    }

    public function test_toggle_requires_auth()
    {
        $this->postJson(self::PREFIX . '/wishlists/toggle', ['product_id' => $this->product->id])
            ->assertStatus(401);
    }

    public function test_destroy_requires_auth()
    {
        $this->deleteJson(self::PREFIX . '/wishlists/' . $this->product->id)
            ->assertStatus(401);
    }

    public function test_my_wishlists_requires_auth()
    {
        $this->getJson(self::PREFIX . '/my-wishlists')->assertStatus(401);
    }

    // =========================================================================
    // in_wishlist — public, guest-safe
    // =========================================================================

    public function test_in_wishlist_guest_returns_false()
    {
        $this->getJson(self::PREFIX . '/wishlists/in_wishlist/' . $this->product->id)
            ->assertStatus(200)
            ->assertJson(['data' => false]);
    }

    public function test_in_wishlist_returns_true_when_product_in_wishlist()
    {
        $this->addToWishlist($this->product);

        $this->auth();
        $this->getJson(self::PREFIX . '/wishlists/in_wishlist/' . $this->product->id)
            ->assertStatus(200)
            ->assertJson(['data' => true]);
    }

    public function test_in_wishlist_returns_false_when_product_not_in_wishlist()
    {
        $this->auth();
        $this->getJson(self::PREFIX . '/wishlists/in_wishlist/' . $this->product->id)
            ->assertStatus(200)
            ->assertJson(['data' => false]);
    }

    public function test_in_wishlist_ignores_other_users_wishlist()
    {
        $this->addToWishlist($this->product, null, $this->otherUser);

        $this->auth();
        $this->getJson(self::PREFIX . '/wishlists/in_wishlist/' . $this->product->id)
            ->assertStatus(200)
            ->assertJson(['data' => false]);
    }

    // =========================================================================
    // GET /wishlists — index
    // =========================================================================

    public function test_index_returns_empty_when_no_wishlist()
    {
        $this->auth();
        $response = $this->getJson(self::PREFIX . '/wishlists');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 200)
            ->assertJsonCount(0, 'data.data');
    }

    public function test_index_returns_only_current_users_wishlist()
    {
        $this->addToWishlist($this->product, null, $this->otherUser);

        $this->auth();
        $response = $this->getJson(self::PREFIX . '/wishlists');
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.data');
    }

    public function test_index_returns_wishlist_products()
    {
        $this->addToWishlist($this->product);

        $this->auth();
        $response = $this->getJson(self::PREFIX . '/wishlists');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $this->product->id);
        $this->assertIsArray($response->json('data.data.0.images'));
    }

    public function test_index_is_paginated()
    {
        foreach (range(1, 5) as $i) {
            $p = Product::create([
                'name' => 'Product ' . $i,
                'slug' => 'product-' . $i . '-' . Str::random(6),
                'price' => 10 * $i,
                'product_type' => ProductType::SIMPLE,
                'status' => 'publish',
                'in_stock' => true,
                'stock_quantity' => 10,
            ]);
            $this->addToWishlist($p);
        }

        $this->auth();
        $response = $this->getJson(self::PREFIX . '/wishlists?limit=2');
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.data');

        $this->assertSame(5, Wishlist::where('user_id', $this->user->id)->count());
    }

    public function test_index_response_structure()
    {
        $this->auth();
        $response = $this->getJson(self::PREFIX . '/wishlists');
        $response->assertJsonStructure([
            'status',
            'message',
            'success',
            'data',
        ]);
    }

    // =========================================================================
    // POST /wishlists — store
    // =========================================================================

    public function test_store_adds_simple_product()
    {
        $this->auth();
        $response = $this->postJson(self::PREFIX . '/wishlists', ['product_id' => $this->product->id]);
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Added to wishlist successfully');

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_store_duplicate_returns_400_with_translated_message()
    {
        $this->addToWishlist($this->product);

        $this->auth();
        $response = $this->postJson(self::PREFIX . '/wishlists', ['product_id' => $this->product->id]);
        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This product is already added to the wishlist');

        $this->assertSame(1, Wishlist::where('user_id', $this->user->id)->where('product_id', $this->product->id)->count());
    }

    public function test_store_missing_product_id_returns_422()
    {
        $this->auth();
        $this->postJson(self::PREFIX . '/wishlists', [])
            ->assertStatus(422);
    }

    public function test_store_nonexistent_product_returns_422()
    {
        $this->auth();
        $this->postJson(self::PREFIX . '/wishlists', ['product_id' => 999999])
            ->assertStatus(422);
    }

    public function test_store_variable_product_without_variant_returns_422()
    {
        $this->auth();
        $this->postJson(self::PREFIX . '/wishlists', ['product_id' => $this->variableProduct->id])
            ->assertStatus(422);
    }

    public function test_store_variable_product_with_variant_succeeds()
    {
        $this->auth();
        $response = $this->postJson(self::PREFIX . '/wishlists', [
            'product_id' => $this->variableProduct->id,
            'product_variant_id' => $this->variant->id,
        ]);
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $this->variableProduct->id,
            'product_variant_id' => $this->variant->id,
        ]);
    }

    public function test_store_allows_same_product_with_different_variants()
    {
        $this->auth();

        $variantB = ProductVariant::create([
            'product_id' => $this->variableProduct->id,
            'sku' => 'VAR-B-' . Str::random(6),
            'price' => 120.00,
            'stock_quantity' => 5,
            'in_stock' => true,
        ]);

        $this->postJson(self::PREFIX . '/wishlists', [
            'product_id' => $this->variableProduct->id,
            'product_variant_id' => $this->variant->id,
        ])->assertStatus(200);

        $this->postJson(self::PREFIX . '/wishlists', [
            'product_id' => $this->variableProduct->id,
            'product_variant_id' => $variantB->id,
        ])->assertStatus(200);

        $this->assertSame(2, Wishlist::where('user_id', $this->user->id)->where('product_id', $this->variableProduct->id)->count());
    }

    // =========================================================================
    // POST /wishlists/toggle
    // =========================================================================

    public function test_toggle_adds_product_when_not_in_wishlist()
    {
        $this->auth();
        $response = $this->postJson(self::PREFIX . '/wishlists/toggle', ['product_id' => $this->product->id]);
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Added to wishlist successfully');

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_toggle_removes_product_when_in_wishlist()
    {
        $this->addToWishlist($this->product);

        $this->auth();
        $response = $this->postJson(self::PREFIX . '/wishlists/toggle', ['product_id' => $this->product->id]);
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Removed from wishlist successfully');

        $this->assertDatabaseMissing('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_toggle_simple_product_does_not_create_duplicates()
    {
        $this->addToWishlist($this->product);

        $this->auth();
        $this->postJson(self::PREFIX . '/wishlists/toggle', ['product_id' => $this->product->id])->assertStatus(200);
        $this->postJson(self::PREFIX . '/wishlists/toggle', ['product_id' => $this->product->id])->assertStatus(200);

        $this->assertSame(1, Wishlist::where('user_id', $this->user->id)->where('product_id', $this->product->id)->count());
    }

    public function test_toggle_variable_product_without_variant_returns_422()
    {
        $this->auth();
        $this->postJson(self::PREFIX . '/wishlists/toggle', ['product_id' => $this->variableProduct->id])
            ->assertStatus(422);
    }

    // =========================================================================
    // DELETE /wishlists/{product_id} — destroy
    // =========================================================================

    public function test_destroy_removes_simple_product()
    {
        $this->addToWishlist($this->product);

        $this->auth();
        $response = $this->deleteJson(self::PREFIX . '/wishlists/' . $this->product->id);
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Removed from wishlist successfully');

        $this->assertDatabaseMissing('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_destroy_nonexistent_product_returns_404()
    {
        $this->auth();
        $this->deleteJson(self::PREFIX . '/wishlists/999999')
            ->assertStatus(404);
    }

    public function test_destroy_when_product_not_in_users_wishlist_returns_404()
    {
        $this->addToWishlist($this->product, null, $this->otherUser);

        $this->auth();
        $this->deleteJson(self::PREFIX . '/wishlists/' . $this->product->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $this->otherUser->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_destroy_variant_item_with_variant_id_query()
    {
        $this->auth();
        $this->postJson(self::PREFIX . '/wishlists', [
            'product_id' => $this->variableProduct->id,
            'product_variant_id' => $this->variant->id,
        ])->assertStatus(200);

        $response = $this->deleteJson(self::PREFIX . '/wishlists/' . $this->variableProduct->id . '?product_variant_id=' . $this->variant->id);
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $this->variableProduct->id,
            'product_variant_id' => $this->variant->id,
        ]);
    }

    public function test_destroy_simple_item_without_variant_does_not_delete_variant_item()
    {
        $this->auth();
        $this->postJson(self::PREFIX . '/wishlists', [
            'product_id' => $this->variableProduct->id,
            'product_variant_id' => $this->variant->id,
        ])->assertStatus(200);

        $this->deleteJson(self::PREFIX . '/wishlists/' . $this->variableProduct->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $this->user->id,
            'product_id' => $this->variableProduct->id,
            'product_variant_id' => $this->variant->id,
        ]);
    }

    // =========================================================================
    // GET /my-wishlists
    // =========================================================================

    public function test_my_wishlists_returns_empty()
    {
        $this->auth();
        $response = $this->getJson(self::PREFIX . '/my-wishlists');
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_my_wishlists_returns_products_for_current_user()
    {
        $this->addToWishlist($this->product);

        $this->auth();
        $response = $this->getJson(self::PREFIX . '/my-wishlists');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->product->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_my_wishlists_ignores_other_users_wishlist()
    {
        $this->addToWishlist($this->product, null, $this->otherUser);

        $this->auth();
        $this->getJson(self::PREFIX . '/my-wishlists')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_my_wishlists_is_paginated()
    {
        foreach (range(1, 3) as $i) {
            $p = Product::create([
                'name' => 'P ' . $i,
                'slug' => 'p-' . $i . '-' . Str::random(6),
                'price' => 5 * $i,
                'product_type' => ProductType::SIMPLE,
                'status' => 'publish',
                'in_stock' => true,
                'stock_quantity' => 5,
            ]);
            $this->addToWishlist($p);
        }

        $this->auth();
        $response = $this->getJson(self::PREFIX . '/my-wishlists?limit=2');
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
    }

    // =========================================================================
    // apiResource — show/update are intentionally not registered
    // =========================================================================

    public function test_show_route_is_not_registered()
    {
        $this->auth();
        $this->getJson(self::PREFIX . '/wishlists/' . $this->product->id)
            ->assertStatus(405);
    }

    public function test_update_route_is_not_registered()
    {
        $this->auth();
        $this->putJson(self::PREFIX . '/wishlists/' . $this->product->id)
            ->assertStatus(405);
    }
}
