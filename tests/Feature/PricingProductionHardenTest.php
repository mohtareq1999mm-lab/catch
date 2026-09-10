<?php

namespace Tests\Feature;

use App\Services\Coupon\CouponCalculator;
use App\Services\Coupon\CouponOrchestrator;
use App\Services\General\OrderService;
use App\Services\General\PromotionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\FlashSale;
use Marvel\Enums\DiscountType;
use Marvel\Enums\FlashSaleType;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\ShippingMethod;
use Marvel\Services\Pricing\ProductPricingService;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class PricingProductionHardenTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1/general';

    private Product $product;
    private Product $discountedProduct;
    private Governorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAllTestTables();
        $this->seedBaseData();
    }

    private function seedBaseData(): void
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'Pricing Tester',
            'email' => 'pricing-test@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $country = Country::create(['name' => 'Egypt', 'status' => true]);
        $this->governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Cairo',
            'status' => true,
        ]);
        ShippingPrice::create([
            'governorate_id' => $this->governorate->id,
            'price' => 30.00,
            'free_shipping_over' => 500.00,
            'status' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Standard Product',
            'slug' => 'standard-' . Str::random(6),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        $this->discountedProduct = Product::create([
            'name' => 'Discounted Product',
            'slug' => 'discounted-' . Str::random(6),
            'price' => 200.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 30,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
            'discount_status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
    }

    private function createCartWithItems(int $quantity = 2, ?Product $product = null): Cart
    {
        $product = $product ?? $this->product;
        $product->increment('reserved_quantity', $quantity);

        $cart = Cart::create([
            'user_id' => auth()->id(),
            'status' => 'active',
            'total_price' => $product->price * $quantity,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price,
            'total_price' => $product->price * $quantity,
            'reserved_quantity' => $quantity,
            'shipping_method' => 'SCHEDULED',
        ]);

        return $cart->fresh();
    }

    private function createVariant(Product $product, float $price = 80.00): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'title' => 'Variant',
            'price' => $price,
            'stock_quantity' => 10,
        ]);
    }

    // ===================== Base Pricing =====================

    /** @test */
    public function product_returns_correct_base_price()
    {
        $pricing = app(ProductPricingService::class);
        $result = $pricing->calculateProductPricing($this->product);

        $this->assertEquals(100.00, $result['base_price']);
        $this->assertNull($result['price_after_discount']);
        $this->assertNull($result['price_after_flash_sale']);
        $this->assertEquals(100.00, $result['final_price']);
    }

    /** @test */
    public function variant_returns_correct_price()
    {
        $pricing = app(ProductPricingService::class);
        $variant = $this->createVariant($this->product, 80.00);
        $price = $pricing->calculateVariantSalePrice($this->product, $variant);

        $this->assertEquals(80.00, $price);
    }

    // ===================== Discount =====================

    /** @test */
    public function percentage_discount_calculation()
    {
        $pricing = app(ProductPricingService::class);
        $result = $pricing->calculateDiscountedPrice(200.00, 'percentage', 20);

        $this->assertEquals(160.00, $result);
    }

    /** @test */
    public function fixed_discount_calculation()
    {
        $pricing = app(ProductPricingService::class);
        $result = $pricing->calculateDiscountedPrice(100.00, 'fixed', 25.00);

        $this->assertEquals(75.00, $result);
    }

    /** @test */
    public function expired_discount_not_applied()
    {
        $product = Product::create([
            'name' => 'Expired Discount',
            'slug' => 'expired-discount-' . Str::random(6),
            'price' => 150.00,
            'status' => true,
            'in_stock' => true,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 50,
            'end_date' => now()->subDay(),
        ]);

        $pricing = app(ProductPricingService::class);
        $result = $pricing->calculateProductPricing($product);

        $this->assertNull($result['price_after_discount']);
        $this->assertEquals(150.00, $result['final_price']);
    }

    /** @test */
    public function discount_capped_at_zero()
    {
        $pricing = app(ProductPricingService::class);
        $price = $pricing->calculateDiscountedPrice(50.00, 'fixed', 100.00);

        $this->assertEquals(0, $price);
    }

    /** @test */
    public function percentage_discount_capped_at_100()
    {
        $pricing = app(ProductPricingService::class);
        $price = $pricing->calculateDiscountedPrice(100.00, 'percentage', 150);

        $this->assertEquals(0, $price);
    }

    // ===================== Flash Sale =====================

    /** @test */
    public function flash_sale_price_takes_priority_over_discount()
    {
        $pricing = app(ProductPricingService::class);
        $result = $pricing->calculateProductPricing($this->discountedProduct);

        $this->assertNull($result['price_after_flash_sale']);
        $this->assertNotNull($result['price_after_discount']);
        $this->assertEquals(160.00, $result['final_price']);
    }

    // ===================== Promotion =====================

    /** @test */
    public function percentage_promotion_applied_correctly()
    {
        $promotion = Promotion::create([
            'name' => 'Test 10%',
            'slug' => 'test10-' . Str::random(4),
            'type' => 'percentage',
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'status' => true,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
            'apply_to' => 'all',
        ]);

        $promotion->products()->attach($this->product->id);

        $cart = $this->createCartWithItems(1);

        $promotionService = app(PromotionService::class);
        $service = app(OrderService::class);
        $totals = $service->calculateCheckoutTotals($cart, (int) $promotion->id, null, ShippingMethod::SCHEDULED);

        $this->assertGreaterThan(0, $totals->promotionDiscount);
        $this->assertLessThan($totals->subtotal, $totals->finalTotal);
    }

    /** @test */
    public function promotion_applied_before_coupon()
    {
        $promotion = Promotion::create([
            'name' => 'Promo First',
            'slug' => 'promo-first-' . Str::random(4),
            'type' => 'percentage',
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'status' => true,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
            'apply_to' => 'all',
        ]);
        $promotion->products()->attach($this->product->id);

        $coupon = Coupon::create([
            'name' => 'Coupon Second',
            'slug' => 'coupon-second-' . Str::random(4),
            'code' => 'SECOND' . Str::random(4),
            'discount_type' => 'fixed_rate',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $cart = $this->createCartWithItems(2);
        $cart->update(['coupon' => $coupon->code]);

        $service = app(OrderService::class);
        $totals = $service->calculateCheckoutTotals($cart, (int) $promotion->id, null, ShippingMethod::SCHEDULED);

        $this->assertGreaterThan(0, $totals->promotionDiscount);
        $this->assertEquals($coupon->code, $totals->coupon);
    }

    /** @test */
    public function invalid_promotion_rejected()
    {
        $cart = $this->createCartWithItems(1);

        $service = app(OrderService::class);
        $this->expectException(\InvalidArgumentException::class);
        $service->calculateCheckoutTotals($cart, 99999, null, ShippingMethod::SCHEDULED);
    }

    /** @test */
    public function expired_promotion_rejected()
    {
        $promotion = Promotion::create([
            'name' => 'Expired',
            'slug' => 'expired-promo-' . Str::random(4),
            'type' => 'percentage',
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'status' => true,
            'start_at' => now()->subMonths(2),
            'end_at' => now()->subMonth(),
            'apply_to' => 'all',
        ]);
        $promotion->products()->attach($this->product->id);

        $cart = $this->createCartWithItems(1);

        $service = app(OrderService::class);
        $this->expectException(\InvalidArgumentException::class);
        $service->calculateCheckoutTotals($cart, (int) $promotion->id, null, ShippingMethod::SCHEDULED);
    }

    // ===================== Coupon =====================

    /** @test */
    public function percentage_coupon_calculated_correctly()
    {
        $coupon = Coupon::create([
            'name' => '10% Off',
            'slug' => '10percent-' . Str::random(4),
            'code' => 'PCT10' . Str::random(2),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $result = CouponCalculator::calculate($coupon, 200.00);

        $this->assertEquals(20.00, $result['discountAmount']);
        $this->assertEquals(180.00, $result['finalPrice']);
        $this->assertFalse($result['freeShipping']);
    }

    /** @test */
    public function fixed_coupon_calculated_correctly()
    {
        $coupon = Coupon::create([
            'name' => '50 Off',
            'slug' => '50off-' . Str::random(4),
            'code' => 'FIX50' . Str::random(2),
            'discount_type' => 'fixed_rate',
            'discount' => 50,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $result = CouponCalculator::calculate($coupon, 200.00);

        $this->assertEquals(50.00, $result['discountAmount']);
        $this->assertEquals(150.00, $result['finalPrice']);
    }

    /** @test */
    public function coupon_with_max_discount_amount_capped()
    {
        $coupon = Coupon::create([
            'name' => 'Capped 10% max 5',
            'slug' => 'capped-' . Str::random(4),
            'code' => 'CAP' . Str::random(3),
            'discount_type' => 'percentage',
            'discount' => 10,
            'max_discount_amount' => 5,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $result = CouponCalculator::calculate($coupon, 200.00);

        $this->assertEquals(5.00, $result['discountAmount']);
        $this->assertEquals(195.00, $result['finalPrice']);
    }

    /** @test */
    public function free_shipping_coupon_has_no_monetary_discount()
    {
        $coupon = Coupon::create([
            'name' => 'Free Ship',
            'slug' => 'freeship-' . Str::random(4),
            'code' => 'SHIPFREE',
            'discount_type' => 'free_shipping',
            'discount' => 0,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $result = CouponCalculator::calculate($coupon, 200.00);

        $this->assertEquals(0, $result['discountAmount']);
        $this->assertEquals(200.00, $result['finalPrice']);
        $this->assertTrue($result['freeShipping']);
    }

    /** @test */
    public function expired_coupon_is_rejected_by_orchestrator()
    {
        $coupon = Coupon::create([
            'name' => 'Expired',
            'slug' => 'expired-cpn-' . Str::random(4),
            'code' => 'EXPIRED',
            'discount_type' => 'percentage',
            'discount' => 50,
            'status' => true,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $user = auth()->user();
        $result = CouponOrchestrator::validate($coupon, $user);

        $this->assertFalse($result['valid']);
    }

    /** @test */
    public function duplicate_coupon_usage_blocked()
    {
        $coupon = Coupon::create([
            'name' => 'Single Use',
            'slug' => 'single-use-' . Str::random(4),
            'code' => 'SINGLE',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'limiter' => 1,
        ]);

        $user = auth()->user();

        $result1 = CouponOrchestrator::validate($coupon, $user);
        $this->assertTrue($result1['valid']);

        $coupon->increment('used');

        $result2 = CouponOrchestrator::validate($coupon, $user);
        $this->assertFalse($result2['valid']);
    }

    // ===================== Checkout =====================

    /** @test */
    public function checkout_stores_price_snapshot()
    {
        $this->createCartWithItems(2);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $order = Order::where('user_id', auth()->id())->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals(200.00, (float) $order->price);
        $this->assertEquals(230.00, (float) $order->total_price);
        $this->assertEquals(30.00, (float) $order->shipping_price);
    }

    /** @test */
    public function changing_product_price_after_order_does_not_change_order()
    {
        $this->createCartWithItems(2);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', auth()->id())->latest()->first();
        $orderPrice = (float) $order->total_price;

        $this->product->update(['price' => 500.00]);

        $order->refresh();
        $this->assertEquals($orderPrice, (float) $order->total_price);
    }

    /** @test */
    public function client_cannot_override_total()
    {
        $this->createCartWithItems(1);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', auth()->id())->latest()->first();
        $this->assertEquals(130.00, (float) $order->total_price);
    }

    // ===================== Security =====================

    /** @test */
    public function negative_discount_rejected()
    {
        $coupon = Coupon::create([
            'name' => 'Negative',
            'slug' => 'negative-' . Str::random(4),
            'code' => 'NEG',
            'discount_type' => 'fixed_rate',
            'discount' => -50,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $result = CouponCalculator::calculate($coupon, 100.00);

        $this->assertEquals(0, $result['discountAmount']);
        $this->assertEquals(100.00, $result['finalPrice']);
    }

    /** @test */
    public function huge_quantity_does_not_overflow()
    {
        $product = Product::create([
            'name' => 'Bulk Product',
            'slug' => 'bulk-' . Str::random(6),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 9999,
        ]);
        $product->increment('reserved_quantity', 999);

        $cart = Cart::create([
            'user_id' => auth()->id(),
            'status' => 'active',
            'total_price' => $product->price * 999,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 999,
            'price' => $product->price,
            'total_price' => $product->price * 999,
            'reserved_quantity' => 999,
            'shipping_method' => 'SCHEDULED',
        ]);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $order = Order::where('user_id', auth()->id())->latest()->first();

        $freeShippingThreshold = (float) ShippingPrice::where('governorate_id', $this->governorate->id)->value('free_shipping_over');
        $subtotal = 100.00 * 999;
        $shipping = $subtotal >= $freeShippingThreshold ? 0.00 : 30.00;
        $expectedTotal = $subtotal + $shipping;

        $this->assertEquals($expectedTotal, (float) $order->total_price);
    }

    /** @test */
    public function zero_price_product_does_not_break_checkout()
    {
        $freeProduct = Product::create([
            'name' => 'Free',
            'slug' => 'free-' . Str::random(6),
            'price' => 0,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
        ]);

        $freeProduct->increment('reserved_quantity', 1);

        $cart = Cart::create([
            'user_id' => auth()->id(),
            'status' => 'active',
            'total_price' => 0,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $freeProduct->id,
            'quantity' => 1,
            'price' => 0,
            'total_price' => 0,
            'reserved_quantity' => 1,
            'shipping_method' => 'SCHEDULED',
        ]);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', auth()->id())->latest()->first();
        $this->assertEquals(0, (float) $order->price);
        $this->assertEquals(30.00, (float) $order->total_price);
    }

    // ===================== Price Cast Type Consistency =====================

    /** @test */
    public function price_fields_are_float_type()
    {
        $this->createCartWithItems(1);
        $cartItem = CartItem::first();
        $this->assertIsFloat($cartItem->price);
        $this->assertIsFloat($cartItem->total_price);
        $this->assertIsFloat($cartItem->discount_amount);

        $this->assertIsFloat($this->product->price);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $order = Order::where('user_id', auth()->id())->latest()->first();
        $this->assertIsFloat($order->price);
        $this->assertIsFloat($order->shipping_price);
        $this->assertIsFloat($order->total_price);

        $orderItem = $order->orderItems()->first();
        $this->assertIsFloat($orderItem->product_price);
        $this->assertIsFloat($orderItem->product_total_price);
    }

    // ===================== Expired Coupon Rejected at Checkout =====================

    /** @test */
    public function expired_coupon_in_cart_is_removed_at_checkout()
    {
        $coupon = Coupon::create([
            'name' => 'Expired at Checkout',
            'slug' => 'exp-checkout-' . Str::random(4),
            'code' => 'EXPCHECKOUT',
            'discount_type' => 'percentage',
            'discount' => 50,
            'status' => true,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $this->createCartWithItems(1);
        $cart = Cart::where('user_id', auth()->id())->first();
        $cart->update(['coupon' => $coupon->code]);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', auth()->id())->latest()->first();
        $this->assertEquals(130.00, (float) $order->total_price);
        $this->assertNull($order->coupon);

        $cart->refresh();
        $this->assertNull($cart->coupon);
    }

    /** @test */
    public function disabled_coupon_in_cart_is_removed_at_checkout()
    {
        $coupon = Coupon::create([
            'name' => 'Disabled',
            'slug' => 'disabled-cpn-' . Str::random(4),
            'code' => 'DISABLED',
            'discount_type' => 'percentage',
            'discount' => 50,
            'status' => false,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $this->createCartWithItems(1);
        $cart = Cart::where('user_id', auth()->id())->first();
        $cart->update(['coupon' => $coupon->code]);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', auth()->id())->latest()->first();
        $this->assertEquals(130.00, (float) $order->total_price);
        $cart->refresh();
        $this->assertNull($cart->coupon);
    }

    // ===================== Variant Pricing with Discount =====================

    /** @test */
    public function variant_pricing_applies_parent_product_discount()
    {
        $variant = $this->createVariant($this->product, 80.00);
        $this->product->update([
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 25,
            'discount_status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $pricing = app(ProductPricingService::class);
        $price = $pricing->calculateVariantSalePrice($this->product, $variant);

        $this->assertEquals(60.00, $price);
    }

    /** @test */
    public function variant_with_flash_sale_suppresses_discount()
    {
        if (!Schema::hasColumn('flash_sales', 'type')) {
            Schema::table('flash_sales', function (Blueprint $table) {
                $table->string('type', 50)->default('percentage')->after('description');
                $table->decimal('discount', 10, 2)->default(0)->after('type');
                $table->decimal('max_discount_amount', 10, 2)->nullable()->after('discount');
                $table->integer('order')->default(0)->after('max_discount_amount');
            });
        }

        $variant = $this->createVariant($this->product, 100.00);
        $this->product->update([
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 50,
            'discount_status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $flashSale = new FlashSale();
        $flashSale->forceFill([
            'title' => 'Test Flash',
            'slug' => 'test-flash-' . Str::random(4),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10,
            'status' => true,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);
        $flashSale->save();
        $this->product->flash_sales()->attach($flashSale->id);

        $pricing = app(ProductPricingService::class);
        $price = $pricing->calculateVariantSalePrice($this->product, $variant, $flashSale);

        $this->assertEquals(90.00, $price);
    }

    // ===================== Order Item Snapshot =====================

    /** @test */
    public function order_item_snapshot_suppresses_discount_price_when_flash_sale_active()
    {
        if (!Schema::hasColumn('flash_sales', 'type')) {
            Schema::table('flash_sales', function (Blueprint $table) {
                $table->string('type', 50)->default('percentage')->after('description');
                $table->decimal('discount', 10, 2)->default(0)->after('type');
                $table->decimal('max_discount_amount', 10, 2)->nullable()->after('discount');
                $table->integer('order')->default(0)->after('max_discount_amount');
            });
        }

        $product = Product::create([
            'name' => 'Flash Discount Test',
            'slug' => 'flash-discount-' . Str::random(6),
            'price' => 200.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 50,
            'discount_status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $flashSale = new FlashSale();
        $flashSale->forceFill([
            'title' => 'Order Snap FS',
            'slug' => 'order-snap-fs-' . Str::random(4),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10,
            'status' => true,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);
        $flashSale->save();
        $product->flash_sales()->attach($flashSale->id);
        $product->increment('reserved_quantity', 1);

        $cart = Cart::create(['user_id' => auth()->id(), 'status' => 'active', 'total_price' => 180.00]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 180.00,
            'total_price' => 180.00,
            'reserved_quantity' => 1,
            'shipping_method' => 'SCHEDULED',
        ]);

        $service = app(OrderService::class);
        $totals = $service->calculateCheckoutTotals($cart, null, null, ShippingMethod::SCHEDULED);
        $orderData = [
            'user_id' => auth()->id(),
            'name' => 'Test',
            'user_phone' => '123',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
        ];

        $orderCreationService = app(\App\Services\Checkout\OrderCreationService::class);
        $order = $orderCreationService->createOrder($orderData, $cart, $totals, ShippingMethod::SCHEDULED);
        $this->assertNotNull($order);

        $orderCreationService->createOrderItems($order, $cart);
        $orderItem = $order->orderItems()->first();

        $this->assertNotNull($orderItem->product_flash_sale_price);
        $this->assertEquals(180.00, (float) $orderItem->product_price);
        $this->assertNull($orderItem->product_discount_price);
    }

    // ===================== Concurrency =====================

    /** @test */
    public function concurrent_coupon_usage_blocked_by_limiter()
    {
        $coupon = Coupon::create([
            'name' => 'Concurrency Test',
            'slug' => 'concurrency-cpn-' . Str::random(4),
            'code' => 'CONCUR',
            'discount_type' => 'fixed_rate',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'limiter' => 1,
        ]);

        $this->createCartWithItems(1);
        $cart = Cart::where('user_id', auth()->id())->first();
        $cart->update(['coupon' => $coupon->code]);

        $service = app(OrderService::class);
        $totals = $service->calculateCheckoutTotals($cart, null, null, ShippingMethod::SCHEDULED);

        $this->assertNotNull($totals->coupon);
        $this->assertLessThan($totals->subtotal, $totals->finalTotal);

        $coupon->increment('used');

        $validation = CouponOrchestrator::validate($coupon, auth()->user());
        $this->assertFalse($validation['valid']);
    }

    /** @test */
    public function concurrent_coupon_usage_for_different_users()
    {
        $coupon = Coupon::create([
            'name' => 'Multi User',
            'slug' => 'multi-user-cpn-' . Str::random(4),
            'code' => 'MULTIUSER',
            'discount_type' => 'fixed_rate',
            'discount' => 20,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'limiter' => 10,
        ]);

        $user2 = \Marvel\Database\Models\User::create([
            'name' => 'User Two',
            'email' => 'user2@example.com',
            'password' => bcrypt('password'),
        ]);

        $result1 = CouponOrchestrator::validate($coupon, auth()->user());
        $this->assertTrue($result1['valid']);

        $result2 = CouponOrchestrator::validate($coupon, $user2);
        $this->assertTrue($result2['valid']);
    }

    /** @test */
    public function product_stock_reservation_prevents_overselling()
    {
        $limitedProduct = Product::create([
            'name' => 'Limited Stock',
            'slug' => 'limited-stock-' . Str::random(6),
            'price' => 50.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 2,
        ]);

        $limitedProduct->increment('reserved_quantity', 1);

        $cart = Cart::create(['user_id' => auth()->id(), 'status' => 'active', 'total_price' => 50]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $limitedProduct->id,
            'quantity' => 1,
            'price' => 50,
            'total_price' => 50,
            'reserved_quantity' => 1,
            'shipping_method' => 'SCHEDULED',
        ]);

        $limitedProduct->increment('reserved_quantity', 1);

        $cart2 = Cart::create(['user_id' => auth()->id(), 'status' => 'active', 'total_price' => 50]);
        CartItem::create([
            'cart_id' => $cart2->id,
            'product_id' => $limitedProduct->id,
            'quantity' => 1,
            'price' => 50,
            'total_price' => 50,
            'reserved_quantity' => 1,
            'shipping_method' => 'SCHEDULED',
        ]);

        $this->expectException(\Exception::class);
        app(\App\Services\General\CartInventoryService::class)->incrementItem(
            $cart2, $limitedProduct, null, 1, [], 'SCHEDULED'
        );
    }

    // ===================== Flash Sale Date Boundaries =====================

    /** @test */
    public function flash_sale_starting_tomorrow_is_not_active_today()
    {
        if (!Schema::hasColumn('flash_sales', 'type')) {
            Schema::table('flash_sales', function (Blueprint $table) {
                $table->string('type', 50)->default('percentage')->after('description');
                $table->decimal('discount', 10, 2)->default(0)->after('type');
                $table->decimal('max_discount_amount', 10, 2)->nullable()->after('discount');
                $table->integer('order')->default(0)->after('max_discount_amount');
            });
        }

        $product = Product::create([
            'name' => 'Flash Start Tomorrow',
            'slug' => 'flash-tomorrow-' . Str::random(6),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        $flashSale = new FlashSale();
        $flashSale->forceFill([
            'title' => 'Tomorrow Flash',
            'slug' => 'tomorrow-flash-' . Str::random(4),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 50,
            'status' => true,
            'start_date' => Carbon::tomorrow(),
            'end_date' => Carbon::tomorrow()->addDays(3),
        ]);
        $flashSale->save();
        $product->flash_sales()->attach($flashSale->id);

        $pricing = app(ProductPricingService::class);
        $result = $pricing->calculateProductPricing($product);

        $this->assertNull($result['price_after_flash_sale'], 'Flash sale starting tomorrow should not be active today');
        $this->assertEquals(100.00, $result['final_price']);
    }

    /** @test */
    public function flash_sale_that_ended_yesterday_is_not_active_today()
    {
        if (!Schema::hasColumn('flash_sales', 'type')) {
            Schema::table('flash_sales', function (Blueprint $table) {
                $table->string('type', 50)->default('percentage')->after('description');
                $table->decimal('discount', 10, 2)->default(0)->after('type');
                $table->decimal('max_discount_amount', 10, 2)->nullable()->after('discount');
                $table->integer('order')->default(0)->after('max_discount_amount');
            });
        }

        $product = Product::create([
            'name' => 'Flash Ended Yesterday',
            'slug' => 'flash-ended-' . Str::random(6),
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        $flashSale = new FlashSale();
        $flashSale->forceFill([
            'title' => 'Expired Flash',
            'slug' => 'expired-flash-' . Str::random(4),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 50,
            'status' => true,
            'start_date' => Carbon::yesterday()->subDays(3),
            'end_date' => Carbon::yesterday(),
        ]);
        $flashSale->save();
        $product->flash_sales()->attach($flashSale->id);

        $pricing = app(ProductPricingService::class);
        $result = $pricing->calculateProductPricing($product);

        $this->assertNull($result['price_after_flash_sale'], 'Flash sale that ended yesterday should not be active today');
        $this->assertEquals(100.00, $result['final_price']);
    }

    // ===================== Coupon + Promotion Stacking =====================

    /** @test */
    public function coupon_applied_on_top_of_promotion_reduces_final_total()
    {
        $promotion = Promotion::create([
            'name' => 'Stacking Promo',
            'slug' => 'stacking-promo-' . Str::random(4),
            'type' => 'percentage',
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'status' => true,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
            'apply_to' => 'all',
        ]);
        $promotion->products()->attach($this->product->id);

        $coupon = Coupon::create([
            'name' => 'Stacking Coupon',
            'slug' => 'stacking-cpn-' . Str::random(4),
            'code' => 'STACK',
            'discount_type' => 'fixed_rate',
            'discount' => 5,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $cart = $this->createCartWithItems(1);
        $cart->update(['coupon' => $coupon->code]);

        $service = app(OrderService::class);
        $totals = $service->calculateCheckoutTotals($cart, (int) $promotion->id, null, ShippingMethod::SCHEDULED);

        $this->assertGreaterThan(0, $totals->promotionDiscount);
        $this->assertGreaterThan(0, $totals->couponDiscount);
        $this->assertEqualsWithDelta(85.00, $totals->finalTotal, 0.01);
    }
}
