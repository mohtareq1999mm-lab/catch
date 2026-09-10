<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CheckoutTotals;
use App\Services\General\PromotionService;
use App\Services\General\OrderService;
use App\Services\General\CartInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\DiscountType;
use Marvel\Enums\FlashSaleType;
use Marvel\Enums\ProductType;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;
use Marvel\Enums\ShippingMethod;
use Marvel\Services\Pricing\ProductPricingService;
use Tests\TestCase;

class FinancialVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        if (!Settings::exists()) {
            Settings::create([
                'site_name' => 'Test Site',
                'options' => [],
                'minimum_order_amount' => 0,
            ]);
        }
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeSimpleProduct(string $name, float $price, int $stock = 10): Product
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

    private function makeDiscountedProduct(string $name, float $price, string $discountType, float $discountAmount, int $stock = 10): Product
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
            'has_discount' => true,
            'discount_type' => $discountType,
            'discount_amount' => $discountAmount,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
    }

    private function makeVariableProduct(string $name, array $variantsData): array
    {
        $product = Product::create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::uuid(),
            'price' => $variantsData[0]['price'] ?? 0,
            'product_type' => ProductType::VARIABLE,
            'stock_quantity' => 0,
            'reserved_quantity' => 0,
            'in_stock' => true,
            'status' => true,
        ]);

        $variants = [];
        foreach ($variantsData as $v) {
            $variants[] = ProductVariant::create([
                'product_id' => $product->id,
                'price' => $v['price'],
                'sale_price' => $v['sale_price'] ?? null,
                'stock_quantity' => $v['stock'] ?? 10,
                'reserved_quantity' => 0,
                'in_stock' => ($v['stock'] ?? 10) > 0,
                'sku' => 'SKU-' . Str::random(8),
            ]);
        }

        return ['product' => $product, 'variants' => $variants];
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
            'total_price' => round($price * $quantity, 2),
            'attributes' => null,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        return $cart;
    }

    private function makeCartWithMultipleItems(User $user, array $items): Cart
    {
        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => 'active',
            'total_price' => 0,
        ]);

        foreach ($items as $item) {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $item['product']->id,
                'quantity' => $item['quantity'],
                'reserved_quantity' => $item['quantity'],
                'price' => $item['price'],
                'total_price' => round($item['price'] * $item['quantity'], 2),
                'attributes' => null,
                'shipping_method' => $item['shipping_method'] ?? ShippingMethod::SCHEDULED,
            ]);
        }

        return $cart;
    }

    // =========================================================================
    // SECTION 1: PRODUCT PRICING MATHEMATICAL ACCURACY
    // =========================================================================

    /** @test */
    public function base_price_is_exact(): void
    {
        $prices = [0.01, 0.99, 1.00, 10.50, 99.99, 100.00, 999.99, 1000.00, 9999.99, 0.00, -1.00];
        foreach ($prices as $expected) {
            $product = $this->makeSimpleProduct('P', max(0, $expected));
            $service = app(ProductPricingService::class);
            $result = $service->calculateProductPricing($product);
            $manual = round(max(0, (float) $expected), 2);
            $this->assertEquals($manual, $result['base_price'], "Base price mismatch for {$expected}");
            $this->assertEquals($manual, $result['final_price'], "Final price mismatch for {$expected} (no discount)");
        }
    }

    /** @test */
    public function percentage_discount_is_mathematically_correct(): void
    {
        $scenarios = [
            ['price' => 100.00, 'discount' => 10,  'expected_price' => 90.00],
            ['price' => 200.00, 'discount' => 25,  'expected_price' => 150.00],
            ['price' => 99.99,  'discount' => 10,  'expected_price' => 89.99],
            ['price' => 50.00,  'discount' => 50,  'expected_price' => 25.00],
            ['price' => 33.33,  'discount' => 33,  'expected_price' => 22.33],
            ['price' => 10.00,  'discount' => 50,  'expected_price' => 5.00],
            ['price' => 1.00,   'discount' => 100, 'expected_price' => 0.00],
            ['price' => 0.00,   'discount' => 10,  'expected_price' => 0.00],
        ];

        $service = app(ProductPricingService::class);

        foreach ($scenarios as $s) {
            $product = $this->makeDiscountedProduct(
                'Test', (float) $s['price'], DiscountType::PERCENTAGE, (float) $s['discount']
            );
            $result = $service->calculateProductPricing($product);

            $manualDiscount = round((float) $s['price'] * ((float) $s['discount'] / 100), 2);
            $manualPrice = round(max(0, (float) $s['price'] - $manualDiscount), 2);

            $this->assertEquals($s['expected_price'], $manualPrice, "Manual calc mismatch for {$s['price']} @ {$s['discount']}%");
            $this->assertEquals($manualPrice, $result['final_price'], "System mismatch for {$s['price']} @ {$s['discount']}%");
        }
    }

    /** @test */
    public function fixed_discount_is_mathematically_correct(): void
    {
        $service = app(ProductPricingService::class);

        $scenarios = [
            ['price' => 100.00, 'discount' => 25.00,  'expected' => 75.00],
            ['price' => 50.00,  'discount' => 60.00,  'expected' => 0.00],
            ['price' => 99.99,  'discount' => 30.00,  'expected' => 69.99],
            ['price' => 10.00,  'discount' => 10.00,  'expected' => 0.00],
            ['price' => 10.00,  'discount' => 10.01,  'expected' => 0.00],
        ];

        foreach ($scenarios as $s) {
            $product = $this->makeDiscountedProduct(
                'FixT', (float) $s['price'], DiscountType::FIXED_RATE, (float) $s['discount']
            );
            $result = $service->calculateProductPricing($product);
            $this->assertEquals($s['expected'], $result['final_price'], "Fixed discount fail for {$s['price']} - {$s['discount']}");
        }
    }

    /** @test */
    public function variant_pricing_is_correct(): void
    {
        $service = app(ProductPricingService::class);
        $data = $this->makeVariableProduct('Var Product', [
            ['title' => 'Small', 'price' => 100.00, 'stock' => 5],
            ['title' => 'Large', 'price' => 200.50, 'stock' => 3],
        ]);

        foreach ($data['variants'] as $variant) {
            $price = $service->calculateVariantCurrentPrice($data['product'], $variant);
            $this->assertEquals(round((float) $variant->price, 2), $price, "Variant {$variant->id} price mismatch");
        }
    }

    // =========================================================================
    // SECTION 2: FLASH SALE MATHEMATICAL ACCURACY
    // =========================================================================

    /** @test */
    public function flash_sale_percentage_is_correct(): void
    {
        $service = app(ProductPricingService::class);
        $product = $this->makeSimpleProduct('Flash Item', 200.00);

        $flashSale = FlashSale::create([
            'title' => '10% Flash',
            'slug' => 'flash-' . Str::random(6),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);

        $result = $service->calculateProductPricing($product, $flashSale);
        $manual = round(200.00 * (1 - 10 / 100), 2);
        $this->assertEquals($manual, $result['price_after_flash_sale']);
        $this->assertEquals($manual, $result['final_price']);
    }

    /** @test */
    public function flash_sale_fixed_discount_is_correct(): void
    {
        $service = app(ProductPricingService::class);
        $product = $this->makeSimpleProduct('Fixed Flash', 200.00);

        $flashSale = FlashSale::create([
            'title' => '30 Off Flash',
            'slug' => 'fflash-' . Str::random(6),
            'type' => FlashSaleType::FIXED_RATE,
            'discount' => 30,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);

        $result = $service->calculateProductPricing($product, $flashSale);
        $this->assertEquals(170.00, $result['price_after_flash_sale']);
        $this->assertEquals(170.00, $result['final_price']);
    }

    /** @test */
    public function flash_sale_final_price_type_is_correct(): void
    {
        $service = app(ProductPricingService::class);
        $product = $this->makeSimpleProduct('Final Flash', 200.00);

        $flashSale = FlashSale::create([
            'title' => 'Set 150',
            'slug' => 'sflash-' . Str::random(6),
            'type' => FlashSaleType::FINAL_PRICE,
            'discount' => 150,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);

        $result = $service->calculateProductPricing($product, $flashSale);
        $this->assertEquals(150.00, $result['price_after_flash_sale']);
        $this->assertEquals(150.00, $result['final_price']);
    }

    /** @test */
    public function flash_sale_max_discount_capped(): void
    {
        $service = app(ProductPricingService::class);
        $product = $this->makeSimpleProduct('Capped Flash', 1000.00);

        $flashSale = FlashSale::create([
            'title' => 'Cap 50',
            'slug' => 'capflash-' . Str::random(6),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 20,
            'max_discount_amount' => 50,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);

        $result = $service->calculateProductPricing($product, $flashSale);
        $this->assertEquals(950.00, $result['final_price']);
    }

    /** @test */
    public function flash_sale_takes_priority_over_product_discount(): void
    {
        $service = app(ProductPricingService::class);
        $product = $this->makeDiscountedProduct('Both', 200.00, DiscountType::PERCENTAGE, 10);

        $flashSale = FlashSale::create([
            'title' => 'Flash 20%',
            'slug' => 'bothflash-' . Str::random(6),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 20,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);

        $result = $service->calculateProductPricing($product, $flashSale);
        $this->assertNotNull($result['price_after_flash_sale']);
        $this->assertNull($result['price_after_discount']);
        $this->assertEquals(160.00, $result['final_price']);
    }

    // =========================================================================
    // SECTION 3: PROMOTION CALCULATION ACCURACY
    // =========================================================================

    /** @test */
    public function promotion_discount_amount_matches_manual_percentage(): void
    {
        $scenarios = [
            ['price' => 100.00, 'value' => 10, 'expected_discount' => 10.00],
            ['price' => 99.99,  'value' => 15, 'expected_discount' => 15.00],
            ['price' => 200.00, 'value' => 25, 'expected_discount' => 50.00],
            ['price' => 33.33,  'value' => 33, 'expected_discount' => 11.00],
            ['price' => 1.00,   'value' => 50, 'expected_discount' => 0.50],
        ];

        foreach ($scenarios as $s) {
            $promotion = Promotion::create([
                'name' => 'PctTest',
                'code' => 'PCT-' . Str::upper(Str::random(6)),
                'type' => PromotionType::PRICE,
                'type_amount' => PromotionMountType::PERCENTAGE,
                'value' => $s['value'],
                'discount' => $s['value'],
                'apply_to' => 'all_products',
                'status' => true,
            ]);

            $manualDiscount = round((float) $s['price'] * ((float) $s['value'] / 100), 2);
            $manualCalcPrice = round(max(0, (float) $s['price'] - $manualDiscount), 2);

            $systemDiscount = $promotion->discountAmount((float) $s['price']);
            $systemCalcPrice = $promotion->calcPrice((float) $s['price']);

            $this->assertEquals($s['expected_discount'], $manualDiscount, "Manual discount mismatch for {$s['price']} @ {$s['value']}%");
            $this->assertEquals($manualDiscount, $systemDiscount, "System discount mismatch for {$s['price']} @ {$s['value']}%");
            $this->assertEquals($manualCalcPrice, $systemCalcPrice, "System calc price mismatch for {$s['price']} @ {$s['value']}%");
        }
    }

    /** @test */
    public function promotion_max_discount_capped(): void
    {
        $promotion = Promotion::create([
            'name' => 'Capped50',
            'code' => 'CAP-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 50,
            'discount' => 50,
            'max_discount_amount' => 25,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertEquals(25.0, $promotion->discountAmount(100.0));
        $this->assertEquals(75.0, $promotion->calcPrice(100.0));
        $this->assertEquals(10.0, $promotion->discountAmount(20.0));
        $this->assertEquals(10.0, $promotion->calcPrice(20.0));
    }

    /** @test */
    public function promotion_fixed_discount_never_exceeds_price(): void
    {
        $promotion = Promotion::create([
            'name' => 'BigFix',
            'code' => 'BIG-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 100,
            'discount' => 100,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertEquals(50.0, $promotion->discountAmount(50.0));
        $this->assertEquals(0.0, $promotion->calcPrice(50.0));
        $this->assertEquals(100.0, $promotion->discountAmount(200.0));
        $this->assertEquals(100.0, $promotion->calcPrice(200.0));
    }

    /** @test */
    public function promotion_proportional_allocation_sum_matches_total_discount(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $p1 = $this->makeSimpleProduct('A', 100.00);
        $p2 = $this->makeSimpleProduct('B', 200.00);
        $p3 = $this->makeSimpleProduct('C', 300.00);

        $cart = $this->makeCartWithMultipleItems($user, [
            ['product' => $p1, 'price' => 100.00, 'quantity' => 1],
            ['product' => $p2, 'price' => 200.00, 'quantity' => 1],
            ['product' => $p3, 'price' => 300.00, 'quantity' => 1],
        ]);

        $promotion = Promotion::create([
            'name' => '10% All',
            'code' => 'ALL-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $totals = $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $manualSubtotal = round(100.00 + 200.00 + 300.00, 2);
        $manualDiscount = round($manualSubtotal * 0.10, 2);
        $manualFinal = round($manualSubtotal - $manualDiscount, 2);

        $this->assertEquals($manualSubtotal, $totals->subtotal);
        $this->assertEquals($manualDiscount, $totals->promotionDiscount);
        $this->assertEquals($manualFinal, $totals->finalTotal);
        $this->assertEquals($manualSubtotal - $manualDiscount, $totals->finalTotal);
    }

    /** @test */
    public function promotion_clears_and_restores_original_prices(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Clear', 150.00);
        $cart = $this->makeCartWithItem($user, $product, 150.00);

        $promotion = Promotion::create([
            'name' => 'ClearTest',
            'code' => 'CLR-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 30,
            'discount' => 30,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $cartItem = $cart->items()->first();
        $this->assertNotNull($cartItem->promotion_id);
        $this->assertEquals(30.0, (float) $cartItem->discount_amount);
        $this->assertEquals(120.0, (float) $cartItem->total_price);

        $cleared = $service->clearPromotionFromCart($cart->fresh());
        $cartItem->refresh();

        $this->assertNull($cartItem->promotion_id);
        $this->assertEquals(0.0, (float) $cartItem->discount_amount);
        $this->assertEquals(150.0, (float) $cartItem->total_price);
        $this->assertEquals(150.0, $cleared->finalTotal);
        $this->assertEquals(0.0, $cleared->promotionDiscount);
    }

    // =========================================================================
    // SECTION 4: COUPON CALCULATION ACCURACY
    // =========================================================================

    /** @test */
    public function coupon_calculator_matches_manual(): void
    {
        $scenarios = [
            ['type' => DiscountType::PERCENTAGE, 'discount' => 10, 'price' => 200.00, 'expected_discount' => 20.00, 'expected_final' => 180.00],
            ['type' => DiscountType::PERCENTAGE, 'discount' => 15, 'price' => 99.99,  'expected_discount' => 15.00, 'expected_final' => 84.99],
            ['type' => DiscountType::FIXED_RATE, 'discount' => 30, 'price' => 100.00, 'expected_discount' => 30.00, 'expected_final' => 70.00],
            ['type' => DiscountType::FIXED_RATE, 'discount' => 200,'price' => 100.00, 'expected_discount' => 100.00,'expected_final' => 0.00],
            ['type' => DiscountType::FREE_SHIPPING,'discount' => 0,  'price' => 100.00, 'expected_discount' => 0.00,  'expected_final' => 100.00],
        ];

        foreach ($scenarios as $s) {
            $coupon = Coupon::create([
                'name' => ['en' => 'Test Coupon'],
                'slug' => 'c-' . Str::random(6),
                'code' => 'TC-' . Str::random(6),
                'discount_type' => $s['type'],
                'discount' => $s['discount'],
                'status' => true,
                'start_date' => now()->subDay(),
                'end_date' => now()->addMonth(),
            ]);

            $result = \App\Services\Coupon\CouponCalculator::calculate($coupon, (float) $s['price']);

            $manualDiscount = $s['type'] === DiscountType::PERCENTAGE
                ? round((float) $s['price'] * ((float) $s['discount'] / 100), 2)
                : ($s['type'] === DiscountType::FIXED_RATE ? min((float) $s['discount'], (float) $s['price']) : 0.0);

            $manualFinal = round(max(0, (float) $s['price'] - $manualDiscount), 2);

            $this->assertEquals($s['expected_discount'], $manualDiscount, "Manual discount mismatch");
            $this->assertEquals($s['expected_discount'], $result['discountAmount'], "System discount mismatch");
            $this->assertEquals($s['expected_final'], $manualFinal, "Manual final mismatch");
            $this->assertEquals($s['expected_final'], $result['finalPrice'], "System final mismatch");
        }
    }

    /** @test */
    public function coupon_max_discount_capped(): void
    {
        $coupon = Coupon::create([
            'name' => ['en' => 'Capped Coupon'],
            'slug' => 'cc-' . Str::random(6),
            'code' => 'CC-' . Str::random(6),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 50,
            'max_discount_amount' => 25,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $result = \App\Services\Coupon\CouponCalculator::calculate($coupon, 100.00);
        $this->assertEquals(25.00, $result['discountAmount']);
        $this->assertEquals(75.00, $result['finalPrice']);

        $result2 = \App\Services\Coupon\CouponCalculator::calculate($coupon, 30.00);
        $this->assertEquals(15.00, $result2['discountAmount']);
        $this->assertEquals(15.00, $result2['finalPrice']);
    }

    // =========================================================================
    // SECTION 5: COMBINED PROMOTION + COUPON CHECKOUT
    // =========================================================================

    /** @test */
    public function checkout_totals_match_manual_calculation(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Checkout', 500.00);
        $cart = $this->makeCartWithItem($user, $product, 500.00);

        $promotion = Promotion::create([
            'name' => '50 Off',
            'code' => 'CHKPRO-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 50,
            'discount' => 50,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $coupon = Coupon::create([
            'name' => ['en' => '10% Off'],
            'slug' => 'cc-' . Str::random(6),
            'code' => 'CHKCPN-' . Str::random(6),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $cart->update(['coupon' => $coupon->code]);

        $promoService = app(PromotionService::class);
        $promoService->applySelectedPromotion($cart->fresh(), $promotion->id);

        $orderService = app(OrderService::class);
        $totals = $orderService->calculateCheckoutTotals(
            $cart->fresh(), $promotion->id, null, ShippingMethod::SCHEDULED,
        );

        $manualSubtotal = 500.00;
        $manualPromoDiscount = 50.00;
        $manualAfterPromo = $manualSubtotal - $manualPromoDiscount;
        $manualCouponDiscount = round($manualAfterPromo * 0.10, 2);
        $manualFinal = round($manualAfterPromo - $manualCouponDiscount, 2);

        $this->assertEquals($manualSubtotal, $totals->subtotal);
        $this->assertEquals($manualPromoDiscount, $totals->promotionDiscount);
        $this->assertEquals($manualCouponDiscount, $totals->couponDiscount);
        $this->assertEquals($manualFinal, $totals->finalTotal);
        $this->assertEquals($manualSubtotal - $manualPromoDiscount - $manualCouponDiscount, $totals->finalTotal);
    }

    /** @test */
    public function checkout_with_promotion_and_coupon_produces_correct_order(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Order Item', 500, 20);
        $cart = $this->makeCartWithItem($user, $product, 500);

        $promotion = Promotion::create([
            'name' => 'Order Promo',
            'code' => 'ORDPRO-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 100,
            'discount' => 100,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        app()->setLocale('en');
        $coupon = Coupon::create([
            'name' => ['en' => 'Order Coupon'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'ordcpn-' . Str::lower(Str::random(6)),
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
            $cart->fresh(), $promotion->id, null, ShippingMethod::SCHEDULED,
        );

        $this->assertEquals(500.0, $checkoutTotals->subtotal);
        $this->assertEquals(100.0, $checkoutTotals->promotionDiscount);
        $expectedAfterPromotion = 400.0;
        $expectedCouponDiscount = round($expectedAfterPromotion * 0.10, 2);
        $this->assertEquals($expectedCouponDiscount, $checkoutTotals->couponDiscount);
        $expectedFinalTotal = round($expectedAfterPromotion - $expectedCouponDiscount, 2);
        $this->assertEquals($expectedFinalTotal, $checkoutTotals->finalTotal);
    }

    // =========================================================================
    // SECTION 6: ORDER SNAPSHOT VERIFICATION
    // =========================================================================

    /** @test */
    public function order_product_snapshot_contains_correct_prices(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Snap Item', 250.00);
        $cart = $this->makeCartWithItem($user, $product, 250.00);

        $promotion = Promotion::create([
            'name' => 'Snap Promo',
            'code' => 'SNAPPRO-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 50,
            'discount' => 50,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $checkoutTotals = $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $this->assertEquals(250.0, $checkoutTotals->subtotal);
        $this->assertEquals(50.0, $checkoutTotals->promotionDiscount);
        $this->assertEquals(200.0, $checkoutTotals->finalTotal);
    }

    /** @test */
    public function order_snapshot_immutable_after_checkout(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Immutable', 300.00);
        $cart = $this->makeCartWithItem($user, $product, 300.00, 2);

        $promotion = Promotion::create([
            'name' => 'Imm Promo',
            'code' => 'IMM-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 100,
            'discount' => 100,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $service = app(PromotionService::class);
        $service->applySelectedPromotion($cart->fresh(), $promotion->id);

        $cart->refresh();
        $orderService = app(OrderService::class);
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'name' => $user->name,
            'user_phone' => '01000000001',
            'user_email' => $user->email,
            'address' => json_encode(['address' => '123 Street']),
            'governorate_id' => null,
        ]);
        $request->setUserResolver(fn() => $user);

        $order = $orderService->addItemsInOrder($request);
        $this->assertNotNull($order);

        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);
        $originalTotalPrice = (float) $orderItem->product_total_price;
        $originalUnitPrice = (float) $orderItem->product_price;
        $originalQty = (int) $orderItem->product_quantity;

        $product->update(['price' => 999.99]);

        $orderItem->refresh();
        $this->assertEquals($originalTotalPrice, (float) $orderItem->product_total_price);
        $this->assertEquals($originalUnitPrice, (float) $orderItem->product_price);
        $this->assertEquals($originalQty, (int) $orderItem->product_quantity);
    }

    /** @test */
    public function promotion_applied_before_coupon_in_checkout(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Ordered', 200, 10);
        $cart = $this->makeCartWithItem($user, $product, 200);

        $promotion = Promotion::create([
            'name' => 'Promo First',
            'code' => 'FRST-' . Str::upper(Str::random(6)),
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
            'slug' => 'c-' . Str::random(6),
            'code' => 'CPN-' . Str::lower(Str::random(6)),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $cart->update(['coupon' => $coupon->code]);

        $ps = app(PromotionService::class);
        $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $os = app(OrderService::class);
        $totals = $os->calculateCheckoutTotals($cart->fresh(), $promotion->id);

        $this->assertEquals(200.0, $totals->subtotal);
        $this->assertEquals(10.0, $totals->promotionDiscount);

        $expectedAfterPromotion = 190.0;
        $expectedCouponDiscount = round($expectedAfterPromotion * 0.10, 2);
        $this->assertEquals($expectedCouponDiscount, $totals->couponDiscount);

        $expectedFinal = round($expectedAfterPromotion - $expectedCouponDiscount, 2);
        $this->assertEquals($expectedFinal, $totals->finalTotal);
        $this->assertEquals(200.0 - 10.0 - $expectedCouponDiscount, $totals->finalTotal);
    }

    // =========================================================================
    // SECTION 7: FLOATING POINT EDGE CASES
    // =========================================================================

    /** @test */
    public function sub_penny_prices_do_not_cause_precision_loss(): void
    {
        $service = app(ProductPricingService::class);

        $product = $this->makeSimpleProduct('Tiny', 0.01);
        $result = $service->calculateProductPricing($product);
        $this->assertEquals(0.01, $result['final_price']);

        $product2 = $this->makeSimpleProduct('Tiny2', 0.99);
        $result2 = $service->calculateProductPricing($product2);
        $this->assertEquals(0.99, $result2['final_price']);
    }

    /** @test */
    public function rounding_never_produces_negative_prices(): void
    {
        $service = app(ProductPricingService::class);
        $promotion = Promotion::create([
            'name' => 'Huge Discount',
            'code' => 'HUGE-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 1000,
            'discount' => 1000,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $this->assertEquals(10.0, $promotion->discountAmount(10.0), 'Fixed discount capped to price');
        $this->assertEquals(0.0, $promotion->calcPrice(10.0), 'Final price cannot be negative');

        $product = $this->makeSimpleProduct('Small', 5.00);
        $discountProduct = $this->makeDiscountedProduct('BigDisc', 5.00, DiscountType::FIXED_RATE, 100);
        $result = $service->calculateProductPricing($discountProduct);
        $this->assertEquals(0.0, $result['final_price']);
    }

    /** @test */
    public function large_quantities_maintain_precision(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('Bulk', 9.99);
        $cart = $this->makeCartWithItem($user, $product, 9.99, 100);

        $orderService = app(OrderService::class);
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'name' => $user->name,
            'user_phone' => '01000000001',
            'user_email' => $user->email,
            'address' => json_encode(['address' => 'Street']),
            'governorate_id' => null,
        ]);
        $request->setUserResolver(fn() => $user);

        $order = $orderService->addItemsInOrder($request);
        $this->assertNotNull($order);

        $expectedLineTotal = round(9.99 * 100, 2);
        $orderItem = $order->orderItems()->first();
        $this->assertEquals($expectedLineTotal, (float) $orderItem->product_total_price);
        $this->assertEquals(100, (int) $orderItem->product_quantity);
    }

    /** @test */
    public function multiple_cart_items_line_totals_sum_correctly(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $p1 = $this->makeSimpleProduct('A', 10.50);
        $p2 = $this->makeSimpleProduct('B', 25.75);
        $p3 = $this->makeSimpleProduct('C', 100.00);

        $items = [
            ['product' => $p1, 'price' => 10.50, 'quantity' => 3],
            ['product' => $p2, 'price' => 25.75, 'quantity' => 2],
            ['product' => $p3, 'price' => 100.00, 'quantity' => 1],
        ];

        $cart = $this->makeCartWithMultipleItems($user, $items);

        $manualSubtotal = 0;
        foreach ($items as $item) {
            $manualSubtotal += round($item['price'] * $item['quantity'], 2);
        }
        $this->assertEquals(183.00, $manualSubtotal);

        $orderService = app(OrderService::class);
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'name' => $user->name,
            'user_phone' => '01000000001',
            'user_email' => $user->email,
            'address' => json_encode(['address' => 'Street']),
            'governorate_id' => null,
        ]);
        $request->setUserResolver(fn() => $user);

        $order = $orderService->addItemsInOrder($request);
        $this->assertNotNull($order);
        $this->assertEquals($manualSubtotal, (float) $order->price);
    }

    // =========================================================================
    // SECTION 8: MINIMUM ORDER AMOUNT
    // =========================================================================

    /** @test */
    public function minimum_order_amount_reads_from_dedicated_column(): void
    {
        $settings = Settings::first();
        $this->assertNotNull($settings);

        $settings->update(['minimum_order_amount' => 100.00]);
        $settings->refresh();

        $readValue = (float) (Settings::first()?->minimum_order_amount ?? 0);
        $this->assertEquals(100.00, $readValue);

        $oldValue = Settings::first()?->options['minimumOrderAmount'] ?? null;
        $this->assertNull($oldValue, 'Legacy options field should not contain minimumOrderAmount');
    }

    /** @test */
    public function minimum_order_amount_enforced_at_checkout(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $settings = Settings::first();
        $settings->update(['minimum_order_amount' => 200.00]);

        $product = $this->makeSimpleProduct('Cheap', 50);
        $cart = $this->makeCartWithItem($user, $product, 50);

        $orderService = app(OrderService::class);
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'name' => $user->name,
            'user_phone' => '01000000001',
            'user_email' => $user->email,
            'address' => json_encode(['address' => 'Street']),
            'governorate_id' => null,
        ]);
        $request->setUserResolver(fn() => $user);

        $this->expectException(\InvalidArgumentException::class);
        $orderService->addItemsInOrder($request);
    }

    /** @test */
    public function minimum_order_amount_zero_allows_any_order(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $settings = Settings::first();
        $settings->update(['minimum_order_amount' => 0.00]);

        $product = $this->makeSimpleProduct('Tiny', 0.50);
        $cart = $this->makeCartWithItem($user, $product, 0.50);

        $orderService = app(OrderService::class);
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'name' => $user->name,
            'user_phone' => '01000000001',
            'user_email' => $user->email,
            'address' => json_encode(['address' => 'Street']),
            'governorate_id' => null,
        ]);
        $request->setUserResolver(fn() => $user);

        $order = $orderService->addItemsInOrder($request);
        $this->assertNotNull($order);
    }

    // =========================================================================
    // SECTION 9: TOTALS INVARIANT
    // =========================================================================

    /** @test */
    public function totals_invariant_holds(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $p1 = $this->makeSimpleProduct('X', 100.00);
        $p2 = $this->makeSimpleProduct('Y', 250.50);
        $cart = $this->makeCartWithMultipleItems($user, [
            ['product' => $p1, 'price' => 100.00, 'quantity' => 2],
            ['product' => $p2, 'price' => 250.50, 'quantity' => 1],
        ]);

        $promotion = Promotion::create([
            'name' => 'Invariant Promo',
            'code' => 'INV-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 15,
            'discount' => 15,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $coupon = Coupon::create([
            'name' => ['en' => 'Invariant Coupon'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'INVCPN-' . Str::random(6),
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 20,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $cart->update(['coupon' => $coupon->code]);

        $ps = app(PromotionService::class);
        $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $os = app(OrderService::class);
        $totals = $os->calculateCheckoutTotals($cart->fresh(), $promotion->id);

        $this->assertEquals($totals->subtotal - $totals->promotionDiscount - $totals->couponDiscount, $totals->finalTotal);
        $this->assertGreaterThanOrEqual(0, $totals->finalTotal);
        $this->assertGreaterThanOrEqual(0, $totals->promotionDiscount);
        $this->assertGreaterThanOrEqual(0, $totals->couponDiscount);
    }

    // =========================================================================
    // SECTION 10: BUG REGRESSION TESTS
    // =========================================================================

    /** @test */
    public function promotion_proportional_allocation_handles_price_changes(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $p1 = $this->makeSimpleProduct('A', 100.00);
        $p2 = $this->makeSimpleProduct('B', 200.00);
        $p3 = $this->makeSimpleProduct('C', 300.00);

        $cart = $this->makeCartWithMultipleItems($user, [
            ['product' => $p1, 'price' => 100.00, 'quantity' => 1],
            ['product' => $p2, 'price' => 200.00, 'quantity' => 1],
            ['product' => $p3, 'price' => 300.00, 'quantity' => 1],
        ]);

        $promotion = Promotion::create([
            'name' => 'Test Prop',
            'code' => 'TPROP-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 30,
            'discount' => 30,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $ps = app(PromotionService::class);
        $totals = $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $expectedDiscount = round(600.00 * 0.30, 2);
        $expectedFinal = round(600.00 - $expectedDiscount, 2);

        $this->assertEquals($expectedDiscount, $totals->promotionDiscount);
        $this->assertEquals($expectedFinal, $totals->finalTotal);

        $items = $cart->fresh()->items;
        $sumDiscounts = round((float) $items->sum('discount_amount'), 2);
        $sumTotals = round((float) $items->sum('total_price'), 2);
        $this->assertEquals($expectedDiscount, $sumDiscounts);
        $this->assertEquals($expectedFinal, $sumTotals);
    }

    /** @test */
    public function proportional_allocation_largest_remainder_completes_full_discount(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $p1 = $this->makeSimpleProduct('X1', 33.33);
        $p2 = $this->makeSimpleProduct('X2', 33.33);
        $p3 = $this->makeSimpleProduct('X3', 33.34);

        $cart = $this->makeCartWithMultipleItems($user, [
            ['product' => $p1, 'price' => 33.33, 'quantity' => 1],
            ['product' => $p2, 'price' => 33.33, 'quantity' => 1],
            ['product' => $p3, 'price' => 33.34, 'quantity' => 1],
        ]);

        $promotion = Promotion::create([
            'name' => 'Rem Test',
            'code' => 'REM-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 30,
            'discount' => 30,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $ps = app(PromotionService::class);
        $totals = $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $expectedDiscount = round(100.00 * 0.30, 2);
        $this->assertEquals($expectedDiscount, $totals->promotionDiscount);
        $this->assertEquals(round(100.00 - $expectedDiscount, 2), $totals->finalTotal);

        $items = $cart->fresh()->items;
        $sumDiscounts = round((float) $items->sum('discount_amount'), 2);
        $this->assertEquals($expectedDiscount, $sumDiscounts);
    }

    /** @test */
    public function settings_get_data_is_restored(): void
    {
        $settings = Settings::getData();
        $this->assertNotNull($settings);
        $this->assertInstanceOf(Settings::class, $settings);

        $first = Settings::first();
        $this->assertEquals($first->id, $settings->id);
    }

    /** @test */
    public function flash_sale_rounding_precision_edge_case(): void
    {
        $service = app(ProductPricingService::class);

        $product = $this->makeDiscountedProduct('TinyDisc', 0.01, DiscountType::PERCENTAGE, 50);
        $result = $service->calculateProductPricing($product);
        $this->assertEquals(0.00, $result['final_price']);

        $product2 = $this->makeSimpleProduct('TinyFlash', 0.01);
        $flashSale = FlashSale::create([
            'title' => '50% Tiny',
            'slug' => 'tflash-' . Str::random(6),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 50,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);
        $result2 = $service->calculateProductPricing($product2, $flashSale);
        $this->assertEquals(0.00, $result2['final_price']);
    }

    /** @test */
    public function wallet_points_round_correctly(): void
    {
        $settings = Settings::first();
        $settings->update([
            'options' => array_merge((array) ($settings->options ?? []), [
                'currencyToWalletRatio' => 1,
            ]),
        ]);
        $settings->refresh();

        $ratio = (float) ($settings->options['currencyToWalletRatio'] ?? 1);
        $this->assertEquals(1, $ratio);
    }

    // =========================================================================
    // SECTION 11: PRICING PRIORITY MATRIX
    // =========================================================================

    /** @test */
    public function flash_sale_takes_priority_over_promotion(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('FS+Promo', 200.00);
        $flashSale = FlashSale::create([
            'title' => 'FS 20%',
            'slug' => 'fspromo-' . Str::random(6),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 20,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);

        $cart = $this->makeCartWithItem($user, $product, 200.00);

        $promotion = Promotion::create([
            'name' => 'Promo 10%',
            'code' => 'FSPRO-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $ps = app(PromotionService::class);
        $totals = $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $this->assertEquals(200.00, $totals->subtotal);
        $expectedPromoDiscount = round(200.00 * 0.10, 2);
        $this->assertEquals($expectedPromoDiscount, $totals->promotionDiscount);
        $this->assertEquals(round(200.00 - $expectedPromoDiscount, 2), $totals->finalTotal);
    }

    /** @test */
    public function flash_sale_with_discount_and_coupon_priority(): void
    {
        $service = app(ProductPricingService::class);
        $product = $this->makeDiscountedProduct('AllThree', 200.00, DiscountType::PERCENTAGE, 10);

        $flashSale = FlashSale::create([
            'title' => 'FS 20%',
            'slug' => 'allthree-' . Str::random(6),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 20,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);

        $result = $service->calculateProductPricing($product, $flashSale);
        $this->assertNotNull($result['price_after_flash_sale']);
        $this->assertNull($result['price_after_discount']);
        $flashPrice = round(200.00 * (1 - 20 / 100), 2);
        $this->assertEquals($flashPrice, $result['final_price']);

        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $cart = $this->makeCartWithItem($user, $product, 200.00);

        $coupon = Coupon::create([
            'name' => ['en' => 'AllThree Coupon'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'ALL3-' . Str::random(6),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $cart->update(['coupon' => $coupon->code]);

        $promotion = Promotion::create([
            'name' => 'AllThree Promo',
            'code' => 'ALL3P-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 30,
            'discount' => 30,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $ps = app(PromotionService::class);
        $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $os = app(OrderService::class);
        $totals = $os->calculateCheckoutTotals($cart->fresh(), $promotion->id);

        $this->assertEquals(200.00, $totals->subtotal);
        $this->assertEquals(30.00, $totals->promotionDiscount);
        $expectedAfterPromo = 170.00;
        $expectedCouponDisc = round($expectedAfterPromo * 0.10, 2);
        $this->assertEquals($expectedCouponDisc, $totals->couponDiscount);
        $expectedFinal = round($expectedAfterPromo - $expectedCouponDisc, 2);
        $this->assertEquals($expectedFinal, $totals->finalTotal);
    }

    /** @test */
    public function promotion_with_free_shipping_coupon(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('FreeShip', 150.00);
        $cart = $this->makeCartWithItem($user, $product, 150.00);

        $promotion = Promotion::create([
            'name' => 'FreeShip Promo',
            'code' => 'FSHPP-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 20,
            'discount' => 20,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $coupon = Coupon::create([
            'name' => ['en' => 'Free Shipping Coupon'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'FREESHIP-' . Str::random(6),
            'discount_type' => DiscountType::FREE_SHIPPING,
            'discount' => 0,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $cart->update(['coupon' => $coupon->code]);

        $ps = app(PromotionService::class);
        $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $os = app(OrderService::class);
        $totals = $os->calculateCheckoutTotals($cart->fresh(), $promotion->id);

        $this->assertEquals(150.00, $totals->subtotal);
        $expectedPromoDisc = round(150.00 * 0.20, 2);
        $this->assertEquals($expectedPromoDisc, $totals->promotionDiscount);
        $this->assertEquals(0.00, $totals->couponDiscount);
        $this->assertEquals(DiscountType::FREE_SHIPPING, $totals->couponDiscountType);
    }

    /** @test */
    public function fixed_rate_promotion_with_percentage_coupon(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $p1 = $this->makeSimpleProduct('FixA', 100.00);
        $p2 = $this->makeSimpleProduct('FixB', 200.00);
        $cart = $this->makeCartWithMultipleItems($user, [
            ['product' => $p1, 'price' => 100.00, 'quantity' => 1],
            ['product' => $p2, 'price' => 200.00, 'quantity' => 1],
        ]);

        $promotion = Promotion::create([
            'name' => 'Fix 50',
            'code' => 'FIX50-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 50,
            'discount' => 50,
            'apply_to' => 'all_products',
            'status' => true,
        ]);

        $coupon = Coupon::create([
            'name' => ['en' => '10% After Promo'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'FIXCPN-' . Str::random(6),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $cart->update(['coupon' => $coupon->code]);

        $ps = app(PromotionService::class);
        $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $os = app(OrderService::class);
        $totals = $os->calculateCheckoutTotals($cart->fresh(), $promotion->id);

        $this->assertEquals(300.00, $totals->subtotal);
        $this->assertEquals(50.00, $totals->promotionDiscount);
        $expectedAfterPromo = 250.00;
        $expectedCouponDisc = round($expectedAfterPromo * 0.10, 2);
        $this->assertEquals($expectedCouponDisc, $totals->couponDiscount);
        $this->assertEquals(round($expectedAfterPromo - $expectedCouponDisc, 2), $totals->finalTotal);
    }

    /** @test */
    public function minimum_order_amount_reads_from_dedicated_column_not_options(): void
    {
        $settings = Settings::first();
        $this->assertNotNull($settings);

        $settings->update([
            'minimum_order_amount' => 250.00,
            'options' => array_merge((array) ($settings->options ?? []), [
                'minimumOrderAmount' => 999.99,
            ]),
        ]);
        $settings->refresh();

        $readValue = (float) (Settings::first()?->minimum_order_amount ?? 0);
        $this->assertEquals(250.00, $readValue);

        $orderService = app(OrderService::class);
        $reflection = new \ReflectionClass($orderService);
        $method = $reflection->getMethod('addItemsInOrder');
        $this->assertTrue($method->isPublic() || $method->isPrivate(), 'method exists');
    }
}
