<?php

namespace Tests\Feature\Guest;

use App\Models\GuestIdentity;
use App\Services\Guest\GuestIdentityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Enums\ItemType;
use Marvel\Enums\ProductType;
use Tests\TestCase;

/**
 * Shared fixtures for the guest checkout suites.
 *
 * DatabaseTransactions + the real migrated schema (isolated `catch_verify`).
 */
abstract class GuestCheckoutTestCase extends TestCase
{
    use DatabaseTransactions;

    protected const PREFIX = '/api/v1/general/guest';

    protected array $guestCookies = [];
    protected Governorate $governorate;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Config::set('scout.driver', 'null');
        Config::set('guest_checkout.digital_products_allowed', false);

        if (Schema::hasTable('settings') && !Settings::query()->exists()) {
            Settings::create([
                'site_name' => 'Guest Test Site',
                'options' => [
                    'catalog_currency_code' => 'EGP',
                    'base_currency_code' => 'EGP',
                ],
                'minimum_order_amount' => 0,
                'order_tax_enabled' => false,
            ]);
        }

        $country = Country::query()->first();
        if (!$country) {
            $country = Country::create(['name' => 'Guest Test Country']);
        }

        $governorate = Governorate::query()->first();
        if (!$governorate) {
            $governorate = Governorate::create([
                'country_id' => $country->id,
                'name' => 'Guest Test Governorate',
                'status' => true,
                'is_fast_shipping_enabled' => true,
            ]);
        }
        $this->governorate = $governorate;

        $this->product = $this->makeProduct();

        // Manual-payment placement auto-releases fulfillment, which requires
        // an ACTIVE default warehouse. Isolated verify databases may lack one.
        if (Schema::hasTable('warehouses')) {
            $default = \App\Models\Fulfillment\Warehouse::query()
                ->where('is_default', true)
                ->where('status', 'active')
                ->first();

            if (!$default) {
                $warehouse = \App\Models\Fulfillment\Warehouse::create([
                    'code' => 'GUEST-TEST-WH',
                    'name' => 'Guest Test Warehouse',
                    'address' => 'Test address',
                    'city' => 'Test City',
                    'country' => 'EG',
                    'is_default' => true,
                    'metadata' => [],
                ]);
                $warehouse->forceFill(['status' => 'active'])->save();
            }
        }
    }

    protected function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Guest Product ' . Str::random(6),
            'slug' => 'guest-product-' . Str::random(10),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'item_type' => ItemType::PHYSICAL,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'is_fast_shipping_available' => false,
            'has_discount' => false,
            'has_flash_sale' => false,
        ], $overrides));
    }

    /** Create a guest identity through the public endpoint and keep cookies. */
    protected function startGuestSession(bool $fresh = false): array
    {
        if ($fresh) {
            // The test client merges cookies across requests; blank them so a
            // "new browser" scenario truly starts without a credential.
            $this->withUnencryptedCookies([
                GuestIdentityService::COOKIE => '',
                GuestIdentityService::CSRF_COOKIE => '',
            ]);
        }

        $response = $this->postJson(self::PREFIX . '/session');
        $response->assertOk();

        $this->guestCookies = $this->cookiesFrom($response);

        $this->assertArrayHasKey(GuestIdentityService::COOKIE, $this->guestCookies);
        $this->assertArrayHasKey(GuestIdentityService::CSRF_COOKIE, $this->guestCookies);

        return $this->guestCookies;
    }

    protected function cookiesFrom(TestResponse $response): array
    {
        $cookies = [];

        foreach ($response->headers->getCookies() as $cookie) {
            // Symfony URL-encodes non-raw cookie values in the Set-Cookie
            // header; browsers/PHP decode them before the request. Mirror that
            // so the sealed value reaches EncryptCookies byte-identical.
            $cookies[$cookie->getName()] = urldecode((string) $cookie->getValue());
        }

        return $cookies;
    }

    /** Send the browser cookie exactly as the browser would (already sealed). */
    protected function asGuest(): static
    {
        return $this->withCredentials()->withUnencryptedCookies([
            GuestIdentityService::COOKIE => $this->guestCookies[GuestIdentityService::COOKIE],
            GuestIdentityService::CSRF_COOKIE => $this->guestCookies[GuestIdentityService::CSRF_COOKIE],
        ]);
    }

    protected function csrfHeaders(): array
    {
        return [
            'X-CSRF-Token' => $this->guestCookies[GuestIdentityService::CSRF_COOKIE],
        ];
    }

    /** The guest identity created by the latest startGuestSession() call. */
    protected function currentGuestId(): int
    {
        return (int) GuestIdentity::query()->orderByDesc('id')->value('id');
    }

    protected function currentGuestCart(): ?\Marvel\Database\Models\Cart
    {
        return \Marvel\Database\Models\Cart::query()
            ->where('guest_id', $this->currentGuestId())
            ->first();
    }

    protected function currentGuestOrders()
    {
        return Order::query()->where('guest_id', $this->currentGuestId());
    }

    protected function guestItemCount(): int
    {
        $cart = $this->currentGuestCart();

        return $cart
            ? \Marvel\Database\Models\CartItem::query()->where('cart_id', $cart->id)->count()
            : 0;
    }

    protected function currentGuestItem(): ?\Marvel\Database\Models\CartItem
    {
        $cart = $this->currentGuestCart();

        return $cart
            ? \Marvel\Database\Models\CartItem::query()->where('cart_id', $cart->id)->first()
            : null;
    }

    protected function addToGuestCart(array $item, ?array $cookies = null): TestResponse
    {
        if ($cookies !== null) {
            $this->guestCookies = $cookies;
        }

        return $this->asGuest()->postJson(
            self::PREFIX . '/cart/items',
            ['item' => $item],
            $this->csrfHeaders()
        );
    }

    protected function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Guest Buyer',
            'user_phone' => '+201000000001',
            'user_email' => 'guest.buyer@example.test',
            'address' => [
                'street' => '1 Guest Street',
                'city' => 'Test City',
            ],
            'governorate_id' => $this->governorate->id,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'shipping_type' => 'local',
        ], $overrides);
    }

    protected function submitGuestCheckout(array $overrides = [], ?string $idempotencyKey = null): TestResponse
    {
        $headers = $this->csrfHeaders();

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->asGuest()->postJson(
            self::PREFIX . '/checkout',
            $this->checkoutPayload($overrides),
            $headers
        );
    }

    /**
     * Create a guest order through the real endpoints.
     *
     * @return array{order: Order, tracking_token: ?string, cancellation_token: ?string}
     */
    protected function createGuestOrder(string $paymentMethod = 'online', int $quantity = 1): array
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $response = $this->submitGuestCheckout(
            ['payment_method' => $paymentMethod],
            'order-key-' . $paymentMethod . '-' . uniqid()
        );

        $response->assertCreated();

        return [
            'order' => $this->currentGuestOrders()->firstOrFail(),
            'tracking_token' => $response->json('data.tracking_token'),
            'cancellation_token' => $response->json('data.cancellation_token'),
        ];
    }

    protected function initiateGuestOnline(int $orderId, string $key, string $gateway = 'stripe'): TestResponse
    {
        return $this->asGuest()->postJson(
            self::PREFIX . '/orders/' . $orderId . '/payment',
            ['payment_method' => 'online', 'gateway' => $gateway],
            array_merge($this->csrfHeaders(), ['Idempotency-Key' => $key])
        );
    }
}
