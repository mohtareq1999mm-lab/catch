<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\General\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;
use Marvel\Enums\ShippingMethod;
use Tests\TestCase;

class PromotionCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeSimpleProduct(string $name, float $price, int $stock): Product
    {
        return Product::create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::uuid(),
            'price' => $price,
            'product_type' => ProductType::SIMPLE,
            'stock_quantity' => $stock,
            'reserved_quantity' => 0,
            'in_stock' => $stock > 0,
            'status' => true,
        ]);
    }

    private function makeCartWithItem(User $user, Product $product, float $price = 100, int $quantity = 1): Cart
    {
        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => 'active',
            'total_price' => 0,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'reserved_quantity' => $quantity,
            'price' => $price,
            'total_price' => $price * $quantity,
            'attributes' => null,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        return $cart;
    }

    public function test_eligible_promotions_endpoint_returns_promotions(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 100, 10);
        $this->makeCartWithItem($user, $product);

        Promotion::create([
            'name' => 'Eligible Promo',
            'code' => 'ELG-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $response = $this->getJson(self::PREFIX . '/general/checkout/promotions');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'eligible_promotions' => [
                    '*' => ['id', 'type', 'title', 'code', 'discount'],
                ],
            ],
        ]);
    }

    public function test_cart_item_resource_contains_promotion_fields(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $product);

        $promotion = Promotion::create([
            'name' => 'Promo',
            'code' => 'PRO-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $response = $this->getJson(self::PREFIX . '/cart/' . $cart->id);

        $response->assertOk();
        $items = $response->json('data.normal_items');
        if (!empty($items)) {
            $this->assertArrayHasKey('promotion_id', $items[0]);
            $this->assertArrayHasKey('discount_amount', $items[0]);
            $this->assertArrayHasKey('is_gift', $items[0]);
        }
    }

    public function test_cart_resource_contains_has_eligible_promotion(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $product);

        Promotion::create([
            'name' => 'Eligible',
            'code' => 'EL-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $response = $this->getJson(self::PREFIX . '/cart/' . $cart->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'has_eligible_promotion',
            ],
        ]);
        $this->assertTrue($response->json('data.has_eligible_promotion'));
    }

    public function test_cart_resource_has_eligible_promotion_false_when_none(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $product);

        $response = $this->getJson(self::PREFIX . '/cart/' . $cart->id);

        $response->assertOk();
        $this->assertFalse($response->json('data.has_eligible_promotion'));
    }

    public function test_public_promotion_listing_returns_active_promotions(): void
    {
        Promotion::create([
            'name' => 'Public Promo',
            'code' => 'PUB-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $response = $this->getJson(self::PREFIX . '/general/promotions');

        $response->assertOk();
    }

    public function test_promotion_by_slug_returns_promotion(): void
    {
        $slug = 'test-promo-' . Str::random(6);
        $promotion = Promotion::create([
            'name' => 'Test Promo',
            'slug' => $slug,
            'code' => 'SLG-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $response = $this->getJson(self::PREFIX . '/general/promotions/' . $slug);

        $response->assertOk();
        $response->assertJsonPath('data.id', $promotion->id);
    }
}
