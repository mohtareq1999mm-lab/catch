<?php

namespace Tests\Feature\Guest;

use App\Models\GuestIdentity;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Enums\ItemType;

class GuestSessionAndCartTest extends GuestCheckoutTestCase
{
    /** @test */
    public function session_endpoint_issues_httponly_guest_and_readable_csrf_cookies(): void
    {
        $response = $this->postJson(self::PREFIX . '/session');

        $response->assertOk()->assertJsonPath('success', true);

        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        $guest = $cookies[GuestIdentityService::COOKIE] ?? null;
        $this->assertNotNull($guest);
        $this->assertTrue($guest->isHttpOnly());
        $this->assertNotSame('', (string) $guest->getValue());

        $csrf = $cookies[GuestIdentityService::CSRF_COOKIE] ?? null;
        $this->assertNotNull($csrf);
        $this->assertFalse($csrf->isHttpOnly());

        // Raw token must never appear in JSON.
        $body = $response->getContent();
        $this->assertStringNotContainsString((string) $guest->getValue(), $body);
        $this->assertArrayNotHasKey('token', (array) $response->json('data'));
        $this->assertArrayNotHasKey('guest_token', (array) $response->json('data'));
    }

    /** @test */
    public function raw_token_is_not_stored_only_its_hash(): void
    {
        $response = $this->postJson(self::PREFIX . '/session');
        $raw = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === GuestIdentityService::COOKIE) {
                $raw = $cookie->getValue();
            }
        }

        $this->assertDatabaseMissing('guest_identities', ['token_hash' => $raw]);
        $this->assertSame(1, GuestIdentity::query()->count());

        $identity = GuestIdentity::query()->first();
        // The stored value is the keyed hash, not the raw token.
        $this->assertSame(
            app(GuestIdentityService::class)->hashToken($raw),
            $identity->token_hash
        );
    }

    /** @test */
    public function invalid_or_revoked_token_resolves_to_a_safe_empty_state(): void
    {
        $this->withUnencryptedCookies([
            GuestIdentityService::COOKIE => 'not-a-valid-token',
        ]);

        $this->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('data.cart', null);

        // Revoked token is rejected as well.
        $cookies = $this->startGuestSession();
        $identity = GuestIdentity::query()->first();
        $identity->forceFill(['revoked_at' => now()])->save();

        $this->asGuest()->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('data.cart', null);
    }

    /** @test */
    public function add_item_creates_guest_owned_cart_with_server_side_price(): void
    {
        $this->startGuestSession();

        $response = $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'shipping_method' => 'scheduled',
            // Client-supplied price must be ignored.
            'price' => 0.01,
            'total_price' => 0.01,
        ]);

        $response->assertCreated()->assertJsonPath('success', true);

        $cart = $this->currentGuestCart();
        $this->assertNotNull($cart);
        $this->assertNotNull($cart);
        $this->assertNull($cart->user_id, 'Guest carts must not fabricate a user');
        $this->assertSame(2, (int) $cart->items()->first()->quantity);
        $this->assertEquals(200.0, (float) $cart->total_price);
        $this->assertEquals(100.0, (float) $cart->items()->first()->price);
    }

    /** @test */
    public function cart_is_retrievable_across_requests_without_extending_retention(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart(['product_id' => $this->product->id, 'quantity' => 1, 'shipping_method' => 'scheduled'])
            ->assertCreated();

        $cart = $this->currentGuestCart();
        $this->assertNotNull($cart);
        $expiresAt = $cart->guest_expires_at->copy();

        // Simulate idle time without mutation.
        $this->travel(2)->days();

        $this->asGuest()->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('data.cart.id', $cart->id);

        $this->assertEquals(
            $expiresAt->timestamp,
            $cart->fresh()->guest_expires_at->timestamp,
            'GET must not extend guest cart retention'
        );
    }

    /** @test */
    public function genuine_mutation_extends_retention_to_seven_days(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart(['product_id' => $this->product->id, 'quantity' => 1, 'shipping_method' => 'scheduled'])
            ->assertCreated();

        $this->travel(3)->days();

        $this->addToGuestCart(['product_id' => $this->product->id, 'quantity' => 1, 'shipping_method' => 'scheduled'])
            ->assertCreated();

        $cart = $this->currentGuestCart();
        $this->assertNotNull($cart);
        $this->assertTrue($cart->guest_expires_at->gt(now()->addDays(6)));
        $this->assertSame(2, (int) $cart->items()->first()->quantity);
    }

    /** @test */
    public function update_and_remove_and_clear_operate_on_the_owned_cart(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart(['product_id' => $this->product->id, 'quantity' => 3, 'shipping_method' => 'scheduled'])
            ->assertCreated();

        $item = $this->currentGuestItem();
        $this->assertNotNull($item);

        $this->asGuest()->putJson(self::PREFIX . '/cart/items', [
            'item' => [
                'product_id' => $this->product->id,
                'quantity' => 1,
                'shipping_method' => 'scheduled',
                'operation' => 'decrement',
            ],
        ], $this->csrfHeaders())->assertOk();

        $this->assertSame(2, (int) $item->fresh()->quantity);

        $this->asGuest()->deleteJson(
            self::PREFIX . '/cart/items/' . $item->id,
            [],
            $this->csrfHeaders()
        )->assertOk();

        $this->assertSame(0, $this->guestItemCount());

        $this->addToGuestCart(['product_id' => $this->product->id, 'quantity' => 1, 'shipping_method' => 'scheduled'])
            ->assertCreated();

        $this->asGuest()->deleteJson(self::PREFIX . '/cart', [], $this->csrfHeaders())->assertOk();

        $this->assertSame(0, $this->guestItemCount());
    }

    /** @test */
    public function digital_products_are_rejected_for_guest_checkout(): void
    {
        $digital = $this->makeProduct([
            'item_type' => ItemType::DIGITAL,
            'name' => 'Guest Digital ' . uniqid(),
            'slug' => 'guest-digital-' . uniqid(),
        ]);

        $this->startGuestSession();

        $this->addToGuestCart([
            'product_id' => $digital->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertStatus(422);

        $this->assertSame(0, $this->guestItemCount());
    }

    /** @test */
    public function unavailable_products_are_rejected(): void
    {
        $inactive = $this->makeProduct([
            'status' => false,
            'name' => 'Inactive ' . uniqid(),
            'slug' => 'inactive-' . uniqid(),
        ]);

        $this->startGuestSession();

        $this->addToGuestCart([
            'product_id' => $inactive->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertStatus(422);

        $this->addToGuestCart([
            'product_id' => 99999999,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertStatus(422);
    }

    /** @test */
    public function guest_cannot_reach_another_guest_cart_through_ids(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart(['product_id' => $this->product->id, 'quantity' => 1, 'shipping_method' => 'scheduled'])
            ->assertCreated();

        $firstGuestCart = $this->currentGuestCart();
        $firstGuestItem = $this->currentGuestItem();
        $this->assertNotNull($firstGuestCart);
        $this->assertNotNull($firstGuestItem);

        // New browser => new identity => its own empty cart.
        $this->guestCookies = [];
        $this->startGuestSession(true);

        $this->asGuest()->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('data.cart', null);

        // Cannot delete the other guest's line by id.
        $this->asGuest()->deleteJson(
            self::PREFIX . '/cart/items/' . $firstGuestItem->id,
            [],
            $this->csrfHeaders()
        )->assertStatus(404);

        $this->assertNotNull($firstGuestItem->fresh());
        $this->assertSame($firstGuestCart->id, (int) $firstGuestItem->fresh()->cart_id);
    }

    /** @test */
    public function state_changing_guest_requests_require_csrf_token(): void
    {
        $this->startGuestSession();

        $this->asGuest()->postJson(
            self::PREFIX . '/cart/items',
            ['item' => ['product_id' => $this->product->id, 'quantity' => 1, 'shipping_method' => 'scheduled']]
        )->assertStatus(419);

        $this->assertSame(0, $this->guestItemCount());
    }

    /** @test */
    public function registered_users_cart_is_never_exposed_to_guest_endpoints(): void
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'Registered',
            'email' => 'registered-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);

        $cart = Cart::create(['user_id' => $user->id, 'status' => 'active']);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 100,
            'total_price' => 100,
            'shipping_method' => 'SCHEDULED',
        ]);

        $this->startGuestSession();

        $this->asGuest()->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertJsonPath('data.cart', null);

        $this->assertSame(1, DB::table('cart_items')->where('cart_id', $cart->id)->count());
    }
}
