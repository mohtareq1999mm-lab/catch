<?php

namespace Tests\Feature\Guest;

use App\Models\GuestIdentity;
use App\Services\Guest\GuestCartMergeService;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Marvel\Database\Repositories\CartRepository;
use Marvel\Enums\ProductType;

class GuestCartMergeTest extends GuestCheckoutTestCase
{
    private function makeUser(): User
    {
        return User::create([
            'name' => 'Merge User',
            'email' => 'merge-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'phone_number' => '+20' . substr(str_replace('.', '', uniqid('', true)), -10),
            'type' => 'user',
            'is_active' => true,
        ]);
    }

    private function mergeRequest(): Request
    {
        $request = Request::create('/api/v1/token', 'POST');
        $request->cookies->set(
            GuestIdentityService::COOKIE,
            $this->guestCookies[GuestIdentityService::COOKIE]
        );

        return $request;
    }

    private function merge(User $user): array
    {
        return app(GuestCartMergeService::class)->merge($this->mergeRequest(), $user);
    }

    private function accountCart(User $user): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'active', 'total_price' => 0]
        );
    }

    private function addAccountLine(Cart $cart, Product $product, int $quantity, ?ProductVariant $variant = null): CartItem
    {
        $price = $variant ? (float) $variant->price : (float) $product->price;

        $item = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => $quantity,
            'price' => $price,
            'total_price' => round($price * $quantity, 2),
            'shipping_method' => 'SCHEDULED',
        ]);

        $cart->update(['total_price' => round((float) $cart->items()->sum('total_price'), 2)]);

        return $item;
    }

    private function guestCart(): ?Cart
    {
        return Cart::query()->where('guest_id', $this->currentGuestId())->first();
    }

    private function guestIdentity(): GuestIdentity
    {
        return GuestIdentity::query()->findOrFail($this->currentGuestId());
    }

    /** @test */
    public function guest_only_item_is_added_to_the_authenticated_cart(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();
        $result = $this->merge($user);

        $this->assertSame('merged', $result['status']);
        $this->assertSame(1, $result['added_items']);
        $this->assertSame(0, $result['merged_items']);
        $this->assertTrue($result['guest_cart_removed']);

        $accountCart = $this->accountCart($user);
        $this->assertSame(1, $accountCart->items()->count());
        $this->assertSame(2, (int) $accountCart->items()->first()->quantity);
        $this->assertSame(200.0, (float) $accountCart->fresh()->total_price);

        $this->assertNull($this->guestCart());
    }

    /** @test */
    public function matching_product_and_variant_quantities_are_summed(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();
        $cart = $this->accountCart($user);
        $this->addAccountLine($cart, $this->product, 1);

        $result = $this->merge($user);

        $this->assertSame('merged', $result['status']);
        $this->assertSame(0, $result['added_items']);
        $this->assertSame(1, $result['merged_items']);
        $this->assertSame(3, (int) $cart->items()->first()->fresh()->quantity);
    }

    /** @test */
    public function different_variants_remain_separate_lines(): void
    {
        $product = $this->makeProduct([
            'product_type' => ProductType::VARIABLE,
            'name' => 'Variable Merge ' . uniqid(),
            'slug' => 'variable-merge-' . uniqid(),
            'stock_quantity' => 20,
        ]);

        $variantA = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-A-' . uniqid(),
            'price' => 100,
            'stock_quantity' => 10,
            'quantity' => 10,
            'in_stock' => true,
        ]);
        $variantB = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-B-' . uniqid(),
            'price' => 120,
            'stock_quantity' => 10,
            'quantity' => 10,
            'in_stock' => true,
        ]);

        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $product->id,
            'product_variant_id' => $variantA->id,
            'quantity' => 2,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();
        $cart = $this->accountCart($user);
        $this->addAccountLine($cart, $product, 1, $variantB);

        $this->merge($user);

        $this->assertSame(2, $cart->items()->count());
        $this->assertSame(1, (int) $cart->items()->where('product_variant_id', $variantB->id)->first()->quantity);
        $this->assertSame(2, (int) $cart->items()->where('product_variant_id', $variantA->id)->first()->quantity);
    }

    /** @test */
    public function stock_limits_cap_the_merged_quantity_and_report_the_adjustment(): void
    {
        $product = $this->makeProduct([
            'name' => 'Limited Merge ' . uniqid(),
            'slug' => 'limited-merge-' . uniqid(),
            'stock_quantity' => 5,
            'reserved_quantity' => 0,
        ]);

        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $product->id,
            'quantity' => 4,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();
        $cart = $this->accountCart($user);
        $this->addAccountLine($cart, $product, 3);

        $result = $this->merge($user);

        $this->assertSame(5, (int) $cart->items()->first()->fresh()->quantity);
        $this->assertNotEmpty($result['adjusted_items']);
        $this->assertSame('quantity_capped', $result['adjusted_items'][0]['reason']);
        $this->assertSame(2, $result['adjusted_items'][0]['dropped_quantity']);
    }

    /** @test */
    public function maximum_quantity_rule_is_applied(): void
    {
        config(['cart.max_item_quantity' => 5]);

        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 3,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();
        $cart = $this->accountCart($user);
        $this->addAccountLine($cart, $this->product, 4);

        $result = $this->merge($user);

        $this->assertSame(5, (int) $cart->items()->first()->fresh()->quantity);
        $this->assertSame(2, $result['adjusted_items'][0]['dropped_quantity']);
    }

    /** @test */
    public function unavailable_product_is_preserved_and_reported(): void
    {
        $unavailable = $this->makeProduct([
            'name' => 'Unavailable Merge ' . uniqid(),
            'slug' => 'unavailable-merge-' . uniqid(),
            'status' => false,
        ]);

        $this->startGuestSession();
        // Make sure the guest cart exists through the canonical endpoint.
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $guestCart = $this->guestCart();
        CartItem::create([
            'cart_id' => $guestCart->id,
            'product_id' => $unavailable->id,
            'quantity' => 2,
            'price' => 50,
            'total_price' => 100,
            'shipping_method' => 'SCHEDULED',
        ]);

        $user = $this->makeUser();
        $result = $this->merge($user);

        $this->assertSame('merged', $result['status']);
        $this->assertCount(1, $result['unavailable_items']);
        $this->assertSame('product_unavailable', $result['unavailable_items'][0]['reason']);

        $cart = $this->accountCart($user);
        $this->assertSame(1, $cart->items()->where('product_id', $unavailable->id)->count());
        $this->assertNull($this->guestCart());
    }

    /** @test */
    public function channel_ineligible_product_is_preserved_and_reported(): void
    {
        $fast = $this->makeProduct([
            'name' => 'Fast Channel Merge ' . uniqid(),
            'slug' => 'fast-channel-merge-' . uniqid(),
            'is_fast_shipping_available' => true,
        ]);

        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $guestCart = $this->guestCart();
        CartItem::create([
            'cart_id' => $guestCart->id,
            'product_id' => $fast->id,
            'quantity' => 1,
            'price' => 100,
            'total_price' => 100,
            'shipping_method' => 'SCHEDULED',
        ]);

        $user = $this->makeUser();
        $result = $this->merge($user);

        $this->assertSame('channel_not_eligible', $result['unavailable_items'][0]['reason']);
        $this->assertSame(1, $this->accountCart($user)->items()->where('product_id', $fast->id)->count());
    }

    /** @test */
    public function checkout_cannot_proceed_while_a_preserved_unavailable_item_is_in_the_cart(): void
    {
        $unavailable = $this->makeProduct([
            'name' => 'Blocked Checkout ' . uniqid(),
            'slug' => 'blocked-checkout-' . uniqid(),
            'status' => false,
        ]);

        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $guestCart = $this->guestCart();
        CartItem::create([
            'cart_id' => $guestCart->id,
            'product_id' => $unavailable->id,
            'quantity' => 1,
            'price' => 50,
            'total_price' => 50,
            'shipping_method' => 'SCHEDULED',
        ]);

        $user = $this->makeUser();
        $this->merge($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/general/checkout', $this->checkoutPayload())
            ->assertStatus(422);

        $this->assertSame(0, \Marvel\Database\Models\Order::query()->where('user_id', $user->id)->count());
    }

    /** @test */
    public function guest_cart_and_items_survive_a_failed_merge_transaction(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $guestCartId = $this->guestCart()->id;
        $guestItemId = $this->guestCart()->items()->first()->id;
        $user = $this->makeUser();

        // Fail at the very end of the merge transaction (guest cart delete).
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'delete from `carts`')) {
                throw new \RuntimeException('forced merge failure');
            }
        });

        $thrown = false;
        try {
            $this->merge($user);
        } catch (\Throwable $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'The forced failure must propagate');
        $this->assertNotNull(Cart::query()->find($guestCartId));
        $this->assertNotNull(CartItem::query()->find($guestItemId));
        $this->assertSame(0, $this->accountCart($user)->items()->count());
    }

    /** @test */
    public function repeated_merge_does_not_duplicate_quantities(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();
        $first = $this->merge($user);
        $second = $this->merge($user);

        $this->assertSame('merged', $first['status']);
        $this->assertSame('no_guest_cart', $second['status']);
        $this->assertSame(2, (int) $this->accountCart($user)->items()->first()->fresh()->quantity);
    }

    /** @test */
    public function another_guests_cart_is_never_merged(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $otherGuest = GuestIdentity::create([
            'token_hash' => hash('sha256', uniqid('other-guest', true)),
            'expires_at' => now()->addDay(),
        ]);
        $otherCart = Cart::create(['guest_id' => $otherGuest->id, 'status' => 'active', 'total_price' => 100]);
        CartItem::create([
            'cart_id' => $otherCart->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 100,
            'total_price' => 100,
            'shipping_method' => 'SCHEDULED',
        ]);

        $user = $this->makeUser();
        $this->merge($user);

        $this->assertSame(1, $otherCart->items()->count());
        $this->assertSame(1, $this->accountCart($user)->items()->count());
    }

    /** @test */
    public function failed_login_does_not_merge_the_guest_cart(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();

        $this->asGuest()->postJson('/api/v1/token', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(404);

        $this->assertNotNull($this->guestCart());
        $this->assertSame(0, Cart::query()->where('user_id', $user->id)->count());
    }

    /** @test */
    public function successful_password_login_merges_the_guest_cart(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();

        $response = $this->asGuest()->postJson('/api/v1/token', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.guest_cart_merge.status', 'merged');
        $response->assertJsonPath('data.guest_cart_merge.added_items', 1);

        $this->assertSame(2, (int) $this->accountCart($user)->items()->first()->quantity);
        $this->assertNull($this->guestCart());
    }

    /** @test */
    public function variant_stock_is_respected_when_merging_variable_products(): void
    {
        $product = $this->makeProduct([
            'product_type' => ProductType::VARIABLE,
            'name' => 'Variant Stock Merge ' . uniqid(),
            'slug' => 'variant-stock-merge-' . uniqid(),
            'stock_quantity' => 100,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-STOCK-' . uniqid(),
            'price' => 100,
            'stock_quantity' => 5,
            'quantity' => 5,
            'in_stock' => true,
        ]);

        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 4,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();
        $cart = $this->accountCart($user);
        $this->addAccountLine($cart, $product, 3, $variant);

        $result = $this->merge($user);

        $this->assertSame(5, (int) $cart->items()->first()->fresh()->quantity);
        $this->assertNotEmpty($result['adjusted_items']);
        $this->assertSame(2, $result['adjusted_items'][0]['dropped_quantity']);
    }

    /** @test */
    public function hard_deleted_product_is_preserved_and_blocks_checkout(): void
    {
        $product = $this->makeProduct([
            'name' => 'Force Deleted Merge ' . uniqid(),
            'slug' => 'force-deleted-merge-' . uniqid(),
        ]);

        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        // Hard delete => cart_items.product_id becomes NULL (nullOnDelete).
        Product::query()->whereKey($product->id)->firstOrFail()->forceDelete();
        $this->assertNull($this->guestCart()->items()->first()->fresh()->product_id);

        $user = $this->makeUser();
        $result = $this->merge($user);

        $this->assertSame('merged', $result['status']);
        $this->assertSame('product_deleted', $result['unavailable_items'][0]['reason']);

        $this->assertSame(
            1,
            $this->accountCart($user)->items()->whereNull('product_id')->count()
        );

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/general/checkout', $this->checkoutPayload())
            ->assertStatus(422);
    }

    /** @test */
    public function authenticated_digital_cart_lines_are_not_marked_unavailable_by_the_shared_resource(): void
    {
        $digital = $this->makeProduct([
            'name' => 'Digital Auth ' . uniqid(),
            'slug' => 'digital-auth-' . uniqid(),
            'item_type' => \Marvel\Enums\ItemType::DIGITAL,
        ]);

        $user = $this->makeUser();
        $cart = $this->accountCart($user);
        $this->addAccountLine($cart, $digital, 1);

        $resolved = \Marvel\Http\Resources\CartResource::make(
            $cart->load(['items.product', 'items.productVariant'])
        )->resolve(request());

        $items = $resolved['normal_items']->resolve(request());

        $this->assertTrue($items[0]['is_available']);
        $this->assertNull($items[0]['unavailable_reason']);    }

    /** @test */
    public function merge_failure_is_non_fatal_and_keeps_the_guest_cart(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $user = $this->makeUser();

        $this->mock(GuestCartMergeService::class, function ($mock) {
            $mock->shouldReceive('merge')->andThrow(new \RuntimeException('merge down'));
        });

        $response = $this->asGuest()->postJson('/api/v1/token', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.guest_cart_merge.status', 'failed');
        $this->assertNotNull($this->guestCart());
        $this->assertSame(0, Cart::query()->where('user_id', $user->id)->count());
    }

    /** @test */
    public function login_without_a_guest_cart_leaves_the_authenticated_cart_unchanged(): void
    {
        $user = $this->makeUser();
        $cart = $this->accountCart($user);
        $this->addAccountLine($cart, $this->product, 1);

        $response = $this->postJson('/api/v1/token', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('guest_cart_merge', (array) $response->json('data'));

        $this->assertSame(1, (int) $cart->items()->first()->fresh()->quantity);
    }

    /** @test */
    public function merge_uses_server_side_pricing_and_ignores_guest_price_snapshots(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        // Tamper with the guest snapshot; merge must reprice from the product.
        $this->guestCart()->items()->first()->update(['price' => 1, 'total_price' => 2]);

        $user = $this->makeUser();
        $this->merge($user);

        $line = $this->accountCart($user)->items()->first();
        $this->assertSame(100.0, (float) $line->price);
        $this->assertSame(200.0, (float) $line->total_price);
    }
}
