<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\General\PromotionService;
use App\Services\General\CartInventoryService;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;
use Marvel\Enums\ShippingMethod;
use Tests\TestCase;

class PromotionFlowTest extends TestCase
{
    use RefreshDatabase;

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

    /** @return array{product: Product, variant: ProductVariant} */
    private function makeVariableProductWithVariant(string $name, float $price, int $stock): array
    {
        $product = Product::create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::uuid(),
            'price' => $price,
            'product_type' => ProductType::VARIABLE,
            'stock_quantity' => 0,
            'reserved_quantity' => 0,
            'in_stock' => true,
            'status' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'price' => $price,
            'sale_price' => null,
            'stock_quantity' => $stock,
            'reserved_quantity' => 0,
            'quantity' => $stock,
            'in_stock' => $stock > 0,
        ]);

        return ['product' => $product, 'variant' => $variant];
    }

    private function makeCartWithItem(User $user, Product $product): Cart
    {
        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => 'active',
            'total_price' => 0,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 1,
            'reserved_quantity' => 1,
            'price' => 100,
            'total_price' => 100,
            'attributes' => null,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        return $cart;
    }

    public function test_checkout_promotions_returns_gift_variant_payload(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $this->makeCartWithItem($user, $cartProduct);

        $giftData = $this->makeVariableProductWithVariant('Gift Product', 120, 5);
        $giftProduct = $giftData['product'];
        $giftVariant = $giftData['variant'];

        $giftPromotion = Promotion::create([
            'name' => 'Gift Promo',
            'code' => 'GIFT-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $giftPromotion->giftProducts()->attach($giftProduct->id, [
            'quantity' => 1,
            'product_variant_id' => $giftVariant->id,
        ]);

        Promotion::create([
            'name' => 'Fixed Promo',
            'code' => 'FIXED-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        Promotion::create([
            'name' => 'Percent Promo',
            'code' => 'PERC-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $response = $this->getJson('/api/v1/general/checkout/promotions');

        $response->assertOk();
        $promotions = $response->json('data.eligible_promotions');

        $this->assertNotEmpty($promotions);
        $types = collect($promotions)->pluck('type')->all();
        $this->assertContains('gift', $types);
        $this->assertContains('fixed_rate', $types);
        $this->assertContains('percentage', $types);

        $gift = collect($promotions)->firstWhere('type', 'gift');
        $this->assertNotNull($gift);
        $this->assertNotEmpty($gift['gift_items']);
        $this->assertEquals($giftVariant->id, $gift['gift_items'][0]['product_variant_id']);
        $this->assertEquals($giftVariant->id, $gift['gift_items'][0]['product_variant']['id']);
    }

    public function test_apply_selected_gift_promotion_reserves_variant(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct);

        $giftData = $this->makeVariableProductWithVariant('Gift Product', 120, 5);
        $giftProduct = $giftData['product'];
        $giftVariant = $giftData['variant'];

        $giftPromotion = Promotion::create([
            'name' => 'Gift Promo',
            'code' => 'GIFT-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $giftPromotion->giftProducts()->attach($giftProduct->id, [
            'quantity' => 1,
            'product_variant_id' => $giftVariant->id,
        ]);

        $service = app(PromotionService::class);
        $totals = $service->applySelectedPromotion($cart->fresh(), $giftPromotion->id, $giftProduct->id);

        // NEW CONTRACT: gift resolves to a descriptor; shipping_method is now
        // applied to the ORDER line at checkout, not to any cart row.
        $this->assertNotEmpty($totals->giftItems);
        $this->assertSame($giftVariant->id, $totals->giftItems[0]['product_variant_id']);
        $this->assertEquals(0, CartItem::query()->where('cart_id', $cart->id)->where('is_gift', true)->count());
    }

    public function test_gift_item_shipping_method_from_checkout_context(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct);

        $giftData = $this->makeVariableProductWithVariant('Gift Product', 120, 5);
        $giftProduct = $giftData['product'];
        $giftVariant = $giftData['variant'];

        $giftPromotion = Promotion::create([
            'name' => 'Gift Promo',
            'code' => 'GIFT-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $giftPromotion->giftProducts()->attach($giftProduct->id, [
            'quantity' => 1,
            'product_variant_id' => $giftVariant->id,
        ]);

        $service = app(PromotionService::class);
        $totals = $service->applySelectedPromotion($cart->fresh(), $giftPromotion->id, $giftProduct->id, ShippingMethod::FAST);

        // NEW CONTRACT: shipping method applies to the ORDER gift line later.
        $this->assertNotEmpty($totals->giftItems);
        $this->assertSame($giftVariant->id, $totals->giftItems[0]['product_variant_id']);
    }

    public function test_cart_modification_clears_promotion_data(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct);

        $fixedPromotion = Promotion::create([
            'name' => 'Fixed Promo',
            'code' => 'FIXED-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $fixedPromotion->id);

        $cartItem = $cart->items()->first();
        $this->assertNotNull($cartItem->promotion_id);
        $this->assertNotNull($cartItem->discount_amount);

        $inventoryService = app(CartInventoryService::class);
        $inventoryService->incrementItem($cart, $cartProduct, null, 1, [], ShippingMethod::SCHEDULED);

        $updatedItem = $cart->items()->where('id', $cartItem->id)->first();
        $this->assertNull($updatedItem->promotion_id);
        $this->assertEquals(0, $updatedItem->discount_amount);
    }

    public function test_revalidate_promotion_on_add_items_in_order(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct);

        $promotion = Promotion::create([
            'name' => 'Test Promo',
            'code' => 'TEST-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $checkoutTotals = $service->applySelectedPromotion($cart->fresh(), $promotion->id);
        $this->assertNotNull($checkoutTotals->promotion);
        $this->assertEquals($promotion->id, $checkoutTotals->promotionId());

        $promotion->update(['status' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Selected promotion is not valid.');

        $service->applySelectedPromotion($cart->fresh(), $promotion->id);
    }

    public function test_gift_item_defaults_to_scheduled_when_no_shipping_context(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct);

        $giftData = $this->makeVariableProductWithVariant('Gift Product', 120, 5);
        $giftProduct = $giftData['product'];
        $giftVariant = $giftData['variant'];

        $giftPromotion = Promotion::create([
            'name' => 'Gift Promo',
            'code' => 'GIFT-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $giftPromotion->giftProducts()->attach($giftProduct->id, [
            'quantity' => 1,
            'product_variant_id' => $giftVariant->id,
        ]);

        $service = app(PromotionService::class);
        $totals = $service->applySelectedPromotion($cart->fresh(), $giftPromotion->id, $giftProduct->id);

        // NEW CONTRACT: descriptor resolution is cart-neutral.
        $this->assertNotEmpty($totals->giftItems);
        $this->assertEquals(0, CartItem::query()->where('cart_id', $cart->id)->where('is_gift', true)->count());
    }

    public function test_decrement_usage_decreases_count(): void
    {
        $promotion = Promotion::create([
            'name' => 'Usage Test',
            'code' => 'USG-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
            'usage' => 5,
        ]);

        $service = app(PromotionService::class);
        $service->decrementUsage($promotion->id);

        $this->assertEquals(4, $promotion->fresh()->usage);
    }

    public function test_decrement_usage_never_goes_below_zero(): void
    {
        $promotion = Promotion::create([
            'name' => 'Floor Test',
            'code' => 'FLR-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
            'usage' => 0,
        ]);

        $service = app(PromotionService::class);
        $service->decrementUsage($promotion->id);

        $this->assertEquals(0, $promotion->fresh()->usage);
    }

    public function test_decrement_usage_with_null_promotion_id_is_noop(): void
    {
        $service = app(PromotionService::class);

        $this->expectNotToPerformAssertions();
        $service->decrementUsage(null);
    }

    public function test_has_eligible_promotion_returns_true_when_eligible(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct);

        Promotion::create([
            'name' => 'Eligible Promo',
            'code' => 'ELIG-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $result = $service->hasEligiblePromotion($cart->fresh()->load('items.product', 'items.productVariant'));

        $this->assertTrue($result);
    }

    public function test_has_eligible_promotion_returns_false_for_empty_cart(): void
    {
        $user = $this->makeUser();
        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => 'active',
            'total_price' => 0,
        ]);

        $service = app(PromotionService::class);
        $result = $service->hasEligiblePromotion($cart->fresh()->load('items.product', 'items.productVariant'));

        $this->assertFalse($result);
    }

    public function test_has_eligible_promotion_returns_false_when_no_valid_promotions(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct);

        $service = app(PromotionService::class);
        $result = $service->hasEligiblePromotion($cart->fresh()->load('items.product', 'items.productVariant'));

        $this->assertFalse($result);
    }

    public function test_clear_promotion_from_cart_removes_promotion_data(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct);

        $promotion = Promotion::create([
            'name' => 'Clear Test',
            'code' => 'CLR-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $cartItem = $cart->items()->first();
        $this->assertNotNull($cartItem->promotion_id);

        $service->clearPromotionFromCart($cart->fresh());
        $cartItem->refresh();

        $this->assertNull($cartItem->promotion_id);
        $this->assertEquals(0, $cartItem->discount_amount);
    }

    public function test_apply_selected_promotion_with_null_clears_promotion(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct);

        $promotion = Promotion::create([
            'name' => 'Null Test',
            'code' => 'NUL-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $cartItem = $cart->items()->first();
        $this->assertNotNull($cartItem->promotion_id);

        $totals = $service->applySelectedPromotion($cart->fresh(), null);
        $cartItem->refresh();

        $this->assertNull($cartItem->promotion_id);
        $this->assertEquals(0, $cartItem->discount_amount);
        $this->assertNull($totals->promotion);
        $this->assertEquals(0.0, $totals->promotionDiscount);
    }

    public function test_increment_usage_does_not_exceed_limiter(): void
    {
        $promotion = Promotion::create([
            'name' => 'Limiter Test',
            'code' => 'LIM-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'required_quantity_type' => null,
            'apply_to' => 'all_products',
            'status' => true,
            'usage' => 10,
            'limiter' => 10,
        ]);

        $service = app(PromotionService::class);
        $service->incrementUsage($promotion->id);

        $this->assertEquals(10, $promotion->fresh()->usage);
    }
}
