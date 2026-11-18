<?php

namespace Tests\Feature\Guest;

use App\Services\Guest\GuestIdentityService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ProductType;

/**
 * Cross-transport Eligibility parity + browser regression + hardening (T21).
 *
 * The middleware-level throttle test runs on the suite's configured cache
 * store (array in phpunit, isolated per test). The limiter policy itself is
 * also proven against the real Redis store (unique keys, explicit cleanup;
 * skipped when Redis is unavailable) so production parity is covered.
 */
class GuestEligibilityParityTest extends GuestCheckoutTestCase
{
    private function mobileToken(): string
    {
        $response = $this->withUnencryptedCookies([
            GuestIdentityService::COOKIE => '',
            GuestIdentityService::CSRF_COOKIE => '',
        ])->withHeaders(['Authorization' => ''])
            ->postJson(self::PREFIX . '/mobile/session');

        $response->assertCreated();

        return (string) $response->json('data.token');
    }

    private function asBearer(string $token): static
    {
        return $this->withUnencryptedCookies([
            GuestIdentityService::COOKIE => '',
            GuestIdentityService::CSRF_COOKIE => '',
        ])->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function variableProduct(): Product
    {
        return $this->makeProduct([
            'product_type' => ProductType::VARIABLE,
            'stock_quantity' => 100,
        ]);
    }

    private function variant(Product $product, array $overrides = []): ProductVariant
    {
        $variant = ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'PARITY-' . Str::random(8),
            'price' => 100,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'in_stock' => true,
        ], $overrides));

        return $variant->refresh();
    }

    public function test_eligibility_outcomes_are_identical_across_transports(): void
    {
        // ---- success parity -------------------------------------------------
        $this->startGuestSession();

        $payload = [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ];

        $this->addToGuestCart($payload)->assertCreated();

        // ---- cookie rejection (while the client is still cookie-only) -------
        $product = $this->variableProduct();
        $foreignVariant = $this->variant($this->variableProduct());

        $rejected = [
            'product_id' => $product->id,
            'product_variant_id' => $foreignVariant->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ];

        // Variant ownership is a FormRequest validation rule (S9) -> 422 with
        // a field error on both transports.
        $this->addToGuestCart($rejected)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['item.product_variant_id']]);

        // ---- mobile success + rejection parity ------------------------------
        $token = $this->mobileToken();

        $this->asBearer($token)->postJson(
            self::PREFIX . '/cart/items',
            ['item' => $payload]
        )->assertCreated();

        $this->asBearer($token)->postJson(
            self::PREFIX . '/cart/items',
            ['item' => $rejected]
        )->assertStatus(422)
            ->assertJsonStructure(['errors' => ['item.product_variant_id']]);
    }

    public function test_authenticated_leg_asserts_the_documented_divergences(): void
    {
        $customer = \Marvel\Database\Models\User::create([
            'name' => 'Parity Customer',
            'email' => 'parity-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);

        $digital = $this->makeProduct(['item_type' => \Marvel\Enums\ItemType::DIGITAL]);

        // Documented divergence (Q7/D3): guests may not buy digital products;
        // an authenticated customer may.
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/cart', [
            'item' => [
                'product_id' => $digital->id,
                'quantity' => 1,
                'shipping_method' => 'scheduled',
            ],
        ])->assertCreated();

        // Documented divergence: guests may never use the fast-shipping
        // method; an authenticated customer may — when the product itself is
        // fast-shipping eligible (customer eligibility is product-gated).
        $fastProduct = $this->makeProduct(['is_fast_shipping_available' => true]);

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/cart', [
            'item' => [
                'product_id' => $fastProduct->id,
                'quantity' => 1,
                'shipping_method' => 'fast',
            ],
        ])->assertCreated();

        // Shared simple physical product: guests and customers add it
        // identically (same eligibility outcome across the credential divide).
        $this->startGuestSession();

        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();
    }

    public function test_csrf_mismatch_still_returns_419_for_browser_guests(): void
    {
        $this->startGuestSession();

        // Cookie credential present, CSRF header missing: unchanged 419.
        $this->asGuest()->postJson(self::PREFIX . '/cart/items', [
            'item' => [
                'product_id' => $this->product->id,
                'quantity' => 1,
                'shipping_method' => 'scheduled',
            ],
        ])->assertStatus(419);
    }

    public function test_foreign_origin_still_returns_403_for_browser_guests(): void
    {
        $this->startGuestSession();

        $this->asGuest()
            ->withHeaders(['Origin' => 'https://evil.example'])
            ->postJson(
                self::PREFIX . '/cart/items',
                [
                    'item' => [
                        'product_id' => $this->product->id,
                        'quantity' => 1,
                        'shipping_method' => 'scheduled',
                    ],
                ],
                $this->csrfHeaders()
            )->assertStatus(403);
    }

    public function test_guest_responses_are_not_cacheable_on_either_transport(): void
    {
        $this->startGuestSession();

        $this->asGuest()->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private');

        $token = $this->mobileToken();

        $this->asBearer($token)->getJson(self::PREFIX . '/cart')
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private');
    }

    public function test_guest_restrictions_are_identical_across_transports(): void
    {
        $this->startGuestSession();

        // Digital: rejected for guests on both transports (D3).
        $digital = $this->makeProduct(['item_type' => \Marvel\Enums\ItemType::DIGITAL]);

        // Cookie assertions first: a later bearer header would otherwise ride
        // along and trip the credential-conflict guard.
        $this->addToGuestCart([
            'product_id' => $digital->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertStatus(422)
            ->assertJsonPath('data.reason', 'digital_not_supported');

        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'fast',
        ])->assertStatus(422)
            ->assertJsonPath('data.reason', 'fast_shipping_not_eligible');

        // Bearer leg.
        $token = $this->mobileToken();

        $this->asBearer($token)->postJson(self::PREFIX . '/cart/items', ['item' => [
            'product_id' => $digital->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ]])->assertStatus(422)
            ->assertJsonPath('data.reason', 'digital_not_supported');

        $this->asBearer($token)->postJson(self::PREFIX . '/cart/items', ['item' => [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'fast',
        ]])->assertStatus(422)
            ->assertJsonPath('data.reason', 'fast_shipping_not_eligible');
    }

    public function test_cart_writes_are_limited_per_guest(): void
    {
        $token = $this->mobileToken();

        $payload = ['item' => [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ]];

        // guest_cart_write: 40/min per guest identity.
        for ($i = 1; $i <= 40; $i++) {
            $this->asBearer($token)->postJson(self::PREFIX . '/cart/items', $payload)->assertCreated();
        }

        $this->asBearer($token)->postJson(self::PREFIX . '/cart/items', $payload)
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    public function test_session_creation_is_ip_rate_limited_by_the_middleware(): void
    {
        // The suite cache store (array) is isolated per test, so exhausting the
        // limiter here cannot leak into other tests.
        $last = null;

        for ($i = 1; $i <= 11; $i++) {
            $last = $this->withUnencryptedCookies([
                GuestIdentityService::COOKIE => '',
                GuestIdentityService::CSRF_COOKIE => '',
            ])->withHeaders(['Authorization' => ''])->postJson(self::PREFIX . '/mobile/session');

            if ($i <= 10) {
                $last->assertCreated();
            }
        }

        $last->assertStatus(429)->assertJsonPath('success', false);
    }

    public function test_rate_limiter_policy_holds_on_the_redis_store(): void
    {
        try {
            Redis::connection()->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not available: ' . $e->getMessage());
        }

        $limiter = new RateLimiter(Cache::store('redis'));
        $key = 'guest-rl-parity-' . Str::random(12);

        try {
            for ($i = 1; $i <= 10; $i++) {
                $this->assertFalse(
                    $limiter->tooManyAttempts($key, 10),
                    'Attempt ' . $i . ' of 10 must pass on the Redis store.'
                );
                $limiter->hit($key, 60);
            }

            $this->assertTrue(
                $limiter->tooManyAttempts($key, 10),
                'The 11th attempt must be limited on the Redis store.'
            );
        } finally {
            $limiter->clear($key);
        }
    }
}
