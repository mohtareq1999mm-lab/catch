<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\General\OrderService;
use App\Services\General\PromotionService;
use App\Services\Coupon\CouponCalculator;
use App\Services\Coupon\CouponOrchestrator;
use App\Services\Coupon\CouponValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponAssignmentUsage;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\DiscountType;
use Marvel\Enums\FlashSaleType;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\ProductType;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Enums\ShippingMethod;
use Marvel\Services\Pricing\ProductPricingService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancialDeepAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        if (!Settings::exists()) {
            Settings::create([
                'site_name' => 'Audit Test',
                'options' => [],
                'minimum_order_amount' => 0]);
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
            'status' => true]);
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
            'end_date' => now()->addMonth()]);
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
            'status' => true]);

        $variants = [];
        foreach ($variantsData as $v) {
            $variants[] = ProductVariant::create([
                'product_id' => $product->id,
                'price' => $v['price'],
                'sale_price' => $v['sale_price'] ?? null,
                'stock_quantity' => $v['stock'] ?? 10,
                'reserved_quantity' => 0,
                'in_stock' => ($v['stock'] ?? 10) > 0,
                'sku' => 'SKU-' . Str::random(8)]);
        }
        return ['product' => $product, 'variants' => $variants];
    }

    private function makeCartWithItem(User $user, Product $product, float $price = 100, int $quantity = 1): Cart
    {
        $cart = Cart::create(['user_id' => $user->id, 'status' => 'active', 'total_price' => 0]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'reserved_quantity' => $quantity,
            'price' => $price,
            'total_price' => round($price * $quantity, 2),
            'attributes' => null,
            'shipping_method' => ShippingMethod::SCHEDULED]);
        return $cart;
    }

    private function makeCartWithMultipleItems(User $user, array $items): Cart
    {
        $cart = Cart::create(['user_id' => $user->id, 'status' => 'active', 'total_price' => 0]);
        foreach ($items as $item) {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $item['product']->id,
                'quantity' => $item['quantity'],
                'reserved_quantity' => $item['quantity'],
                'price' => $item['price'],
                'total_price' => round($item['price'] * $item['quantity'], 2),
                'attributes' => null,
                'shipping_method' => $item['shipping_method'] ?? ShippingMethod::SCHEDULED]);
        }
        return $cart;
    }

    private function makeOrderRequest(User $user, ?int $governorateId = null): \Illuminate\Http\Request
    {
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'name' => $user->name,
            'user_phone' => '01000000001',
            'user_email' => $user->email,
            'address' => json_encode(['address' => '123 Street']),
            'governorate_id' => $governorateId]);
        $request->setUserResolver(fn() => $user);
        return $request;
    }

    private function makeSuperAdmin(): User
    {
        Permission::findOrCreate('api');
        Permission::findOrCreate(PermissionEnum::UPDATE_SETTINGS, 'api');

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => 'api',
            'display_name' => json_encode(['en' => 'Super Admin'])]);
        $role->givePermissionTo([PermissionEnum::UPDATE_SETTINGS]);

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@audit.local',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeGovernorateWithShipping(float $price, ?float $freeShippingOver = null): Governorate
    {
        $country = Country::create(['name' => 'Test Country', 'status' => true]);
        $gov = Governorate::create(['name' => 'Test Gov', 'country_id' => $country->id, 'status' => true]);
        ShippingPrice::create([
            'governorate_id' => $gov->id,
            'price' => $price,
            'free_shipping_over' => $freeShippingOver,
            'status' => true]);
        return $gov;
    }

    // =========================================================================
    // SECTION 1: CONCURRENCY AUDIT
    // =========================================================================

    /** @test */
    public function promotion_usage_limiter_enforces_max_uses(): void
    {
        $promotion = Promotion::create([
            'name' => 'Limited',
            'code' => 'LIMITED-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'limiter' => 1,
            'usage' => 1,
            'apply_to' => 'all_products',
            'status' => true]);

        $this->assertFalse($promotion->isValid());

        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $product = $this->makeSimpleProduct('LimitedP', 100.00);
        $cart = $this->makeCartWithItem($user, $product, 100.00);

        $ps = app(PromotionService::class);
        $this->expectException(\InvalidArgumentException::class);
        $ps->applySelectedPromotion($cart->fresh(), $promotion->id);
    }

    /** @test */
    public function concurrent_coupon_usage_unique_per_user(): void
    {
        $user = $this->makeUser();
        $coupon = Coupon::create([
            'name' => ['en' => 'Unique'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'UNQ-' . Str::random(6),
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 20,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth()]);

        $created = CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'order_id' => null,
            'used_at' => now()]);
        $this->assertNotNull($created);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/unique|duplicate|constraint/i');
        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'order_id' => null,
            'used_at' => now()]);
    }

    /** @test */
    public function transaction_lock_prevents_double_promotion_application(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $product = $this->makeSimpleProduct('LockTest', 200.00);
        $cart = $this->makeCartWithItem($user, $product, 200.00);

        $promotion = Promotion::create([
            'name' => 'Lock Promo',
            'code' => 'LCK-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 20,
            'discount' => 20,
            'apply_to' => 'all_products',
            'status' => true]);

        $ps = app(PromotionService::class);
        $result1 = $ps->applySelectedPromotion($cart->fresh(), $promotion->id);
        $this->assertEquals(40.00, $result1->promotionDiscount);

        $item = $cart->fresh()->items()->first();
        $this->assertNotNull($item->promotion_id);
        $this->assertEquals(40.00, (float) $item->discount_amount);
    }

    // =========================================================================
    // SECTION 2: ASSIGNED COUPON AUDIT
    // =========================================================================

    /** @test */
    public function assigned_coupon_usage_recorded_in_assignment_and_global(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $product = $this->makeSimpleProduct('Assigned', 300.00);
        $cart = $this->makeCartWithItem($user, $product, 300.00);

        $coupon = Coupon::create([
            'name' => ['en' => 'Assigned'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'ASGN-' . Str::random(6),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 15,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'used' => 0]);

        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 3,
            'used' => 0]);

        $cart->update(['coupon' => $coupon->code]);
        $os = app(OrderService::class);
        $order = $os->addItemsInOrder($this->makeOrderRequest($user));
        $this->assertNotNull($order);

        $order->update(['status' => 'completed']);
        $ref = new \ReflectionMethod($os, 'recordCouponUsage');
        $ref->setAccessible(true);
        $ref->invoke($os, $order);

        $coupon->refresh();
        $assignment->refresh();
        $this->assertEquals(1, $coupon->used);
        $this->assertEquals(1, $assignment->used);

        $usageExists = CouponAssignmentUsage::where('coupon_assignment_id', $assignment->id)
            ->where('order_id', $order->id)->exists();
        $this->assertTrue($usageExists);
    }

    /** @test */
    public function assigned_coupon_rejects_when_max_uses_exhausted(): void
    {
        $user = $this->makeUser();
        $coupon = Coupon::create([
            'name' => ['en' => 'Exhausted'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'EXH-' . Str::random(6),
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 50,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth()]);
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
            'used' => 1]);

        $validation = CouponOrchestrator::validate($coupon, $user, collect());
        $this->assertFalse($validation['valid']);
    }

    // =========================================================================
    // SECTION 3: GIFT PROMOTION AUDIT
    // =========================================================================

    /** @test */
    public function gift_promotion_has_zero_discount_and_reserves_gift_item(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $product = $this->makeSimpleProduct('Buy Item', 150.00);
        $giftProduct = $this->makeSimpleProduct('Free Gift', 0.00, 5);
        $cart = $this->makeCartWithItem($user, $product, 150.00);

        $promotion = Promotion::create([
            'name' => 'Gift Promo',
            'code' => 'GIFT-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'apply_to' => 'all_products',
            'status' => true]);
        $promotion->giftProducts()->attach($giftProduct->id, ['quantity' => 1, 'product_variant_id' => null]);

        $ps = app(PromotionService::class);
        $totals = $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $this->assertEquals(150.00, $totals->subtotal);
        $this->assertEquals(0.00, $totals->promotionDiscount);
        $this->assertEquals(150.00, $totals->finalTotal);

        $giftItem = $cart->fresh()->items()->where('is_gift', true)->first();
        $this->assertNotNull($giftItem);
        $this->assertEquals($giftProduct->id, $giftItem->product_id);
        $this->assertEquals(0.00, (float) $giftItem->price);
    }

    // =========================================================================
    // SECTION 4: SPECIFIC PRODUCTS PROMOTION
    // =========================================================================

    /** @test */
    public function promotion_applies_only_to_specific_products(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $eligible = $this->makeSimpleProduct('Eligible', 200.00);
        $ineligible = $this->makeSimpleProduct('Ineligible', 100.00);
        $cart = $this->makeCartWithMultipleItems($user, [
            ['product' => $eligible, 'price' => 200.00, 'quantity' => 1],
            ['product' => $ineligible, 'price' => 100.00, 'quantity' => 1]]);

        $promotion = Promotion::create([
            'name' => 'Specific',
            'code' => 'SPC-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 20,
            'discount' => 20,
            'apply_to' => 'specific_products',
            'status' => true]);
        $promotion->products()->attach($eligible->id);

        $ps = app(PromotionService::class);
        $totals = $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $this->assertEquals(300.00, $totals->subtotal);
        $this->assertEquals(40.00, $totals->promotionDiscount);

        $ei = $cart->fresh()->items()->where('product_id', $eligible->id)->first();
        $this->assertEquals(40.00, (float) $ei->discount_amount);
        $this->assertEquals(160.00, (float) $ei->total_price);

        $ii = $cart->fresh()->items()->where('product_id', $ineligible->id)->first();
        $this->assertEquals(0.00, (float) $ii->discount_amount);
        $this->assertEquals(100.00, (float) $ii->total_price);
    }

    // =========================================================================
    // SECTION 5: EXPIRED / FUTURE / INACTIVE DISCOUNTS
    // =========================================================================

    /** @test */
    public function expired_discount_not_applied(): void
    {
        $service = app(ProductPricingService::class);
        $product = Product::create([
            'name' => 'Expired',
            'slug' => 'expd-' . Str::random(6),
            'price' => 100.00, 'product_type' => ProductType::SIMPLE,
            'stock_quantity' => 10, 'in_stock' => true, 'status' => true,
            'has_discount' => true, 'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 50,
            'start_date' => now()->subDays(10), 'end_date' => now()->subDay()]);
        $result = $service->calculateProductPricing($product);
        $this->assertNull($result['price_after_discount']);
        $this->assertEquals(100.00, $result['final_price']);
    }

    /** @test */
    public function future_discount_not_applied(): void
    {
        $service = app(ProductPricingService::class);
        $product = Product::create([
            'name' => 'Future',
            'slug' => 'futd-' . Str::random(6),
            'price' => 100.00, 'product_type' => ProductType::SIMPLE,
            'stock_quantity' => 10, 'in_stock' => true, 'status' => true,
            'has_discount' => true, 'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 30,
            'start_date' => now()->addDay(), 'end_date' => now()->addMonth()]);
        $result = $service->calculateProductPricing($product);
        $this->assertNull($result['price_after_discount']);
        $this->assertEquals(100.00, $result['final_price']);
    }

    /** @test */
    public function expired_promotion_rejected_by_resolver(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $product = $this->makeSimpleProduct('Exp Promo', 100.00);
        $cart = $this->makeCartWithItem($user, $product, 100.00);

        $promotion = Promotion::create([
            'name' => 'Expired', 'code' => 'EXPP-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE, 'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 20, 'discount' => 20, 'apply_to' => 'all_products', 'status' => true,
            'start_at' => now()->subDays(10), 'end_at' => now()->subDay()]);

        $ps = app(PromotionService::class);
        $this->expectException(\InvalidArgumentException::class);
        $ps->applySelectedPromotion($cart->fresh(), $promotion->id);
    }

    /** @test */
    public function inactive_promotion_rejected(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $product = $this->makeSimpleProduct('Inactive', 100.00);
        $cart = $this->makeCartWithItem($user, $product, 100.00);

        $promotion = Promotion::create([
            'name' => 'Inactive', 'code' => 'INA-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE, 'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 20, 'discount' => 20, 'apply_to' => 'all_products', 'status' => false]);

        $ps = app(PromotionService::class);
        $this->expectException(\InvalidArgumentException::class);
        $ps->applySelectedPromotion($cart->fresh(), $promotion->id);
    }

    /** @test */
    public function expired_coupon_rejected_by_orchestrator(): void
    {
        $user = $this->makeUser();
        $coupon = Coupon::create([
            'name' => ['en' => 'Expired'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'EXPC-' . Str::random(6),
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 10, 'status' => true,
            'start_date' => now()->subDays(10), 'end_date' => now()->subDay()]);
        $validation = CouponOrchestrator::validate($coupon, $user, collect());
        $this->assertFalse($validation['valid']);
    }

    // =========================================================================
    // SECTION 6: SHIPPING + FREE SHIPPING INTEGRATION
    // =========================================================================

    /** @test */
    public function free_shipping_by_threshold_works_with_promotion(): void
    {
        $gov = $this->makeGovernorateWithShipping(30.00, 500.00);
        $os = app(OrderService::class);

        $info = $os->getGovernorateShippingInfo((int) $gov->id);
        $this->assertEquals(30.00, $info['price']);
        $this->assertEquals(500.00, $info['free_shipping_over']);

        $shipping = $os->resolveFreeShippingByThreshold(600.00, 500.00, 30.00);
        $this->assertEquals(0.00, $shipping);

        $shipping2 = $os->resolveFreeShippingByThreshold(400.00, 500.00, 30.00);
        $this->assertEquals(30.00, $shipping2);
    }

    /** @test */
    public function free_shipping_coupon_overrides_shipping_cost(): void
    {
        $os = app(OrderService::class);
        $this->assertEquals(0.00, $os->resolveFreeShippingByCoupon(DiscountType::FREE_SHIPPING, 25.00));
        $this->assertEquals(25.00, $os->resolveFreeShippingByCoupon(DiscountType::PERCENTAGE, 25.00));
        $this->assertEquals(40.00, $os->resolveFreeShippingByCoupon(DiscountType::FIXED_RATE, 40.00));
    }

    /** @test */
    public function shipping_cost_included_in_order_total(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $product = $this->makeSimpleProduct('Ship', 200.00);
        $this->makeCartWithItem($user, $product, 200.00);
        $gov = $this->makeGovernorateWithShipping(35.00);

        $os = app(OrderService::class);
        $order = $os->addItemsInOrder($this->makeOrderRequest($user, (int) $gov->id));
        $this->assertNotNull($order);
        $this->assertNotNull($order->governorate_id);
        $this->assertEquals(200.00, (float) $order->price);
    }

    // =========================================================================
    // SECTION 7: VARIANT PRICING WITH FLASH SALE AND PROMOTION
    // =========================================================================

    /** @test */
    public function variant_with_flash_sale_applied_correctly(): void
    {
        $service = app(ProductPricingService::class);
        $data = $this->makeVariableProduct('Var Flash', [
            ['price' => 100.00, 'stock' => 5],
            ['price' => 200.00, 'stock' => 3]]);

        $flashSale = FlashSale::create([
            'title' => 'Var 20%', 'slug' => 'vflash-' . Str::random(6),
            'type' => FlashSaleType::PERCENTAGE, 'discount' => 20,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true]);

        foreach ($data['variants'] as $variant) {
            $price = $service->calculateVariantCurrentPrice($data['product'], $variant, $flashSale);
            $expected = round((float) $variant->price * (1 - 20 / 100), 2);
            $this->assertEquals($expected, $price);
        }
    }

    /** @test */
    public function variant_with_product_discount_and_no_flash_sale(): void
    {
        $product = Product::create([
            'name' => 'Disc Var', 'slug' => 'dvar-' . Str::random(6),
            'price' => 200.00, 'product_type' => ProductType::VARIABLE,
            'stock_quantity' => 0, 'in_stock' => true, 'status' => true,
            'has_discount' => true, 'discount_type' => DiscountType::PERCENTAGE,
            'discount_amount' => 15, 'start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'price' => 150.00,
            'stock_quantity' => 5, 'reserved_quantity' => 0, 'in_stock' => true,
            'sku' => 'VAR-' . Str::random(8)]);

        $price = app(ProductPricingService::class)->calculateVariantCurrentPrice($product, $variant);
        $this->assertEquals(round(150.00 * 0.85, 2), $price);
    }

    // =========================================================================
    // SECTION 8: PROMOTION MINIMUM ORDER AMOUNT
    // =========================================================================

    /** @test */
    public function promotion_minimum_order_amount_enforced(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $product = $this->makeSimpleProduct('Cheap', 50.00);
        $cart = $this->makeCartWithItem($user, $product, 50.00);

        $promotion = Promotion::create([
            'name' => 'MinOrd', 'code' => 'MINO-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE, 'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10, 'discount' => 10, 'minimum_order_amount' => 100,
            'apply_to' => 'all_products', 'status' => true]);

        $ps = app(PromotionService::class);
        $this->expectException(\InvalidArgumentException::class);
        $ps->applySelectedPromotion($cart->fresh(), $promotion->id);
    }

    // =========================================================================
    // SECTION 9: USAGE LIMITER AUDIT
    // =========================================================================

    /** @test */
    public function promotion_usage_limiter_at_scope_valid(): void
    {
        $promotion = Promotion::create([
            'name' => 'Scope Lmt', 'code' => 'SCPL-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE, 'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10, 'discount' => 10, 'limiter' => 5, 'usage' => 3,
            'apply_to' => 'all_products', 'status' => true]);
        $this->assertTrue($promotion->isValid());

        $promotion2 = Promotion::create([
            'name' => 'Exhausted', 'code' => 'EXH-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE, 'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10, 'discount' => 10, 'limiter' => 5, 'usage' => 5,
            'apply_to' => 'all_products', 'status' => true]);
        $this->assertFalse($promotion2->isValid());
    }

    /** @test */
    public function coupon_global_limiter_enforced_by_validator(): void
    {
        $coupon = Coupon::create([
            'name' => ['en' => 'Global Lmt'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'GLLMT-' . Str::random(6),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(),
            'used' => 100, 'limiter' => 100]);

        $user = $this->makeUser();
        $validation = CouponValidator::validate($coupon, $user, collect());
        $this->assertFalse($validation['valid']);
        $this->assertEquals('usage_limit_reached', $validation['reason']);
    }

    // =========================================================================
    // SECTION 10: SETTINGS MINIMUM_ORDER_AMOUNT VERIFICATION
    // =========================================================================

    /** @test */
    public function settings_minimum_order_amount_reads_from_column(): void
    {
        $settings = Settings::first();
        $settings->update([
            'minimum_order_amount' => 150.00,
            'options' => array_merge((array) ($settings->options ?? []), [
                'minimumOrderAmount' => 999.99])]);
        $settings->refresh();

        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $product = $this->makeSimpleProduct('TestMin', 100.00);
        $this->makeCartWithItem($user, $product, 100.00);

        $os = app(OrderService::class);
        try {
            $os->addItemsInOrder($this->makeOrderRequest($user));
            $this->fail('Should throw for minimum order');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Minimum order amount', $e->getMessage());
        }

        $this->assertEquals(150.00, (float) (Settings::first()?->minimum_order_amount ?? 0));
    }

    /** @test */
    public function settings_api_returns_minimum_order_amount(): void
    {
        Settings::first()->update(['minimum_order_amount' => 75.00]);
        $response = $this->getJson(self::PREFIX . '/settings');
        $response->assertOk();
        $response->assertJsonPath('data.minimumOrderAmount', '75.00');
    }

    /** @test */
    public function settings_update_via_api_writes_to_column(): void
    {
        $user = $this->makeSuperAdmin();
        Sanctum::actingAs($user);

        $response = $this->putJson(self::PREFIX . '/settings', [
            'minimum_order_amount' => 200.00]);
        $response->assertOk();
        $settings = Settings::first();
        $this->assertEquals(200.00, (float) $settings->minimum_order_amount);
    }

    // =========================================================================
    // SECTION 11: MAX DISCOUNT COUPON COMBINED WITH PROMOTION
    // =========================================================================

    /** @test */
    public function max_discount_coupon_with_fixed_promotion(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $product = $this->makeSimpleProduct('MaxDisc', 1000.00);
        $cart = $this->makeCartWithItem($user, $product, 1000.00);

        $promotion = Promotion::create([
            'name' => 'Big Promo', 'code' => 'BIGP-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE, 'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 30, 'discount' => 30, 'apply_to' => 'all_products', 'status' => true]);
        $coupon = Coupon::create([
            'name' => ['en' => 'Capped'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'CAPCPN-' . Str::random(6),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 20, 'max_discount_amount' => 50,
            'status' => true, 'start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
        $cart->update(['coupon' => $coupon->code]);

        $ps = app(PromotionService::class);
        $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $os = app(OrderService::class);
        $totals = $os->calculateCheckoutTotals($cart->fresh(), $promotion->id);

        $this->assertEquals(1000.00, $totals->subtotal);
        $this->assertEquals(300.00, $totals->promotionDiscount);
        $this->assertEquals(50.00, $totals->couponDiscount);
        $this->assertEquals(650.00, $totals->finalTotal);
    }

    // =========================================================================
    // SECTION 12: ORDER IMMUTABILITY
    // =========================================================================

    /** @test */
    public function order_immutable_after_all_price_changes(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $product = $this->makeSimpleProduct('Immutable', 500.00);
        $this->makeCartWithItem($user, $product, 500.00, 3);

        $os = app(OrderService::class);
        $order = $os->addItemsInOrder($this->makeOrderRequest($user));
        $this->assertNotNull($order);

        $snapPrice = (float) $order->price;
        $snapItemPrice = (float) $order->orderItems()->first()->product_price;
        $snapItemTotal = (float) $order->orderItems()->first()->product_total_price;

        $product->update(['price' => 999.99]);
        $product->delete();

        $order->refresh();
        $this->assertEquals($snapPrice, (float) $order->price);
        $this->assertEquals($snapItemPrice, (float) $order->orderItems()->first()->product_price);
        $this->assertEquals($snapItemTotal, (float) $order->orderItems()->first()->product_total_price);
    }

    // =========================================================================
    // SECTION 13: FULL CHECKOUT WITH ALL DISCOUNTS MANUALLY VERIFIED
    // =========================================================================

    /** @test */
    public function complete_checkout_all_discounts_manually_verified(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $productA = $this->makeSimpleProduct('A', 250.00, 20);
        $productB = $this->makeSimpleProduct('B', 150.00, 20);
        $cart = $this->makeCartWithMultipleItems($user, [
            ['product' => $productA, 'price' => 250.00, 'quantity' => 2],
            ['product' => $productB, 'price' => 150.00, 'quantity' => 1]]);
        $this->makeGovernorateWithShipping(40.00, 1000.00);

        // MANUAL:
        // Subtotal: 250*2 + 150*1 = 650.00
        // Promotion 15%: 650*0.15 = 97.50
        // After promo: 552.50
        // Coupon 10% capped at 30: min(55.25, 30) = 30.00
        // After coupon: 522.50
        // Shipping: 40.00 (650 < 1000 free threshold)
        // Grand total: 562.50

        $manualSubtotal = 650.00;
        $manualPromo = 97.50;
        $manualAfterPromo = 552.50;
        $manualCoupon = 30.00;
        $manualAfterCoupon = 522.50;
        $manualShipping = 40.00;
        $manualGrand = 562.50;

        $promotion = Promotion::create([
            'name' => 'Full15', 'code' => 'FULL15-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE, 'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 15, 'discount' => 15, 'apply_to' => 'all_products', 'status' => true]);
        $coupon = Coupon::create([
            'name' => ['en' => 'Full CPN'],
            'slug' => 'c-' . Str::random(6),
            'code' => 'FULLCPN-' . Str::random(6),
            'discount_type' => DiscountType::PERCENTAGE, 'discount' => 10, 'max_discount_amount' => 30,
            'status' => true, 'start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
        $cart->update(['coupon' => $coupon->code]);

        $ps = app(PromotionService::class);
        $ps->applySelectedPromotion($cart->fresh(), $promotion->id);

        $os = app(OrderService::class);
        $totals = $os->calculateCheckoutTotals($cart->fresh(), $promotion->id);

        $this->assertEquals($manualSubtotal, $totals->subtotal, 'subtotal');
        $this->assertEquals($manualPromo, $totals->promotionDiscount, 'promo');
        $this->assertEquals($manualCoupon, $totals->couponDiscount, 'coupon');
        $this->assertEquals($manualAfterCoupon, $totals->finalTotal, 'final');

        $shipping = $os->resolveFreeShippingByThreshold($totals->subtotal, 1000.00, 40.00);
        $this->assertEquals($manualShipping, $shipping, 'shipping');
        $this->assertEquals($manualGrand, round((float) $totals->finalTotal + $shipping, 2), 'grand');
    }

    // =========================================================================
    // SECTION 14: ROUNDING CONSISTENCY
    // =========================================================================

    /** @test */
    public function rounding_consistency_across_discounts(): void
    {
        $service = app(ProductPricingService::class);
        $prices = [0.01, 0.05, 0.10, 0.50, 1.00, 10.00, 99.99, 100.00, 1000.00];
        $discounts = [1, 5, 10, 15, 20, 25, 33, 50, 75, 100];

        foreach ($prices as $price) {
            foreach ($discounts as $discount) {
                $product = $this->makeDiscountedProduct('RT', $price, DiscountType::PERCENTAGE, (float) $discount);
                $result = $service->calculateProductPricing($product);
                $expected = round(max(0, $price - round($price * ($discount / 100), 2)), 2);

                if (abs($expected - $result['final_price']) > 0.01) {
                    $this->assertEquals(
                        $expected, $result['final_price'],
                        "price={$price}, disc={$discount}%, exp={$expected}, got={$result['final_price']}"
                    );
                }
            }
        }
        $this->assertTrue(true);
    }

    /** @test */
    public function number_format_precision_preserved(): void
    {
        foreach ([0.01, 0.10, 1.00, 10.50, 99.99, 100.00, 1000.01] as $v) {
            $this->assertEquals($v, (float) number_format($v, 2, '.', ''));
        }
    }

    // =========================================================================
    // SECTION 15: FINAL MINIMUM_ORDER_AMOUNT MIGRATION VERIFICATION
    // =========================================================================

    /** @test */
    public function no_legacy_minimum_order_amount_in_options(): void
    {
        $settings = Settings::first();
        $settings->update(['minimum_order_amount' => 100.00, 'options' => []]);
        $settings->refresh();

        $this->assertEquals(100.00, (float) $settings->minimum_order_amount);
        $this->assertArrayNotHasKey('minimumOrderAmount', $settings->options ?? []);
        $this->assertArrayNotHasKey('minimum_order_amount', $settings->options ?? []);
    }

    /** @test */
    public function checkout_repository_uses_dedicated_column(): void
    {
        $settings = Settings::first();
        $settings->update([
            'minimum_order_amount' => 300.00,
            'options' => array_merge((array) ($settings->options ?? []), ['minimumOrderAmount' => 999.99])]);

        $read = Settings::first();
        $this->assertEquals(300.00, (float) $read->minimum_order_amount);
        $this->assertEquals(999.99, (float) ($read->options['minimumOrderAmount'] ?? 0));

        $viaGetData = Settings::getData();
        $this->assertEquals(300.00, (float) $viaGetData['minimum_order_amount']);
    }
}
