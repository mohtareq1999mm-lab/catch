<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Services\General\OrderService;
use App\Services\General\PromotionService;
use App\Services\Inventory\OrderReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\User;
use Marvel\Enums\DiscountType;
use Marvel\Enums\FlashSaleType;
use Marvel\Enums\ProductType;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;
use Marvel\Enums\ShippingMethod;
use Tests\TestCase;

/**
 * PHASE 04 HARDENING — residual findings F-03 / F-04 / F-05 / F-06.
 *
 * Pins the approved contracts without redesigning anything:
 *  - F-03 explicit stacking precedence (Flash Sale → Promotion → Coupon)
 *  - F-04 gift-specific reservation-failure mapping
 *  - F-05 decrement floor + consumption idempotency + gift race
 *  - F-06 limiter-fill observability (behavior-preserving, Option A)
 *
 * Cancellation semantics (Rule 17 / ORD-1) are NOT duplicated here: they are
 * already pinned by ReaperAuthorityTest (unpaid decrement + never-paid
 * expiry skip) and BusinessRulesImplementationTest
 * (test_promotion_not_decremented_on_paid_order_cancellation).
 */
class PromotionResidualHardeningTest extends TestCase
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

    private function makeCartWithItem(User $user, Product $product, float $price, int $quantity = 1): Cart
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

    private function makeFixedPromotion(float $amount): Promotion
    {
        return Promotion::create([
            'name' => 'Fixed Promo',
            'code' => 'FIX-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => $amount,
            'discount' => $amount,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
    }

    private function makePercentageCoupon(float $percent): Coupon
    {
        app()->setLocale('en');

        return Coupon::create([
            'name' => ['en' => 'Stack Coupon'],
            'slug' => 'coupon-' . Str::random(6),
            'code' => 'STK-' . Str::lower(Str::random(6)),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => $percent,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
    }

    private function makeOrderFor(User $user, ?int $promotionId): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'name' => 'Promo Order',
            'user_phone' => '01000000001',
            'user_email' => $user->email,
            'address' => json_encode(['address' => '123 Street']),
            'shipping_method' => 'SCHEDULED',
            'price' => 100.00,
            'total_price' => 100.00,
            'status' => 'pending',
            'payment_method' => 'online',
            'promotion_id' => $promotionId,
        ]);
    }

    // =========================================================================
    // F-03 — STACKING PRECEDENCE: Flash Sale → Promotion → Coupon
    // =========================================================================

    /** @test */
    public function triple_stack_flash_sale_then_promotion_then_coupon_pins_final_amount(): void
    {
        $user = $this->makeUser();
        $product = $this->makeSimpleProduct('Stack Item', 200, 10);

        // A real 20% flash sale is attached to the product; the cart line
        // carries its post-flash price (160) exactly as refreshCartItemPrices
        // embeds it before totals run. The flash engine itself is out of
        // scope — this pins the composition order, not flash math.
        $flashSale = FlashSale::create([
            'title' => 'Stack Flash 20%',
            'slug' => 'stack-fs-' . Str::random(6),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 20,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);
        $flashSale->products()->attach($product->id);

        $cart = $this->makeCartWithItem($user, $product, 160.00);

        $promotion = $this->makeFixedPromotion(10);
        $coupon = $this->makePercentageCoupon(10);
        $cart->update(['coupon' => $coupon->code]);

        $totals = app(OrderService::class)->calculateCheckoutTotals($cart->fresh(), $promotion->id);

        // Flash base 160 → fixed promotion 10 → remainder 150 → 10% coupon 15.
        $this->assertEquals(160.00, $totals->subtotal);
        $this->assertEquals(10.00, $totals->promotionDiscount);
        $this->assertEquals(15.00, $totals->couponDiscount);
        $this->assertEquals(135.00, $totals->finalTotal);
    }

    /** @test */
    public function gift_promotion_does_not_shrink_the_coupon_base(): void
    {
        $user = $this->makeUser();
        $cartProduct = $this->makeSimpleProduct('Cart Item', 200, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct, 200);

        $giftProduct = $this->makeSimpleProduct('Free Gift', 50, 5);
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
        $giftPromotion->giftProducts()->attach($giftProduct->id, ['quantity' => 1]);

        $coupon = $this->makePercentageCoupon(10);
        $cart->update(['coupon' => $coupon->code]);

        $totals = app(OrderService::class)->calculateCheckoutTotals($cart->fresh(), $giftPromotion->id, $giftProduct->id);

        // Gift discount is 0 with an order-line descriptor; the 10% coupon
        // applies to the full 200 remainder.
        $this->assertNotEmpty($totals->giftItems);
        $this->assertEquals($giftProduct->id, $totals->giftItems[0]['product_id']);
        $this->assertEquals(0.00, $totals->promotionDiscount);
        $this->assertEquals(20.00, $totals->couponDiscount);
        $this->assertEquals(180.00, $totals->finalTotal);
    }

    // =========================================================================
    // F-05 — DECREMENT FLOOR
    // =========================================================================

    /** @test */
    public function decrement_usage_at_zero_never_goes_negative(): void
    {
        $promotion = Promotion::create([
            'name' => 'Floor Promo',
            'code' => 'FLR-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 5,
            'discount' => 5,
            'usage' => 0,
            'limiter' => 10,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $service->decrementUsage($promotion->id);
        $this->assertEquals(0, (int) $promotion->fresh()->usage);

        $promotion->forceFill(['usage' => 1])->save();
        $service->decrementUsage($promotion->id);
        $this->assertEquals(0, (int) $promotion->fresh()->usage);

        $service->decrementUsage($promotion->id);
        $this->assertEquals(0, (int) $promotion->fresh()->usage);
    }

    // =========================================================================
    // F-05 — CONSUMPTION IDEMPOTENCY
    // =========================================================================

    /** @test */
    public function promotion_consumption_is_idempotent_across_double_completion(): void
    {
        $user = $this->makeUser();
        $promotion = Promotion::create([
            'name' => 'Idem Promo',
            'code' => 'IDM-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'usage' => 0,
            'limiter' => null,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $order = $this->makeOrderFor($user, $promotion->id);
        $this->assertFalse((bool) $order->promotion_consumed);

        $orderService = app(OrderService::class);
        $orderService->finalizePromotionUsageAfterPayment($order->fresh());
        $orderService->finalizePromotionUsageAfterPayment($order->fresh());

        $this->assertEquals(1, (int) $promotion->fresh()->usage);
        $this->assertTrue((bool) $order->fresh()->promotion_consumed);
    }

    // =========================================================================
    // F-04 + F-05 — GIFT RACE: lost stock → gift-specific failure, no commit
    // =========================================================================

    /** @test */
    public function gift_stock_lost_before_reservation_fails_with_gift_error_and_nothing_commits(): void
    {
        $user = $this->makeUser();
        $cartProduct = $this->makeSimpleProduct('Cart Item', 100, 10);
        $cart = $this->makeCartWithItem($user, $cartProduct, 100);

        $giftProduct = $this->makeSimpleProduct('Raced Gift', 50, 1);
        $giftPromotion = Promotion::create([
            'name' => 'Raced Gift Promo',
            'code' => 'RGP-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
        ]);
        $giftPromotion->giftProducts()->attach($giftProduct->id, ['quantity' => 1]);

        $promotionService = app(PromotionService::class);
        $totals = $promotionService->applySelectedPromotion($cart->fresh(), $giftPromotion->id, $giftProduct->id);
        $this->assertNotEmpty($totals->giftItems);

        // Gift present while available: the mapping stays silent.
        $promotionService->throwIfGiftUnavailable($totals->giftItems);
        $this->assertTrue(true);

        // A rival order commits the last gift unit before our reservation.
        $giftProduct->forceFill(['stock_quantity' => 0, 'in_stock' => false])->save();

        $order = $this->makeOrderFor($user, $giftPromotion->id);
        $giftLine = [
            'product_id' => $giftProduct->id,
            'product_variant_id' => null,
            'product_name' => $giftProduct->name,
            'product_quantity' => 1,
            'product_price' => 0,
            'product_total_price' => 0,
            'product_sku' => $giftProduct->sku,
            'promotion_discount_amount' => 0,
            'attributes' => null,
            'is_gift' => true,
            'promotion_id' => $giftPromotion->id,
        ];
        if (Schema::hasColumn('order_products', 'item_type')) {
            $giftLine['item_type'] = $giftProduct->item_type ?? \Marvel\Enums\ItemType::PHYSICAL;
        }
        $order->orderItems()->create($giftLine);

        // The authority still fails closed with the generic stock error…
        try {
            app(OrderReservationService::class)->reserveForOrder($order->fresh());
            $this->fail('Reservation must fail when the gift unit is gone.');
        } catch (InsufficientStockException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // …nothing is partially committed…
        $this->assertSame(Order::INVENTORY_STATE_NONE, $order->fresh()->inventory_state);
        $this->assertEquals(0, (int) $giftProduct->fresh()->reserved_quantity);

        // …and the F-04 mapping converts it to the gift-specific 422.
        try {
            $promotionService->throwIfGiftUnavailable($totals->giftItems);
            $this->fail('Lost gift stock must raise the gift-specific failure.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(
                'Selected gift product is not available for this promotion.',
                $e->getMessage()
            );
        }
    }

    // =========================================================================
    // F-06 — LIMITER-FILL OBSERVABILITY (behavior-preserving)
    // =========================================================================

    /** @test */
    public function limiter_filled_before_completion_keeps_discount_and_logs(): void
    {
        $user = $this->makeUser();
        $promotion = Promotion::create([
            'name' => 'Full Promo',
            'code' => 'FUL-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 5,
            'discount' => 5,
            'usage' => 1,
            'limiter' => 1,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $order = $this->makeOrderFor($user, $promotion->id);

        Log::shouldReceive('warning')
            ->once()
            ->with(
                'promotion.usage.limiter_blocked',
                \Mockery::on(fn ($context) => $context['promotion_id'] === $promotion->id
                    && (int) $context['order_id'] === (int) $order->id
                    && (int) $context['usage'] === 1
                    && (int) $context['limiter'] === 1)
            );

        app(OrderService::class)->finalizePromotionUsageAfterPayment($order->fresh());

        // Approved contract holds: discount kept, counter untouched, flag set.
        $this->assertEquals(1, (int) $promotion->fresh()->usage);
        $this->assertTrue((bool) $order->fresh()->promotion_consumed);
    }

    /** @test */
    public function normal_completion_increments_without_limiter_warning(): void
    {
        $user = $this->makeUser();
        $promotion = Promotion::create([
            'name' => 'Roomy Promo',
            'code' => 'RMY-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 5,
            'discount' => 5,
            'usage' => 0,
            'limiter' => 10,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $order = $this->makeOrderFor($user, $promotion->id);

        Log::shouldReceive('warning')->never();

        app(OrderService::class)->finalizePromotionUsageAfterPayment($order->fresh());

        $this->assertEquals(1, (int) $promotion->fresh()->usage);
        $this->assertTrue((bool) $order->fresh()->promotion_consumed);
    }
}
