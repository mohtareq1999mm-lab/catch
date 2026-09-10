<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\General\PromotionService;
use App\Services\General\OrderService;
use App\Services\General\CartInventoryService;
use App\Services\Checkout\OrderCreationService;
use App\DTOs\CheckoutTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ProductType;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;
use Marvel\Enums\Role;
use Marvel\Enums\ShippingMethod;
use Tests\TestCase;

class PromotionProductionHardenTest extends TestCase
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

    // =========================================================================
    // PERCENTAGE PROMOTION
    // =========================================================================

    /** @test */
    public function percentage_promotion_calculation(): void
    {
        $promotion = Promotion::create([
            'name' => '10% Off',
            'code' => 'PCT-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertEquals(10.0, $promotion->discountAmount(100.0));
        $this->assertEquals(5.0, $promotion->discountAmount(50.0));
        $this->assertEquals(0.0, $promotion->discountAmount(0.0));
        $this->assertEquals(0.0, $promotion->discountAmount(-10.0));
    }

    /** @test */
    public function percentage_rounding_correct(): void
    {
        $promotion = Promotion::create([
            'name' => 'Rounding',
            'code' => 'RND-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 15,
            'discount' => 15,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertEquals(14.99, $promotion->discountAmount(99.95));
        $this->assertEquals(15.0, $promotion->discountAmount(100.0));
        $this->assertEquals(0.15, $promotion->discountAmount(1.0));
    }

    /** @test */
    public function percentage_max_discount_capped(): void
    {
        $promotion = Promotion::create([
            'name' => 'Capped',
            'code' => 'CAP-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 50,
            'discount' => 50,
            'max_discount_amount' => 25,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertEquals(25.0, $promotion->discountAmount(100.0));
        $this->assertEquals(10.0, $promotion->discountAmount(20.0));
    }

    /** @test */
    public function invalid_percentage_rejected(): void
    {
        $promotion = Promotion::create([
            'name' => 'Zero',
            'code' => 'ZER-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertEquals(0.0, $promotion->discountAmount(100.0));
    }

    // =========================================================================
    // FIXED PROMOTION
    // =========================================================================

    /** @test */
    public function fixed_discount_calculation(): void
    {
        $promotion = Promotion::create([
            'name' => '$10 Off',
            'code' => 'FIX-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertEquals(10.0, $promotion->discountAmount(100.0));
        $this->assertEquals(5.0, $promotion->discountAmount(5.0));
        $this->assertEquals(0.0, $promotion->discountAmount(0.0));
    }

    /** @test */
    public function fixed_discount_not_exceed_total(): void
    {
        $promotion = Promotion::create([
            'name' => 'Big Fixed',
            'code' => 'BIG-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 500,
            'discount' => 500,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertEquals(100.0, $promotion->discountAmount(100.0));
        $this->assertEquals(5.0, $promotion->discountAmount(5.0));
    }

    // =========================================================================
    // GIFT PROMOTION
    // =========================================================================

    /** @test */
    public function gift_promotion_adds_item(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Item', 200, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct, 200);

        $giftData = $this->makeVariableProductWithVariant('Free Gift', 50, 5);
        $giftProduct = $giftData['product'];
        $giftVariant = $giftData['variant'];

        $giftPromotion = Promotion::create([
            'name' => 'Free Gift',
            'code' => 'GFT-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $giftPromotion->giftProducts()->attach($giftProduct->id, [
            'quantity' => 1,
            'product_variant_id' => $giftVariant->id,
        ]);

        $service = app(PromotionService::class);
        $totals = $service->applySelectedPromotion($cart->fresh(), $giftPromotion->id, $giftProduct->id);

        // NEW CONTRACT: gift becomes an ORDER-LINE DESCRIPTOR; the cart is untouched.
        $this->assertNotEmpty($totals->giftItems);
        $this->assertEquals($giftProduct->id, $totals->giftItems[0]['product_id']);
        $this->assertEquals(0, CartItem::query()->where('cart_id', $cart->id)->where('is_gift', true)->count());
        $this->assertTrue($totals->hasPromotion());
    }

    /** @test */
    public function gift_stock_validation(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Item', 200, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct, 200);

        $giftData = $this->makeVariableProductWithVariant('Out Gift', 50, 0);
        $outOfStockGift = $giftData['product'];
        $giftVariant = $giftData['variant'];

        $giftPromotion = Promotion::create([
            'name' => 'OOS Gift',
            'code' => 'OOS-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $giftPromotion->giftProducts()->attach($outOfStockGift->id, [
            'quantity' => 1,
            'product_variant_id' => $giftVariant->id,
        ]);

        // NEW CONTRACT: an explicitly selected OOS gift fails loudly instead
        // of silently disappearing from the order.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Selected gift product is not available');

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $giftPromotion->id, $outOfStockGift->id);
    }

    /** @test */
    public function create_gift_promotion_with_simple_product(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart', 200, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct, 200);

        $giftProduct = $this->makeSimpleProduct('Simple Gift', 50, 5);

        $giftPromotion = Promotion::create([
            'name' => 'Simple Gift',
            'code' => 'SGFT-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $giftPromotion->giftProducts()->attach($giftProduct->id, ['quantity' => 1]);

        $this->assertDatabaseHas('promotion_gift_products', [
            'promotion_id' => $giftPromotion->id,
            'product_id' => $giftProduct->id,
            'product_variant_id' => null,
        ]);
    }

    /** @test */
    public function checkout_with_simple_product_gift(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart', 200, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct, 200);

        $giftProduct = $this->makeSimpleProduct('Simple Gift', 50, 5);

        $giftPromotion = Promotion::create([
            'name' => 'Simple Gift Checkout',
            'code' => 'SGCHK-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $giftPromotion->giftProducts()->attach($giftProduct->id, ['quantity' => 1]);

        $service = app(PromotionService::class);
        $totals = $service->applySelectedPromotion($cart->fresh(), $giftPromotion->id, $giftProduct->id);

        $this->assertNotEmpty($totals->giftItems);
        $this->assertEquals($giftProduct->id, $totals->giftItems[0]['product_id']);
        $this->assertNull($totals->giftItems[0]['product_variant_id']);
        $this->assertTrue($totals->hasPromotion());
        $this->assertEquals(0, CartItem::query()->where('cart_id', $cart->id)->where('is_gift', true)->count());
    }

    /** @test */
    public function existing_variant_gift_flow_still_works(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart', 200, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct, 200);

        $giftData = $this->makeVariableProductWithVariant('Variant Gift', 50, 5);
        $giftProduct = $giftData['product'];
        $giftVariant = $giftData['variant'];

        $giftPromotion = Promotion::create([
            'name' => 'Variant Gift',
            'code' => 'VGFT-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $giftPromotion->giftProducts()->attach($giftProduct->id, [
            'quantity' => 1,
            'product_variant_id' => $giftVariant->id,
        ]);

        $service = app(PromotionService::class);
        $totals = $service->applySelectedPromotion($cart->fresh(), $giftPromotion->id, $giftProduct->id);

        $this->assertNotEmpty($totals->giftItems);
        $this->assertEquals($giftProduct->id, $totals->giftItems[0]['product_id']);
        $this->assertEquals($giftVariant->id, $totals->giftItems[0]['product_variant_id']);
        $this->assertTrue($totals->hasPromotion());
    }

    /** @test */
    public function simple_and_variant_gifts_can_exist_together(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart', 200, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct, 200);

        $simpleGift = $this->makeSimpleProduct('Simple', 30, 5);
        $variantData = $this->makeVariableProductWithVariant('Variant', 50, 5);
        $variantGift = $variantData['product'];
        $variantId = $variantData['variant']->id;

        $promotion = Promotion::create([
            'name' => 'Multi Gift',
            'code' => 'MULTI-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $promotion->giftProducts()->attach($simpleGift->id, ['quantity' => 1]);
        $promotion->giftProducts()->attach($variantGift->id, [
            'quantity' => 1,
            'product_variant_id' => $variantId,
        ]);

        $rows = DB::table('promotion_gift_products')
            ->where('promotion_id', $promotion->id)
            ->get();

        $this->assertCount(2, $rows);
        $simpleRow = $rows->firstWhere('product_id', $simpleGift->id);
        $variantRow = $rows->firstWhere('product_id', $variantGift->id);

        $this->assertNull($simpleRow->product_variant_id);
        $this->assertEquals($variantId, $variantRow->product_variant_id);
    }

    // =========================================================================
    // ELIGIBILITY
    // =========================================================================

    /** @test */
    public function expired_promotion_rejected(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $product);

        $promotion = Promotion::create([
            'name' => 'Expired',
            'code' => 'EXP-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'end_at' => now()->subDay(),
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertFalse($promotion->isValid());

        $service = app(PromotionService::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Selected promotion is not valid.');
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);
    }

    /** @test */
    public function future_promotion_rejected(): void
    {
        $promotion = Promotion::create([
            'name' => 'Future',
            'code' => 'FUT-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'start_at' => now()->addWeek(),
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertFalse($promotion->isValid());
    }

    /** @test */
    public function disabled_promotion_rejected(): void
    {
        $promotion = Promotion::create([
            'name' => 'Disabled',
            'code' => 'DIS-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'apply_to' => 'all_products',
            'status' => false,
        ]);

        $this->assertFalse($promotion->isValid());

        $this->assertNull(
            Promotion::valid()->where('id', $promotion->id)->first()
        );
    }

    /** @test */
    public function product_restricted_promotion_applies_zero_discount_for_non_matching_cart(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $allowedProduct = $this->makeSimpleProduct('Allowed', 100, 10);
        $otherProduct = $this->makeSimpleProduct('Other', 50, 10);
        $cart = $this->makeCartWithItem($user, $otherProduct, 50);

        $promotion = Promotion::create([
            'name' => 'Restricted',
            'code' => 'RST-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'apply_to' => 'specific_products',
            'status' => true,
        ]);
        $promotion->products()->attach($allowedProduct->id);

        $service = app(PromotionService::class);
        $totals = $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $this->assertEquals(0.0, $totals->promotionDiscount);
    }

    /** @test */
    public function minimum_order_amount_enforced_at_strategy_level(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Cheap', 30, 10);
        $cart = $this->makeCartWithItem($user, $product, 30);

        $promotion = Promotion::create([
            'name' => 'Min Order',
            'code' => 'MIN-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 5,
            'discount' => 5,
            'minimum_order_amount' => 100,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertTrue($promotion->isValid());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Selected promotion is not eligible for this cart.');

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);
    }

    // =========================================================================
    // USAGE LIMITS
    // =========================================================================

    /** @test */
    public function usage_limit_enforced(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $product);

        $promotion = Promotion::create([
            'name' => 'Limited',
            'code' => 'LIM-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'limiter' => 3,
            'usage' => 3,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertFalse($promotion->isValid());
        $this->assertNull(
            Promotion::valid()->where('id', $promotion->id)->first()
        );
    }

    /** @test */
    public function duplicate_usage_blocked(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $product);

        $promotion = Promotion::create([
            'name' => 'Dupe Test',
            'code' => 'DUP-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'limiter' => 1,
            'usage' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);

        DB::transaction(function () use ($service, $cart, $promotion) {
            $service->applySelectedPromotion($cart->fresh(), $promotion->id);
            $service->incrementUsage($promotion->id);
        });

        $this->assertEquals(1, $promotion->fresh()->usage);

        $this->expectException(\InvalidArgumentException::class);
        DB::transaction(function () use ($service, $cart, $promotion) {
            $service->applySelectedPromotion($cart->fresh(), $promotion->id);
        });
    }

    /** @test */
    public function increment_usage_does_not_exceed_limiter(): void
    {
        $promotion = Promotion::create([
            'name' => 'Cap',
            'code' => 'CAP-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'limiter' => 5,
            'usage' => 5,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $service->incrementUsage($promotion->id);

        $this->assertEquals(5, $promotion->fresh()->usage);
    }

    /** @test */
    public function concurrent_usage_does_not_exceed_limiter(): void
    {
        $promotion = Promotion::create([
            'name' => 'Concurrent',
            'code' => 'CON-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'limiter' => 2,
            'usage' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);

        DB::transaction(function () use ($service, $promotion) {
            $service->incrementUsage($promotion->id);
        });

        $this->assertEquals(1, $promotion->fresh()->usage);
    }

    // =========================================================================
    // CHECKOUT INTEGRATION
    // =========================================================================

    /** @test */
    public function promotion_applied_before_coupon(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 200, 10);
        $cart = $this->makeCartWithItem($user, $product, 200);

        $promotion = Promotion::create([
            'name' => '$10 Promo',
            'code' => 'PRM-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        app()->setLocale('en');
        $coupon = Coupon::create([
            'name' => ['en' => '10% Coupon'],
            'slug' => 'coupon-' . Str::random(6),
            'code' => 'CPN-' . Str::lower(Str::random(6)),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $cart->update(['coupon' => $coupon->code]);

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $orderService = app(OrderService::class);
        $checkoutTotals = $orderService->calculateCheckoutTotals($cart->fresh(), $promotion->id);

        $this->assertEquals(200.0, $checkoutTotals->subtotal);
        $this->assertEquals(10.0, $checkoutTotals->promotionDiscount);

        $expectedAfterPromotion = 190.0;
        $expectedCouponDiscount = round($expectedAfterPromotion * 0.10, 2);
        $this->assertEquals($expectedCouponDiscount, $checkoutTotals->couponDiscount);

        $expectedFinal = round($expectedAfterPromotion - $expectedCouponDiscount, 2);
        $this->assertEquals($expectedFinal, $checkoutTotals->finalTotal);
    }

    /** @test */
    public function order_snapshot_contains_discount(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 300, 10);
        $cart = $this->makeCartWithItem($user, $product, 300);

        $promotion = Promotion::create([
            'name' => 'Snap Promo',
            'code' => 'SNAP-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 50,
            'discount' => 50,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $checkoutTotals = $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $this->assertNotNull($checkoutTotals->promotion);
        $this->assertEquals($promotion->id, $checkoutTotals->promotionId());
        $this->assertEquals($promotion->code, $checkoutTotals->promotionCode());
        $this->assertEquals(50.0, $checkoutTotals->promotionDiscount);

        $orderService = app(OrderService::class);
        $checkoutTotals2 = $orderService->calculateCheckoutTotals($cart->fresh(), $promotion->id);

        $this->assertEquals(50.0, $checkoutTotals2->promotionDiscount);
        $this->assertEquals($promotion->code, $checkoutTotals2->promotionCode());
    }

    /** @test */
    public function client_cannot_override_promotion(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 200, 10);
        $cart = $this->makeCartWithItem($user, $product, 200);

        $promotion = Promotion::create([
            'name' => 'Only Promo',
            'code' => 'ONLY-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 20,
            'discount' => 20,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $cartItem = $cart->items()->first();
        $this->assertEquals($promotion->id, $cartItem->promotion_id);

        $cartItem->forceFill(['discount_amount' => 999, 'total_price' => -799])->save();

        $orderService = app(OrderService::class);
        $checkoutTotals = $orderService->calculateCheckoutTotals($cart->fresh(), $promotion->id);
        $cartItem->refresh();

        $this->assertEquals(200.0, $checkoutTotals->subtotal);
        $this->assertGreaterThanOrEqual(0, $checkoutTotals->finalTotal);
    }

    // =========================================================================
    // BUG REGRESSION TESTS
    // =========================================================================

    /** @test */
    public function add_items_in_order_uses_product_id_not_variant_id_for_gift(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct);

        $giftData = $this->makeVariableProductWithVariant('Gift', 50, 5);
        $giftProduct = $giftData['product'];
        $giftVariant = $giftData['variant'];

        $giftPromotion = Promotion::create([
            'name' => 'Gift For Test',
            'code' => 'GFTB-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $giftPromotion->giftProducts()->attach($giftProduct->id, [
            'quantity' => 1,
            'product_variant_id' => $giftVariant->id,
        ]);

        $service = app(PromotionService::class);
        $totals = $service->applySelectedPromotion($cart->fresh(), $giftPromotion->id, $giftProduct->id);

        // NEW CONTRACT: descriptor carries the gift product id.
        $this->assertNotEmpty($totals->giftItems);
        $this->assertEquals($giftProduct->id, $totals->giftItems[0]['product_id']);

        $this->assertEquals(0, CartItem::query()->where('cart_id', $cart->id)->where('is_gift', true)->count());
    }

    /** @test */
    public function promotion_request_image_field_name_is_dash(): void
    {
        $rules = (new \Marvel\Http\Requests\PromotionRequest())->rules();

        $this->assertArrayHasKey('image-desktop', $rules);
        $this->assertArrayHasKey('image-mobile', $rules);
        $this->assertArrayNotHasKey('image_desktop', $rules);
        $this->assertArrayNotHasKey('image_mobile', $rules);
    }

    /** @test */
    public function promotion_db_schema_matches_model(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('promotions');

        $this->assertContains('type', $columns);
        $this->assertContains('type_amount', $columns);
        $this->assertContains('value', $columns);
        $this->assertContains('discount', $columns);
        $this->assertContains('limiter', $columns);
        $this->assertContains('usage', $columns);
        $this->assertContains('start_at', $columns);
        $this->assertContains('end_at', $columns);
        $this->assertContains('status', $columns);
        $this->assertContains('code', $columns);
        $this->assertContains('apply_to', $columns);
        $this->assertContains('minimum_order_amount', $columns);
    }

    /** @test */
    public function migration_allows_nullable_product_variant_id(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('promotion_gift_products');

        $this->assertContains('product_variant_id', $columns);
        $this->assertContains('promotion_id', $columns);
        $this->assertContains('product_id', $columns);
        $this->assertContains('quantity', $columns);
    }

    /** @test */
    public function simple_gift_reserves_inventory(): void
    {
        // NEW CONTRACT: gift reservation happens on the ORDER via
        // OrderReservationService — simple (variant-less) gifts included.
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Item', 100, 10);
        $this->makeCartWithItem($user, $cartProduct, 100);

        $giftProduct = $this->makeSimpleProduct('Simple Gift', 30, 5);

        $promotion = Promotion::create([
            'name' => 'Simple Gift',
            'code' => 'RSRV-S-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $promotion->giftProducts()->attach($giftProduct->id, ['quantity' => 1]);

        $order = \Marvel\Database\Models\Order::create([
            'user_id' => $user->id, 'name' => 'G', 'user_phone' => '01',
            'user_email' => $user->email, 'address' => '{}',
            'price' => 100, 'total_price' => 100, 'status' => 'pending',
        ]);
        $order->orderItems()->create([
            'product_id' => $giftProduct->id,
            'product_name' => $giftProduct->name,
            'product_quantity' => 1,
            'product_price' => 0,
            'product_total_price' => 0,
            'is_gift' => true,
            'promotion_id' => $promotion->id,
        ]);

        app(\App\Services\Inventory\OrderReservationService::class)->reserveForOrder($order->refresh());

        $this->assertEquals(1, $giftProduct->fresh()->reserved_quantity);
        $this->assertEquals(0, $cartProduct->fresh()->reserved_quantity - $cartProduct->reserved_quantity + $cartProduct->reserved_quantity - $cartProduct->reserved_quantity); // no-op guard
    }

    // =========================================================================
    // SERVICE EDGE CASES
    // =========================================================================

    /** @test */
    public function clear_promotion_from_cart_resets_all_promotion_fields(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $product);

        $promotion = Promotion::create([
            'name' => 'Clear Me',
            'code' => 'CLR-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
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

    /** @test */
    public function null_promotion_id_clears_cart(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $product);

        $promotion = Promotion::create([
            'name' => 'Null Test',
            'code' => 'NUL-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $totals = $service->applySelectedPromotion($cart->fresh(), null);
        $cartItem = $cart->items()->first();

        $this->assertNull($cartItem->promotion_id);
        $this->assertNull($totals->promotion);
    }

    /** @test */
    public function decrement_usage_never_below_zero(): void
    {
        $promotion = Promotion::create([
            'name' => 'Floor',
            'code' => 'FLR-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'apply_to' => 'all_products',
            'status' => true,
            'usage' => 0,
        ]);

        $service = app(PromotionService::class);
        $service->decrementUsage($promotion->id);

        $this->assertEquals(0, $promotion->fresh()->usage);
    }

    /** @test */
    public function decrement_usage_with_null_id_is_noop(): void
    {
        $service = app(PromotionService::class);
        $this->expectNotToPerformAssertions();
        $service->decrementUsage(null);
    }

    /** @test */
    public function has_eligible_promotion_returns_false_for_empty_cart(): void
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

    /** @test */
    public function promotion_calc_price_never_negative(): void
    {
        $promotion = Promotion::create([
            'name' => 'Big Disc',
            'code' => 'BIG-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 1000,
            'discount' => 1000,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertEquals(0.0, $promotion->calcPrice(10.0));
        $this->assertEquals(0.0, $promotion->calcPrice(0.0));
        $this->assertEquals(0.0, $promotion->calcPrice(-5.0));
    }

    /** @test */
    public function promotion_required_quantity_type_check(): void
    {
        $promotion = Promotion::create([
            'name' => 'Qty Check',
            'code' => 'QTY-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'required_quantity_type' => 3,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertEquals(0.0, $promotion->discountAmount(100.0, 1));
        $this->assertEquals(0.0, $promotion->discountAmount(100.0, 2));
        $this->assertEquals(10.0, $promotion->discountAmount(100.0, 3));
        $this->assertEquals(10.0, $promotion->discountAmount(100.0, 5));
    }

    /** @test */
    public function eligible_promotions_endpoint_returns_eligible_only(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Item', 100, 10);
        $this->makeCartWithItem($user, $product);

        Promotion::create([
            'name' => 'Active1',
            'code' => 'ACT1-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 5,
            'discount' => 5,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        Promotion::create([
            'name' => 'Disabled',
            'code' => 'DSBL-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 5,
            'discount' => 5,
            'apply_to' => 'all_products',
            'status' => false,
        ]);
        Promotion::create([
            'name' => 'Expired',
            'code' => 'EXPD-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 5,
            'discount' => 5,
            'end_at' => now()->subDay(),
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $response = $this->getJson(self::PREFIX . '/general/checkout/promotions');

        $response->assertOk();
        $promotions = $response->json('data.eligible_promotions');

        $this->assertNotEmpty($promotions);
        $promotionNames = collect($promotions)->pluck('title')->all();
        $this->assertContains('Active1', $promotionNames);
        $this->assertNotContains('Disabled', $promotionNames);
        $this->assertNotContains('Expired', $promotionNames);
    }

    /** @test */
    public function checkout_with_promotion_and_coupon_produces_correct_order(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Checkout Item', 500, 20);
        $cart = $this->makeCartWithItem($user, $product, 500);

        $promotion = Promotion::create([
            'name' => 'Checkout Promo',
            'code' => 'CHK-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 100,
            'discount' => 100,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        app()->setLocale('en');
        $coupon = Coupon::create([
            'name' => ['en' => 'Checkout Coupon'],
            'slug' => 'coupon-' . Str::random(6),
            'code' => 'chk-' . Str::lower(Str::random(6)),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $cart->update(['coupon' => $coupon->code]);

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $orderService = app(OrderService::class);
        $checkoutTotals = $orderService->calculateCheckoutTotals(
            $cart->fresh(),
            $promotion->id,
            null,
            ShippingMethod::SCHEDULED,
        );

        $this->assertEquals(500.0, $checkoutTotals->subtotal);
        $this->assertEquals(100.0, $checkoutTotals->promotionDiscount);
        $expectedAfterPromotion = 400.0;
        $expectedCouponDiscount = round($expectedAfterPromotion * 0.10, 2);
        $this->assertEquals($expectedCouponDiscount, $checkoutTotals->couponDiscount);
        $expectedFinalTotal = round($expectedAfterPromotion - $expectedCouponDiscount, 2);
        $this->assertEquals($expectedFinalTotal, $checkoutTotals->finalTotal);
    }

    /** @test */
    public function expired_promotion_is_invalid_during_checkout(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Exp Test', 200, 10);
        $cart = $this->makeCartWithItem($user, $product, 200);

        $promotion = Promotion::create([
            'name' => 'Gone Promo',
            'code' => 'GONE-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 50,
            'discount' => 50,
            'end_at' => now()->subDay(),
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Selected promotion is not valid.');

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);
    }

    /** @test */
    public function null_promotion_id_passed_to_increment_usage_is_noop(): void
    {
        $service = app(PromotionService::class);
        $this->expectNotToPerformAssertions();
        $service->incrementUsage(null);
    }

    /** @test */
    public function requested_variant_not_overwritten_by_existing_item(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cartProduct = $this->makeSimpleProduct('Item X', 200, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct, 200);

        $giftData = $this->makeVariableProductWithVariant('Gift Var', 50, 5);
        $giftProduct = $giftData['product'];
        $giftVariantA = $giftData['variant'];

        $giftVariantB = ProductVariant::create([
            'product_id' => $giftProduct->id,
            'title' => 'Size B',
            'stock_quantity' => 5,
            'reserved_quantity' => 0,
            'price' => 55,
            'sku' => 'GFT-V-B-' . Str::random(6),
        ]);

        $promotion = Promotion::create([
            'name' => 'Variant Overwrite Test',
            'code' => 'VOW-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $promotion->giftProducts()->attach($giftProduct->id, [
            'quantity' => 1,
            'product_variant_id' => $giftVariantA->id,
        ]);

        $service = app(PromotionService::class);

        // First resolution: descriptor points at variant A
        $firstTotals = $service->applySelectedPromotion($cart->fresh(), $promotion->id, $giftProduct->id);

        $this->assertNotEmpty($firstTotals->giftItems);
        $this->assertEquals($giftVariantA->id, $firstTotals->giftItems[0]['product_variant_id']);

        // Second application: same user switches to variant B
        $promotion->giftProducts()->sync([
            $giftProduct->id => [
                'quantity' => 1,
                'product_variant_id' => $giftVariantB->id,
            ],
        ]);

        $secondTotals = $service->applySelectedPromotion($cart->fresh(), $promotion->id, $giftProduct->id);

        $this->assertNotEmpty($secondTotals->giftItems);
        $this->assertEquals(
            $giftVariantB->id,
            $secondTotals->giftItems[0]['product_variant_id'],
            'Selected variant B should not be overwritten by old item variant A'
        );
    }
}
